<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Database;
use ProEnroll\Api\ReferenceData;

/**
 * Work categories stored in `service_categories` (seeded via migration).
 */
final class CategoryRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /** @return list<array<string, mixed>> */
    public function listActive(): array
    {
        try {
            $stmt = $this->db->query(
                'SELECT code, name_en, name_ta, icon_key, default_visit_fee_paise, base_price_paise, sort_order
                 FROM service_categories
                 WHERE is_active = 1
                 ORDER BY sort_order ASC, name_en ASC'
            );
            $rows = $stmt->fetchAll();
            if (!is_array($rows) || $rows === []) {
                return ReferenceData::staticCategories();
            }

            $out = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = (string) $row['code'];
                $nameTa = (string) ($row['name_ta'] ?? '');
                if (self::isBrokenTamil($nameTa)) {
                    $nameTa = self::staticTamilName($code) ?? $nameTa;
                }
                $out[] = [
                    'code' => $code,
                    'name_en' => (string) $row['name_en'],
                    'name_ta' => $nameTa,
                    'icon_key' => (string) ($row['icon_key'] ?? 'build'),
                    'default_visit_fee_paise' => (int) $row['default_visit_fee_paise'],
                    'base_price_paise' => (int) ($row['base_price_paise'] ?? $row['default_visit_fee_paise']),
                    'sort_order' => (int) ($row['sort_order'] ?? 0),
                ];
            }

            return $out !== [] ? $out : ReferenceData::staticCategories();
        } catch (\Throwable) {
            return ReferenceData::staticCategories();
        }
    }

    /** Corrupted charset often becomes literal "?" or loses Tamil script. */
    private static function isBrokenTamil(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, '?')) {
            return true;
        }

        return !preg_match('/[\x{0B80}-\x{0BFF}]/u', $value);
    }

    private static function staticTamilName(string $code): ?string
    {
        foreach (ReferenceData::staticCategories() as $c) {
            if (($c['code'] ?? '') === $code) {
                $ta = (string) ($c['name_ta'] ?? '');
                return $ta !== '' ? $ta : null;
            }
        }

        return null;
    }

    public function isValidCode(string $code): bool
    {
        if ($code === '') {
            return false;
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM service_categories WHERE code = ? AND is_active = 1 LIMIT 1'
            );
            $stmt->execute([$code]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            foreach (ReferenceData::staticCategories() as $c) {
                if ($c['code'] === $code) {
                    return true;
                }
            }
            return false;
        }
    }

    /** @param list<string> $codes */
    public function validateCodes(array $codes): ?string
    {
        foreach ($codes as $code) {
            if (!$this->isValidCode((string) $code)) {
                return (string) $code;
            }
        }
        return null;
    }

    /** @return list<array<string, mixed>> */
    public function listAll(bool $includeInactive = true): array
    {
        if (!$this->tableExists()) {
            return ReferenceData::staticCategories();
        }

        try {
            $where = $includeInactive ? '' : 'WHERE is_active = 1';
            $stmt = $this->db->query(
                "SELECT code, name_en, name_ta, icon_key, default_visit_fee_paise,
                        base_price_paise, sort_order, is_active, created_at, updated_at
                 FROM service_categories
                 $where
                 ORDER BY sort_order ASC, name_en ASC"
            );
            $rows = $stmt->fetchAll();
            if (!is_array($rows) || $rows === []) {
                return ReferenceData::staticCategories();
            }

            return array_map(fn (array $row) => $this->mapRow($row), $rows);
        } catch (\Throwable) {
            return ReferenceData::staticCategories();
        }
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        if ($code === '' || !$this->tableExists()) {
            return null;
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT code, name_en, name_ta, icon_key, default_visit_fee_paise,
                        base_price_paise, sort_order, is_active, created_at, updated_at
                 FROM service_categories WHERE code = ? LIMIT 1'
            );
            $stmt->execute([$code]);
            $row = $stmt->fetch();
            return is_array($row) ? $this->mapRow($row) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function create(array $data): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }

        $code = strtolower(trim((string) ($data['code'] ?? '')));
        if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $code)) {
            return null;
        }

        if ($this->findByCode($code) !== null) {
            return null;
        }

        $nameEn = trim((string) ($data['name_en'] ?? ''));
        if ($nameEn === '') {
            return null;
        }

        $nameTa = trim((string) ($data['name_ta'] ?? $nameEn));
        $iconKey = trim((string) ($data['icon_key'] ?? 'build'));
        if ($iconKey === '') {
            $iconKey = 'build';
        }

        $defaultFee = max(100, (int) ($data['default_visit_fee_paise'] ?? 15000));
        $basePrice = max(100, (int) ($data['base_price_paise'] ?? $defaultFee));
        $sortOrder = max(0, (int) ($data['sort_order'] ?? 0));
        $isActive = !empty($data['is_active']) ? 1 : 0;

        $stmt = $this->db->prepare(
            'INSERT INTO service_categories
                (code, name_en, name_ta, icon_key, default_visit_fee_paise,
                 base_price_paise, sort_order, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $code,
            $nameEn,
            $nameTa,
            $iconKey,
            $defaultFee,
            $basePrice,
            $sortOrder,
            $isActive,
        ]);

        return $this->findByCode($code);
    }

    private function tableExists(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $stmt = $this->db->query(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_categories' LIMIT 1"
            );
            $cached = $stmt !== false && $stmt->fetch() !== false;
        } catch (\Throwable) {
            $cached = false;
        }

        return $cached;
    }

    /** @param array<string, mixed> $row */
    private function mapRow(array $row): array
    {
        $code = (string) $row['code'];
        $nameTa = (string) ($row['name_ta'] ?? '');
        if (self::isBrokenTamil($nameTa)) {
            $nameTa = self::staticTamilName($code) ?? $nameTa;
        }

        return [
            'code' => $code,
            'name_en' => (string) $row['name_en'],
            'name_ta' => $nameTa,
            'icon_key' => (string) ($row['icon_key'] ?? 'build'),
            'default_visit_fee_paise' => (int) $row['default_visit_fee_paise'],
            'base_price_paise' => (int) ($row['base_price_paise'] ?? $row['default_visit_fee_paise']),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'is_active' => (int) ($row['is_active'] ?? 1) === 1,
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }
}
