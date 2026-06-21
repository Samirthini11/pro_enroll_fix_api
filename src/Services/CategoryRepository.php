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
                $out[] = [
                    'code' => (string) $row['code'],
                    'name_en' => (string) $row['name_en'],
                    'name_ta' => (string) $row['name_ta'],
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
}
