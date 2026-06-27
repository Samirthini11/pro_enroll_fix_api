<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Config;
use ProEnroll\Api\Database;

/**
 * Generates 6-digit OTPs, stores them in MySQL.
 * Production SMS OTP uses Firebase Phone Auth in the Flutter app; this service
 * remains for legacy mail/debug flows when OTP_DELIVERY=mail.
 */
final class OtpService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * @param array{ip?: ?string, user_agent?: ?string} $meta
     * @return array{request_id: string, expires_in: int, debug_otp?: string}
     */
    public function send(string $phoneE164, string $purpose = 'sign_up', array $meta = []): array
    {
        $phone = $this->normalizePhone($phoneE164);
        $purpose = $purpose === 'sign_in' ? 'sign_in' : 'sign_up';
        // $otp = (string) random_int(100000, 999999);
        $otp = '123456';
        $requestId = bin2hex(random_bytes(16));
        $ttl = (int) Config::get('OTP_EXPIRY_SECONDS', '600');

        $stmt = $this->db->prepare(
            'INSERT INTO otp_requests
             (request_id, phone_e164, purpose, otp_code, expires_at, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?, NOW())'
        );
        $stmt->execute([
            $requestId,
            $phone,
            $purpose,
            $otp,
            $ttl,
            $meta['ip'] ?? null,
            $meta['user_agent'] ?? null,
        ]);

        $delivery = strtolower((string) Config::get('OTP_DELIVERY', 'mail'));
        if ($delivery === 'mail' || Config::bool('APP_DEBUG', false)) {
            $this->deliverOtpMail($phone, $otp, $ttl);
        }

        $result = [
            'request_id' => $requestId,
            'expires_in' => $ttl,
            'purpose' => $purpose,
        ];

        if (Config::bool('OTP_DEBUG_RETURN', false) || Config::bool('APP_DEBUG', false)) {
            $result['debug_otp'] = $otp;
        }

        return $result;
    }

    /**
     * @return array{phone_e164: string, purpose: string}|null
     */
    public function verify(string $requestId, string $otp): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT phone_e164, purpose, otp_code, expires_at, verified_at, attempt_count
             FROM otp_requests WHERE request_id = ? LIMIT 1'
        );
        $stmt->execute([$requestId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        if ($row['verified_at'] !== null) {
            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        $this->db->prepare(
            'UPDATE otp_requests SET attempt_count = attempt_count + 1 WHERE request_id = ?'
        )->execute([$requestId]);

        if (!hash_equals((string) $row['otp_code'], trim($otp))) {
            return null;
        }

        $this->db->prepare(
            'UPDATE otp_requests SET verified_at = NOW() WHERE request_id = ?'
        )->execute([$requestId]);

        return [
            'phone_e164' => (string) $row['phone_e164'],
            'purpose' => (string) $row['purpose'],
        ];
    }

    private function deliverOtpMail(string $phoneE164, string $otp, int $ttlSeconds): void
    {
        $to = Config::get('OTP_MAIL_TO');
        if ($to === null || $to === '') {
            $to = Config::get('MAIL_TO', 'dev@localhost');
        }

        $from = Config::get('OTP_FROM_EMAIL', 'noreply@proenroll.local');
        $app = Config::get('APP_NAME', 'Pro-Enroll');
        $minutes = (int) ceil($ttlSeconds / 60);

        $subject = "[$app] OTP for $phoneE164";
        $body = "Your Pro-Enroll verification code is: $otp\n\n"
            . "Phone: $phoneE164\n"
            . "Valid for about $minutes minute(s).\n"
            . "If you did not request this, ignore this email.\n";

        $headers = implode("\r\n", [
            "From: $app <$from>",
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: Pro-Enroll-API',
        ]);

        @mail($to, $subject, $body, $headers);
    }

    private function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '' || !preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
            throw new \InvalidArgumentException('Invalid phone_e164 (use E.164, e.g. +919876543210)');
        }

        return $phone;
    }
}
