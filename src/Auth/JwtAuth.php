<?php

declare(strict_types=1);

namespace ProEnroll\Api\Auth;

use ProEnroll\Api\Config;

/**
 * Issue and verify HS256 JWT access tokens for API clients.
 */
final class JwtAuth
{
    /**
     * @param array{sub: string, phone?: ?string} $claims
     */
    public static function issue(array $claims, ?int $ttlSeconds = null): string
    {
        $ttl = $ttlSeconds ?? (int) Config::get('JWT_TTL_SECONDS', '604800');
        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + max(60, $ttl),
        ]);

        return self::encode($payload);
    }

    /**
     * @return array{sub: string, phone: ?string, jti: ?string, iat: int, exp: int}
     */
    public static function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Malformed token');
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;
        $expected = self::sign("$headerB64.$payloadB64");
        if (!hash_equals($expected, $sigB64)) {
            throw new \InvalidArgumentException('Invalid token signature');
        }

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!is_array($payload) || !isset($payload['sub'], $payload['exp'])) {
            throw new \InvalidArgumentException('Invalid token payload');
        }

        if ((int) $payload['exp'] < time()) {
            throw new \InvalidArgumentException('Token expired');
        }

        return [
            'sub' => (string) $payload['sub'],
            'phone' => isset($payload['phone']) ? (string) $payload['phone'] : null,
            'email' => isset($payload['email']) ? (string) $payload['email'] : null,
            'role' => isset($payload['role']) ? (string) $payload['role'] : null,
            'admin_role' => isset($payload['admin_role']) ? (string) $payload['admin_role'] : null,
            'jti' => isset($payload['jti']) ? (string) $payload['jti'] : null,
            'iat' => (int) ($payload['iat'] ?? 0),
            'exp' => (int) $payload['exp'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function encode(array $payload): string
    {
        $header = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
        $body = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $sig = self::sign("$header.$body");

        return "$header.$body.$sig";
    }

    private static function sign(string $data): string
    {
        $secret = Config::get('JWT_SECRET');
        if ($secret === null || $secret === '') {
            throw new \RuntimeException('JWT_SECRET is not configured');
        }

        return self::base64UrlEncode(hash_hmac('sha256', $data, $secret, true));
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $pad = 4 - (strlen($data) % 4);
        if ($pad < 4) {
            $data .= str_repeat('=', $pad);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid base64');
        }

        return $decoded;
    }
}
