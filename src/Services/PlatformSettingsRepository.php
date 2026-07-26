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

    /** Platform cut on visit fee (0–100). Default 10. Deducted from prepaid wallet. */
    public function visitCommissionPercent(): int
    {
        $v = (int) $this->get('visit_commission_percent', (string) (Config::get('VISIT_COMMISSION_PERCENT') ?? '10'));

        return max(0, min(100, $v));
    }

    /**
     * Charge a wallet penalty (= commission % of visit fee) when a pro rejects
     * a job while already on the way (en_route). Default on.
     */
    public function lateRejectPenaltyEnabled(): bool
    {
        $raw = $this->get(
            'late_reject_penalty_enabled',
            (string) (Config::get('LATE_REJECT_PENALTY_ENABLED') ?? '1'),
        );

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
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

    /** Max visit fee in paise. Default ₹500 (50000). */
    public function visitFeeMaxPaise(): int
    {
        $v = (int) $this->get(
            'visit_fee_max_paise',
            (string) (Config::get('VISIT_FEE_MAX_PAISE') ?? '50000'),
        );

        return max(100, $v);
    }

    /**
     * Hours after pro marks work done before unpaid awaiting_payment auto-completes.
     * Default 48. Set 0 to disable.
     */
    public function awaitingPaymentAutoCompleteHours(): int
    {
        $v = (int) $this->get(
            'awaiting_payment_auto_complete_hours',
            (string) (Config::get('AWAITING_PAYMENT_AUTO_COMPLETE_HOURS') ?? '48'),
        );

        return max(0, min(720, $v));
    }

    /**
     * Minimum prepaid wallet balance required to accept jobs (after free tier).
     * Default ₹50 (5000 paise).
     */
    public function walletMinAcceptPaise(): int
    {
        $v = (int) $this->get(
            'wallet_min_accept_paise',
            (string) (Config::get('WALLET_MIN_ACCEPT_PAISE') ?? '5000'),
        );

        // Clamp to a sane range (₹0 … ₹10,000).
        return max(0, min(1000000, $v));
    }

    /** Minimum UPI recharge amount. Default ₹50 (5000 paise). */
    public function walletRechargeMinPaise(): int
    {
        $v = (int) $this->get(
            'wallet_recharge_min_paise',
            (string) (Config::get('WALLET_RECHARGE_MIN_PAISE') ?? '5000'),
        );
        $minAccept = $this->walletMinAcceptPaise();

        return max($minAccept > 0 ? $minAccept : 5000, min(10000000, $v));
    }

    /** Min visit fee in paise. Default ₹50 (5000). */
    public function visitFeeMinPaise(): int
    {
        $v = (int) $this->get(
            'visit_fee_min_paise',
            (string) (Config::get('VISIT_FEE_MIN_PAISE') ?? '5000'),
        );
        $max = $this->visitFeeMaxPaise();

        return max(100, min($v, $max));
    }

    /** Clamp a visit fee to platform min/max. */
    public function clampVisitFeePaise(int $paise): int
    {
        return max($this->visitFeeMinPaise(), min($this->visitFeeMaxPaise(), $paise));
    }

    /** Company UPI where professionals recharge wallet / pay platform fee. */
    public function companyUpiId(): string
    {
        $v = trim($this->get(
            'company_upi_id',
            (string) (Config::get('COMPANY_UPI_ID') ?? 'sami050699@okaxis'),
        ));

        return $v !== '' ? $v : 'sami050699@okaxis';
    }

    public function companyUpiName(): string
    {
        $v = trim($this->get(
            'company_upi_name',
            (string) (Config::get('COMPANY_UPI_NAME') ?? 'Pro Enroll'),
        ));

        return $v !== '' ? $v : 'Pro Enroll';
    }

    /**
     * UPI deep link / QR payload for paying platform fee.
     * Amount in paise → rupees with 2 decimals.
     */
    public function companyUpiPayUri(int $amountPaise, ?string $note = null): string
    {
        $rupees = max(0, $amountPaise) / 100;
        $am = number_format($rupees, 2, '.', '');
        $params = [
            'pa' => $this->companyUpiId(),
            'pn' => $this->companyUpiName(),
            'am' => $am,
            'cu' => 'INR',
            'tn' => $note ?? 'Pro Enroll wallet recharge',
        ];

        return 'upi://pay?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string, mixed> */
    public function publicPayload(): array
    {
        return [
            'visit_commission_percent' => $this->visitCommissionPercent(),
            'late_reject_penalty_enabled' => $this->lateRejectPenaltyEnabled(),
            'free_booking_limit' => $this->freeBookingLimit(),
            'hold_pro_after_free_limit' => $this->holdProAfterFreeLimit(),
            'visit_fee_min_paise' => $this->visitFeeMinPaise(),
            'visit_fee_max_paise' => $this->visitFeeMaxPaise(),
            'awaiting_payment_auto_complete_hours' => $this->awaitingPaymentAutoCompleteHours(),
            'wallet_min_accept_paise' => $this->walletMinAcceptPaise(),
            'wallet_recharge_min_paise' => $this->walletRechargeMinPaise(),
            'company_upi_id' => $this->companyUpiId(),
            'company_upi_name' => $this->companyUpiName(),
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
