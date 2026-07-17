<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Database;

final class DeviceTokenRepository
{
    private PDO $db;

    private static bool $tableReady = false;

    public function __construct()
    {
        $this->db = Database::connection();
        $this->ensureTable();
    }

    public function upsert(
        string $authUid,
        string $phoneE164,
        string $fcmToken,
        string $platform,
        string $role,
        ?string $deviceLabel = null,
    ): void {
        $phoneE164 = self::normalizePhoneE164($phoneE164);
        $stmt = $this->db->prepare(
            'INSERT INTO push_device_tokens
                (auth_uid, phone_e164, fcm_token, platform, role, device_label, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                auth_uid = VALUES(auth_uid),
                phone_e164 = VALUES(phone_e164),
                platform = VALUES(platform),
                device_label = VALUES(device_label),
                updated_at = NOW()'
        );
        $stmt->execute([
            $authUid,
            $phoneE164,
            $fcmToken,
            $platform,
            $role,
            $deviceLabel,
        ]);
    }

    /** @return list<string> */
    public function tokensForAuthUid(string $authUid, ?string $role = null): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $sql = 'SELECT fcm_token FROM push_device_tokens WHERE auth_uid = ?';
        $params = [$authUid];
        if ($role !== null) {
            $sql .= ' AND role = ?';
            $params[] = $role;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $this->extractTokens($stmt->fetchAll() ?: []);
    }

    /** @return list<string> */
    public function tokensForPhone(string $phoneE164, ?string $role = null): array
    {
        $variants = self::phoneVariants($phoneE164);
        if ($variants === [] || !$this->tableExists()) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($variants), '?'));
        $sql = "SELECT fcm_token FROM push_device_tokens WHERE phone_e164 IN ({$placeholders})";
        $params = $variants;
        if ($role !== null) {
            $sql .= ' AND role = ?';
            $params[] = $role;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $this->extractTokens($stmt->fetchAll() ?: []);
    }

    /** @return list<string> */
    public function tokensForCustomerId(int $customerId): array
    {
        if ($customerId < 1 || !$this->tableExists()) {
            return [];
        }

        $customer = (new CustomerRepository())->findById($customerId);
        if ($customer === null) {
            return [];
        }

        $authUid = (string) ($customer['auth_uid'] ?? '');
        $phone = (string) ($customer['phone_e164'] ?? '');

        return array_values(array_unique(array_merge(
            $authUid !== '' ? $this->tokensForAuthUid($authUid, 'customer') : [],
            $phone !== '' ? $this->tokensForPhone($phone, 'customer') : [],
            $phone !== '' ? $this->tokensForPhone($phone, 'professional') : [],
        )));
    }

    public function deleteByToken(string $fcmToken): void
    {
        if (!$this->tableExists() || trim($fcmToken) === '') {
            return;
        }

        $stmt = $this->db->prepare('DELETE FROM push_device_tokens WHERE fcm_token = ?');
        $stmt->execute([$fcmToken]);
    }

    /** @return list<array{role: string, phone_e164: string, fcm_token: string}> */
    public function listRecentTokens(int $limit = 10): array
    {
        if (!$this->tableExists() || $limit < 1) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT role, phone_e164, fcm_token
             FROM push_device_tokens
             ORDER BY updated_at DESC
             LIMIT ' . (int) $limit
        );
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'role' => (string) ($row['role'] ?? ''),
                'phone_e164' => (string) ($row['phone_e164'] ?? ''),
                'fcm_token' => (string) ($row['fcm_token'] ?? ''),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public static function phoneVariants(string $phoneE164): array
    {
        $phone = preg_replace('/[^\d+]/', '', trim($phoneE164)) ?? '';
        if ($phone === '') {
            return [];
        }

        $variants = [$phone];
        $digits = ltrim($phone, '+');

        // Bare 10-digit Indian mobile → +91 / 91 / 0…
        if (preg_match('/^[6-9]\d{9}$/', $digits) === 1) {
            $variants[] = '+91' . $digits;
            $variants[] = '91' . $digits;
            $variants[] = '0' . $digits;
        }

        if (str_starts_with($phone, '+91') && strlen($phone) >= 13) {
            $local = substr($phone, 3);
            $variants[] = substr($phone, 1); // 91…
            $variants[] = '0' . $local;
            $variants[] = $local;
        } elseif (str_starts_with($phone, '91') && strlen($phone) >= 12 && !str_starts_with($phone, '+')) {
            $local = substr($phone, 2);
            $variants[] = '+' . $phone;
            $variants[] = '0' . $local;
            $variants[] = $local;
        } elseif (str_starts_with($phone, '0') && strlen($phone) >= 11) {
            $local = substr($phone, 1);
            $variants[] = '+91' . $local;
            $variants[] = '91' . $local;
            $variants[] = $local;
        }

        return array_values(array_unique(array_filter($variants)));
    }

    /** Canonical +91… form when possible (for storage). */
    public static function normalizePhoneE164(string $phoneE164): string
    {
        $variants = self::phoneVariants($phoneE164);
        foreach ($variants as $v) {
            if (str_starts_with($v, '+91') && strlen($v) === 13) {
                return $v;
            }
        }

        return trim($phoneE164);
    }

    private function ensureTable(): void
    {
        if (self::$tableReady) {
            return;
        }

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS push_device_tokens (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                auth_uid VARCHAR(128) NOT NULL,
                phone_e164 VARCHAR(20) NOT NULL,
                fcm_token VARCHAR(512) NOT NULL,
                platform ENUM(\'android\', \'ios\', \'web\') NOT NULL DEFAULT \'android\',
                role ENUM(\'professional\', \'customer\') NOT NULL DEFAULT \'professional\',
                device_label VARCHAR(120) NULL,
                updated_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_fcm_role (fcm_token, role),
                INDEX idx_auth_uid (auth_uid),
                INDEX idx_phone (phone_e164),
                INDEX idx_role (role)
            ) ENGINE=InnoDB'
        );

        $this->migrateLegacyUniqueIndex();
        self::$tableReady = true;
    }

    private function migrateLegacyUniqueIndex(): void
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'push_device_tokens'
               AND INDEX_NAME = 'uq_fcm_token'
             LIMIT 1"
        );
        $stmt->execute();
        if ($stmt->fetchColumn() === false) {
            return;
        }

        try {
            $this->db->exec('ALTER TABLE push_device_tokens DROP INDEX uq_fcm_token');
            $this->db->exec(
                'ALTER TABLE push_device_tokens ADD UNIQUE KEY uq_fcm_role (fcm_token, role)'
            );
        } catch (\Throwable) {
            // Best-effort migration; table may already be on the new index.
        }
    }

    private function tableExists(): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'push_device_tokens'
             LIMIT 1"
        );
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /** @param list<array<string, mixed>> $rows */
    private function extractTokens(array $rows): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($row) => (string) ($row['fcm_token'] ?? ''),
            $rows,
        ))));
    }
}
