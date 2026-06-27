<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Database;

final class DeviceTokenRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
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
                role = VALUES(role),
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
        $sql = 'SELECT fcm_token FROM push_device_tokens WHERE auth_uid = ?';
        $params = [$authUid];
        if ($role !== null) {
            $sql .= ' AND role = ?';
            $params[] = $role;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_values(array_filter(array_map(
            static fn ($row) => (string) ($row['fcm_token'] ?? ''),
            $stmt->fetchAll() ?: [],
        )));
    }

    /** @return list<string> */
    public function tokensForPhone(string $phoneE164, ?string $role = null): array
    {
        $sql = 'SELECT fcm_token FROM push_device_tokens WHERE phone_e164 = ?';
        $params = [$phoneE164];
        if ($role !== null) {
            $sql .= ' AND role = ?';
            $params[] = $role;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_values(array_filter(array_map(
            static fn ($row) => (string) ($row['fcm_token'] ?? ''),
            $stmt->fetchAll() ?: [],
        )));
    }
}
