<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use ProEnroll\Api\Config;

/**
 * Minimal AWS S3 PutObject client (Signature Version 4) — no SDK required.
 *
 * Resolves the bucket's real region (fixes "must be addressed using the
 * specified endpoint") and retries once on PermanentRedirect.
 */
final class S3StorageService
{
    private static ?string $resolvedRegion = null;

    public function isConfigured(): bool
    {
        return $this->accessKey() !== ''
            && $this->secretKey() !== ''
            && $this->bucket() !== '';
    }

    /**
     * @return array{key: string, url: string, bucket: string, region: string}
     */
    public function putObject(
        string $key,
        string $binary,
        string $contentType = 'application/octet-stream',
    ): array {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('S3 is not configured (AWS_ACCESS_KEY_ID / AWS_S3_BUCKET)');
        }

        $bucket = $this->bucket();
        $key = ltrim(str_replace('\\', '/', $key), '/');
        $region = $this->resolveBucketRegion($bucket);

        try {
            return $this->putObjectToRegion($bucket, $key, $binary, $contentType, $region);
        } catch (\RuntimeException $e) {
            $redirectRegion = $this->regionFromError($e->getMessage());
            if ($redirectRegion !== null && $redirectRegion !== $region) {
                self::$resolvedRegion = $redirectRegion;

                return $this->putObjectToRegion(
                    $bucket,
                    $key,
                    $binary,
                    $contentType,
                    $redirectRegion,
                );
            }
            throw $e;
        }
    }

    /**
     * @return array{key: string, url: string, bucket: string, region: string}
     */
    public function putKycImage(
        int $professionalId,
        string $kind,
        string $binary,
        string $contentType,
        string $ext,
    ): array {
        $prefix = trim((string) Config::get('AWS_S3_KEY_PREFIX', 'kyc'), '/');
        $safeKind = preg_replace('/[^a-z0-9_\-]/i', '', $kind) ?: 'doc';
        $safeExt = preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'jpg';
        $key = sprintf(
            '%s/%d/%s_%s.%s',
            $prefix !== '' ? $prefix : 'kyc',
            $professionalId,
            $safeKind,
            bin2hex(random_bytes(8)),
            $safeExt,
        );

        return $this->putObject($key, $binary, $contentType);
    }

    /**
     * @return array{key: string, url: string, bucket: string, region: string}
     */
    private function putObjectToRegion(
        string $bucket,
        string $key,
        string $binary,
        string $contentType,
        string $region,
    ): array {
        [$host, $uri, $url] = $this->objectAddress($bucket, $key, $region);
        $response = $this->signedRequest(
            method: 'PUT',
            host: $host,
            uri: $uri,
            region: $region,
            payload: $binary,
            contentType: $contentType,
            extraHeaders: [],
        );

        $code = $response['code'];
        $body = $response['body'];
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException(
                'S3 upload failed (' . $code . '): ' . $this->friendlyError($body)
            );
        }

        return [
            'key' => $key,
            'url' => $url,
            'bucket' => $bucket,
            'region' => $region,
        ];
    }

    /**
     * Presigned GET URL so admin apps can preview private KYC objects.
     * Accepts a full S3 object URL or a raw object key.
     */
    public function presignGetUrl(string $urlOrKey, int $expiresSeconds = 3600): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $key = $this->extractObjectKey($urlOrKey);
        if ($key === null || $key === '') {
            return null;
        }

        $expiresSeconds = max(60, min($expiresSeconds, 604800));
        $bucket = $this->bucket();
        $region = $this->resolveBucketRegion($bucket);
        [$host, $uri] = $this->objectAddress($bucket, $key, $region);

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $credentialScope = "{$dateStamp}/{$region}/s3/aws4_request";
        $credential = $this->accessKey() . '/' . $credentialScope;

        $params = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $credential,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $expiresSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($params);

        $canonicalQueryParts = [];
        foreach ($params as $name => $value) {
            $canonicalQueryParts[] = rawurlencode($name) . '=' . rawurlencode($value);
        }
        $canonicalQuery = implode('&', $canonicalQueryParts);

        $canonicalRequest = implode("\n", [
            'GET',
            $uri,
            $canonicalQuery,
            'host:' . $host,
            '',
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp, $region));

        return 'https://' . $host . $uri . '?' . $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }

    private function extractObjectKey(string $urlOrKey): ?string
    {
        $value = trim($urlOrKey);
        if ($value === '') {
            return null;
        }

        if (!preg_match('#^https?://#i', $value)) {
            return ltrim(str_replace('\\', '/', $value), '/');
        }

        $parts = parse_url($value);
        if ($parts === false || empty($parts['host']) || empty($parts['path'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $path = ltrim((string) $parts['path'], '/');
        $bucket = strtolower($this->bucket());

        // Only rewrite URLs that clearly belong to our bucket.
        $isOurHost = str_contains($host, 'amazonaws.com')
            || str_contains($host, $bucket)
            || trim((string) Config::get('AWS_S3_ENDPOINT', '')) !== '';
        if (!$isOurHost) {
            return null;
        }

        // Path-style: /bucket/key...
        if (str_starts_with(strtolower($path), $bucket . '/')) {
            return rawurldecode(substr($path, strlen($bucket) + 1));
        }

        return rawurldecode($path);
    }

    /**
     * Discover bucket region so we hit the correct regional endpoint.
     */
    private function resolveBucketRegion(string $bucket): string
    {
        $forced = trim((string) Config::get('AWS_S3_REGION', ''));
        if ($forced !== '') {
            return $forced;
        }

        if (self::$resolvedRegion !== null) {
            return self::$resolvedRegion;
        }

        $configured = $this->region();

        // GetBucketLocation must be signed against us-east-1.
        try {
            $host = $this->usePathStyle()
                ? 's3.amazonaws.com'
                : "{$bucket}.s3.amazonaws.com";
            $uri = $this->usePathStyle() ? '/' . rawurlencode($bucket) . '?location' : '/?location';

            $response = $this->signedRequest(
                method: 'GET',
                host: $host,
                uri: $uri,
                region: 'us-east-1',
                payload: '',
                contentType: '',
                extraHeaders: [],
                queryForCanonical: 'location=',
            );

            if ($response['code'] >= 200 && $response['code'] < 300) {
                $region = $this->parseLocationConstraint($response['body']);
                self::$resolvedRegion = $region;

                return $region;
            }

            // PermanentRedirect / AuthorizationHeaderMalformed often include region.
            $fromErr = $this->regionFromError($response['body']);
            if ($fromErr !== null) {
                self::$resolvedRegion = $fromErr;

                return $fromErr;
            }
        } catch (\Throwable) {
            // Fall back to configured region.
        }

        self::$resolvedRegion = $configured;

        return $configured;
    }

    /**
     * @return array{0: string, 1: string, 2: string} host, uri, url
     */
    private function objectAddress(string $bucket, string $key, string $region): array
    {
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
        $customEndpoint = trim((string) Config::get('AWS_S3_ENDPOINT', ''));

        if ($customEndpoint !== '') {
            $customEndpoint = rtrim($customEndpoint, '/');
            $host = preg_replace('#^https?://#i', '', $customEndpoint) ?? $customEndpoint;
            $uri = '/' . rawurlencode($bucket) . '/' . $encodedKey;
            $url = 'https://' . $host . $uri;

            return [$host, $uri, $url];
        }

        if ($this->usePathStyle()) {
            $host = $region === 'us-east-1'
                ? 's3.amazonaws.com'
                : "s3.{$region}.amazonaws.com";
            $uri = '/' . rawurlencode($bucket) . '/' . $encodedKey;
            $url = "https://{$host}{$uri}";

            return [$host, $uri, $url];
        }

        // Virtual-hosted–style (preferred).
        $host = $region === 'us-east-1'
            ? "{$bucket}.s3.amazonaws.com"
            : "{$bucket}.s3.{$region}.amazonaws.com";
        $uri = '/' . $encodedKey;
        $url = "https://{$host}{$uri}";

        return [$host, $uri, $url];
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array{code: int, body: string, headers: string}
     */
    private function signedRequest(
        string $method,
        string $host,
        string $uri,
        string $region,
        string $payload,
        string $contentType,
        array $extraHeaders,
        string $queryForCanonical = '',
    ): array {
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $payload);

        $path = $uri;
        $canonicalQuery = $queryForCanonical;
        if (str_contains($uri, '?')) {
            [$path, $query] = explode('?', $uri, 2);
            // location → location= for canonical query.
            $canonicalQuery = $queryForCanonical !== ''
                ? $queryForCanonical
                : (str_contains($query, '=') ? $query : $query . '=');
        }

        $headers = array_merge([
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ], $extraHeaders);

        if ($contentType !== '') {
            $headers['content-type'] = $contentType;
        }

        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaderNames = [];
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= strtolower($name) . ':' . trim($value) . "\n";
            $signedHeaderNames[] = strtolower($name);
        }
        $signedHeaders = implode(';', $signedHeaderNames);

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $path,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $credentialScope = "{$dateStamp}/{$region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($dateStamp, $region));
        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey()
            . "/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $curlHeaders = ['Authorization: ' . $authorization];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }
        if ($method === 'PUT' || $method === 'POST') {
            $curlHeaders[] = 'Content-Length: ' . (string) strlen($payload);
        }

        $url = 'https://' . $host . $uri;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not init S3 request');
        }

        $headerBlob = '';
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_TIMEOUT => 60,
        ]);
        if ($method === 'PUT' || $method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('S3 request failed: ' . $err);
        }

        $headerBlob = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        return [
            'code' => $code,
            'body' => (string) $body,
            'headers' => (string) $headerBlob,
        ];
    }

    private function parseLocationConstraint(string $xml): string
    {
        if (preg_match(
            '#<LocationConstraint[^>]*>([^<]*)</LocationConstraint>#i',
            $xml,
            $m,
        )) {
            $loc = trim($m[1]);
            // Empty LocationConstraint means classic US East (N. Virginia).
            return $loc !== '' ? $loc : 'us-east-1';
        }

        return 'us-east-1';
    }

    private function regionFromError(string $message): ?string
    {
        if (preg_match('#<Region>([^<]+)</Region>#i', $message, $m)) {
            return trim($m[1]);
        }
        if (preg_match('#<Endpoint>([^<]+)</Endpoint>#i', $message, $m)) {
            $endpoint = trim($m[1]);
            // bucket.s3.ap-south-1.amazonaws.com OR s3.ap-south-1.amazonaws.com
            if (preg_match('#s3[.-]([a-z0-9-]+)\\.amazonaws\\.com#i', $endpoint, $r)) {
                $maybe = strtolower($r[1]);
                if ($maybe !== 'amazonaws' && $maybe !== 'dualstack') {
                    return $maybe;
                }
            }
        }
        if (preg_match(
            '#must be addressed using the specified endpoint[^A-Za-z0-9-]*([a-z]{2}-[a-z]+-\\d)#i',
            $message,
            $m,
        )) {
            return strtolower($m[1]);
        }

        return null;
    }

    private function friendlyError(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return 'empty response';
        }
        if (preg_match('#<Message>([^<]+)</Message>#i', $body, $m)) {
            $msg = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1);
            $region = $this->regionFromError($body);
            if ($region !== null) {
                return $msg . " (use region {$region})";
            }

            return $msg;
        }

        return substr(strip_tags($body), 0, 400);
    }

    private function usePathStyle(): bool
    {
        return Config::bool('AWS_S3_USE_PATH_STYLE', false);
    }

    private function accessKey(): string
    {
        return trim((string) Config::get('AWS_ACCESS_KEY_ID', ''));
    }

    private function secretKey(): string
    {
        return trim((string) Config::get('AWS_SECRET_ACCESS_KEY', ''));
    }

    private function region(): string
    {
        return trim((string) Config::get('AWS_DEFAULT_REGION', 'ap-south-1')) ?: 'ap-south-1';
    }

    private function bucket(): string
    {
        return trim((string) Config::get('AWS_S3_BUCKET', ''));
    }

    private function signingKey(string $dateStamp, string $region): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey(), true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);

        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
