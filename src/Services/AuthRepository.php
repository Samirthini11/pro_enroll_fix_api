<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Database;

final class AuthRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureAccount(string $phoneE164, string $authUid, int $professionalId): array
    {
        return $this->ensureAccountInternal($phoneE164, $authUid, $professionalId);
    }

    public function ensureCustomerAccount(string $phoneE164, string $authUid): array
    {
        return $this->ensureAccountInternal($phoneE164, $authUid, null);
    }

    private function ensureAccountInternal(
        string $phoneE164,
        string $authUid,
        ?int $professionalId,
    ): array {
        $existing = $this->findByPhone($phoneE164);
        if ($existing !== null) {
            if ($professionalId !== null) {
                // Pro login: link professional record; keep customer-capable account.
                $this->db->prepare(
                    'UPDATE auth_accounts
                     SET professional_id = ?, phone_verified_at = COALESCE(phone_verified_at, NOW()),
                         last_login_at = NOW(), updated_at = NOW()
                     WHERE id = ?'
                )->execute([$professionalId, $existing['id']]);
            } else {
                // Customer login: do not clear professional_id (same phone, dual role).
                $this->db->prepare(
                    'UPDATE auth_accounts
                     SET phone_verified_at = COALESCE(phone_verified_at, NOW()),
                         last_login_at = NOW(), updated_at = NOW()
                     WHERE id = ?'
                )->execute([$existing['id']]);
            }

            return $this->findById((int) $existing['id']) ?? $existing;
        }

        $this->db->prepare(
            'INSERT INTO auth_accounts
             (auth_uid, phone_e164, professional_id, status, phone_verified_at, last_login_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW(), NOW(), NOW())'
        )->execute([$authUid, $phoneE164, $professionalId, 'active']);

        return $this->findByPhone($phoneE164) ?? [];
    }

    public function findByPhone(string $phoneE164): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM auth_accounts WHERE phone_e164 = ? LIMIT 1');
        $stmt->execute([$phoneE164]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByAuthUid(string $authUid): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM auth_accounts WHERE auth_uid = ? AND status = ? LIMIT 1'
        );
        $stmt->execute([$authUid, 'active']);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM auth_accounts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @return array{session_id: string, refresh_token: string, access_expires_at: string, refresh_expires_at: string}
     */
    public function createSession(
        int $authAccountId,
        int $accessTtlSeconds,
        int $refreshTtlSeconds,
        ?string $deviceLabel,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $sessionId = self::uuid();
        $refreshToken = bin2hex(random_bytes(32));
        $refreshHash = hash('sha256', $refreshToken);

        $this->db->prepare(
            'INSERT INTO auth_sessions
             (session_id, auth_account_id, refresh_token_hash, access_expires_at, refresh_expires_at,
              device_label, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), DATE_ADD(NOW(), INTERVAL ? SECOND),
                     ?, ?, ?, NOW())'
        )->execute([
            $sessionId,
            $authAccountId,
            $refreshHash,
            $accessTtlSeconds,
            $refreshTtlSeconds,
            $deviceLabel,
            $ip,
            $userAgent,
        ]);

        return [
            'session_id' => $sessionId,
            'refresh_token' => $refreshToken,
            'access_expires_at' => date('c', time() + $accessTtlSeconds),
            'refresh_expires_at' => date('c', time() + $refreshTtlSeconds),
        ];
    }

    public function findActiveSession(string $sessionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, a.auth_uid, a.phone_e164, a.professional_id, a.status AS account_status
             FROM auth_sessions s
             INNER JOIN auth_accounts a ON a.id = s.auth_account_id
             WHERE s.session_id = ? AND s.revoked_at IS NULL
               AND s.access_expires_at > NOW() AND a.status = ?
             LIMIT 1'
        );
        $stmt->execute([$sessionId, 'active']);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByRefreshToken(string $refreshToken): ?array
    {
        $hash = hash('sha256', $refreshToken);
        $stmt = $this->db->prepare(
            'SELECT s.*, a.auth_uid, a.phone_e164, a.professional_id, a.status AS account_status
             FROM auth_sessions s
             INNER JOIN auth_accounts a ON a.id = s.auth_account_id
             WHERE s.refresh_token_hash = ? AND s.revoked_at IS NULL
               AND s.refresh_expires_at > NOW() AND a.status = ?
             LIMIT 1'
        );
        $stmt->execute([$hash, 'active']);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function revokeSession(string $sessionId): void
    {
        $this->db->prepare(
            'UPDATE auth_sessions SET revoked_at = NOW() WHERE session_id = ? AND revoked_at IS NULL'
        )->execute([$sessionId]);
    }

    public function revokeAllForAccount(int $authAccountId): void
    {
        $this->db->prepare(
            'UPDATE auth_sessions SET revoked_at = NOW()
             WHERE auth_account_id = ? AND revoked_at IS NULL'
        )->execute([$authAccountId]);
    }

    public function logAttempt(
        string $phoneE164,
        string $type,
        bool $success,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $this->db->prepare(
            'INSERT INTO auth_login_attempts (phone_e164, attempt_type, success, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$phoneE164, $type, $success ? 1 : 0, $ip, $userAgent]);
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
