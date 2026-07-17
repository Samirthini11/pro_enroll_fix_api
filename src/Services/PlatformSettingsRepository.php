<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Config;
use ProEnroll\Api\Database;

/**
 * Platform business settings (commission %, free booking limit, hold policy).
 * DB `platform_settings` first; .env fallback; then hard defaults.
 */
final class PlatformSettingsRepository
{
    private PDO $db;

    private static ?bool $tableExists = null;

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /** Platform cut on visit fee (0–100). Default 5. */
    public function visitCommissionPercent(): int
    {
        $v = (int) $this->get('visit_commission_percent', (string) (Config::get('VISIT_COMMISSION_PERCENT') ?? '5'));

        return max(0, min(100, $v));
    }

    /** First N completed bookings with zero commission. Default 5. */
    public function freeBookingLimit(): int
    {
        $v = (int) $this->get('free_booking_limit', (string) (Config::get('FREE_BOOKING_LIMIT') ?? '5'));

        return max(0, $v);
    }

    /** When free limit is used up, hold pro and hide from customers. Default on. */
    public function holdProAfterFreeLimit(): bool
    {
        $raw = $this->get(
            'hold_pro_after_free_limit',
            (string) (Config::get('HOLD_PRO_AFTER_FREE_LIMIT') ?? '1'),
        );

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string, mixed> */
    public function publicPayload(): array
    {
        return [
            'visit_commission_percent' => $this->visitCommissionPercent(),
            'free_booking_limit' => $this->freeBookingLimit(),
            'hold_pro_after_free_limit' => $this->holdProAfterFreeLimit(),
        ];
    }

    public function get(string $key, string $default = ''): string
    {
        $all = $this->all();

        return $all[$key] ?? $default;
    }

    /** @return array<string, string> */
    private function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = [];
        if (!$this->tableExists()) {
            return self::$cache;
        }

        try {
            $rows = $this->db->query('SELECT setting_key, setting_value FROM platform_settings')->fetchAll();
            foreach ($rows as $row) {
                self::$cache[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        } catch (\Throwable) {
            self::$cache = [];
        }

        return self::$cache;
    }

    private function tableExists(): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }

        try {
            $stmt = $this->db->query(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'platform_settings'
                 LIMIT 1"
            );
            self::$tableExists = $stmt !== false && $stmt->fetch() !== false;
        } catch (\Throwable) {
            self::$tableExists = false;
        }

        return self::$tableExists;
    }
}
