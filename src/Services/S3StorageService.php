<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use ProEnroll\Api\Config;

/**
 * Minimal AWS S3 PutObject client (Signature Version 4) — no SDK required.
 */
final class S3StorageService
{
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
        $region = $this->region();
        $key = ltrim(str_replace('\\', '/', $key), '/');
        $host = "{$bucket}.s3.{$region}.amazonaws.com";
        $uri = '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
        $url = "https://{$host}{$uri}";

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $binary);
        $contentShaHeader = 'x-amz-content-sha256:' . $payloadHash;
        $dateHeader = 'x-amz-date:' . $amzDate;
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
        $canonicalHeaders = "content-type:{$contentType}\n"
            . "host:{$host}\n"
            . "{$contentShaHeader}\n"
            . "{$dateHeader}\n";

        $canonicalRequest = implode("\n", [
            'PUT',
            $uri,
            '',
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

        $signingKey = $this->signingKey($dateStamp, $region);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey()
            . "/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not init S3 upload');
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $binary,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: ' . $contentType,
                'Content-Length: ' . (string) strlen($binary),
                'Host: ' . $host,
                'x-amz-content-sha256: ' . $payloadHash,
                'x-amz-date: ' . $amzDate,
                'Authorization: ' . $authorization,
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300) {
            throw new \RuntimeException(
                'S3 upload failed (' . $code . '): ' . ($err !== '' ? $err : (string) $body)
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
