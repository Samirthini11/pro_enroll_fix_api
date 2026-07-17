<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Database;

final class CustomerRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function findByAuthUid(string $uid): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM customers WHERE auth_uid = ? LIMIT 1');
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function upsertFromPhone(string $phoneE164, ?string $fullName = null): array
    {
        $existing = $this->findByPhone($phoneE164);
        if ($existing !== null) {
            if ($fullName !== null && $fullName !== '') {
                $this->db->prepare(
                    'UPDATE customers SET full_name = ?, updated_at = NOW() WHERE id = ?'
                )->execute([$fullName, $existing['id']]);
            }
            return $this->findById((int) $existing['id']) ?? $existing;
        }

        $uid = 'cust_' . bin2hex(random_bytes(12));
        $this->db->prepare(
            'INSERT INTO customers (auth_uid, phone_e164, full_name, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$uid, $phoneE164, $fullName]);

        return $this->findByAuthUid($uid) ?? [];
    }

    public function findByPhone(string $phoneE164): ?array
    {
        $variants = DeviceTokenRepository::phoneVariants($phoneE164);
        if ($variants === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($variants), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM customers WHERE phone_e164 IN ({$placeholders}) LIMIT 1"
        );
        $stmt->execute($variants);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array<string, mixed> $fields */
    public function updateProfile(int $id, array $fields): array
    {
        $allowed = ['full_name', 'city_id', 'profile_photo_url'];
        $sets = [];
        $params = [];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $sets[] = "$key = ?";
            $params[] = $value;
        }
        if ($sets === []) {
            return $this->findById($id) ?? [];
        }
        $sets[] = 'updated_at = NOW()';
        $params[] = $id;
        $this->db->prepare(
            'UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = ?'
        )->execute($params);
        return $this->findById($id) ?? [];
    }

    /** @return array<string, mixed> */
    public function profilePayload(string $uid): array
    {
        $c = $this->findByAuthUid($uid);
        if ($c === null) {
            return ['registered' => false];
        }

        $photo = $c['profile_photo_url'] ?? null;

        $payload = [
            'registered' => true,
            'full_name' => $c['full_name'],
            'phone_e164' => $c['phone_e164'],
            'city_id' => $c['city_id'] !== null ? (int) $c['city_id'] : null,
            'profile_photo_url' => is_string($photo) && $photo !== '' ? $photo : null,
            'has_profile_photo' => is_string($photo) && $photo !== '',
        ];
        $payload['profile_complete'] = self::isProfileComplete($payload);

        return $payload;
    }

    /** @param array<string, mixed> $profile */
    public static function isProfileComplete(array $profile): bool
    {
        if (($profile['registered'] ?? false) !== true) {
            return false;
        }

        $name = trim((string) ($profile['full_name'] ?? ''));

        return $name !== '' && ($profile['city_id'] ?? null) !== null;
    }

    /** @param array<string, mixed> $profile */
    public function resolveNextRouteFromProfile(array $profile): string
    {
        return self::isProfileComplete($profile)
            ? '/customer/home'
            : '/customer/profile-setup';
    }
}
