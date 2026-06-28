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

        $stmt = $this->db->prepare(
            'SELECT pdt.fcm_token
             FROM push_device_tokens pdt
             INNER JOIN customers c
               ON c.auth_uid = pdt.auth_uid OR c.phone_e164 = pdt.phone_e164
             WHERE c.id = ? AND pdt.role = ?'
        );
        $stmt->execute([$customerId, 'customer']);

        return $this->extractTokens($stmt->fetchAll() ?: []);
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
        $phone = trim($phoneE164);
        if ($phone === '') {
            return [];
        }

        $variants = [$phone];

        if (str_starts_with($phone, '+91') && strlen($phone) >= 12) {
            $variants[] = substr($phone, 1);
            $variants[] = '0' . substr($phone, 3);
        } elseif (str_starts_with($phone, '91') && strlen($phone) >= 11) {
            $variants[] = '+' . $phone;
            $variants[] = '0' . substr($phone, 2);
        } elseif (str_starts_with($phone, '0') && strlen($phone) >= 10) {
            $variants[] = '+91' . substr($phone, 1);
            $variants[] = '91' . substr($phone, 1);
        }

        return array_values(array_unique($variants));
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
