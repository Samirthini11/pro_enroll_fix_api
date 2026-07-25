<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Database;
use ProEnroll\Api\IstTime;

/**
 * Prepaid wallet ledger: UPI recharges (+) and per-job commission debits (−).
 */
final class WalletLedgerRepository
{
    private PDO $db;

    private static ?bool $tableExists = null;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function tableExists(): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE 'pro_wallet_ledger'");
            self::$tableExists = $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            self::$tableExists = false;
        }

        return self::$tableExists;
    }

    public function balancePaise(int $professionalId): int
    {
        if (!$this->tableExists()) {
            return 0;
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT COALESCE(SUM(amount_paise), 0) FROM pro_wallet_ledger WHERE professional_id = ?'
            );
            $stmt->execute([$professionalId]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Credit wallet after company UPI payment (UTR required).
     *
     * @return array{id: int, balance_paise: int, amount_paise: int}
     */
    public function recharge(int $professionalId, int $amountPaise, string $utr, ?string $note = null): array
    {
        if (!$this->tableExists()) {
            throw new \RuntimeException('Wallet ledger is not available');
        }

        $settings = new PlatformSettingsRepository();
        $min = $settings->walletRechargeMinPaise();
        if ($amountPaise < $min) {
            throw new \InvalidArgumentException(
                sprintf('Minimum recharge is ₹%d', (int) round($min / 100))
            );
        }

        $utr = $this->normalizeUtr($utr);

        // Reject duplicate UTR
        $dup = $this->db->prepare('SELECT id FROM pro_wallet_ledger WHERE utr = ? LIMIT 1');
        $dup->execute([$utr]);
        if ($dup->fetch()) {
            throw new \InvalidArgumentException('This UTR was already used');
        }

        $this->db->beginTransaction();
        try {
            $balance = $this->balancePaise($professionalId);
            $after = $balance + $amountPaise;
            $stmt = $this->db->prepare(
                'INSERT INTO pro_wallet_ledger
                    (professional_id, entry_type, amount_paise, balance_after_paise, booking_id, utr, note, created_at)
                 VALUES (?, ?, ?, ?, NULL, ?, ?, NOW())'
            );
            $stmt->execute([
                $professionalId,
                'recharge',
                $amountPaise,
                $after,
                $utr,
                $note ?? 'Wallet recharge via company UPI',
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->db->commit();

            return [
                'id' => $id,
                'balance_paise' => $after,
                'amount_paise' => $amountPaise,
            ];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Debit platform fee (10% of visit) from prepaid wallet.
     * Returns false if ledger missing or already debited for this booking.
     */
    public function debitCommission(int $professionalId, int $bookingId, int $amountPaise): bool
    {
        if (!$this->tableExists() || $amountPaise <= 0) {
            return false;
        }

        $exists = $this->db->prepare(
            "SELECT id FROM pro_wallet_ledger
             WHERE booking_id = ? AND entry_type = 'commission_debit' LIMIT 1"
        );
        $exists->execute([$bookingId]);
        if ($exists->fetch()) {
            return true; // already deducted
        }

        $this->db->beginTransaction();
        try {
            $balance = $this->balancePaise($professionalId);
            $after = $balance - $amountPaise;
            $stmt = $this->db->prepare(
                'INSERT INTO pro_wallet_ledger
                    (professional_id, entry_type, amount_paise, balance_after_paise, booking_id, utr, note, created_at)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, NOW())'
            );
            $stmt->execute([
                $professionalId,
                'commission_debit',
                -$amountPaise,
                $after,
                $bookingId,
                sprintf('Platform fee %d%% deducted', (new PlatformSettingsRepository())->visitCommissionPercent()),
            ]);
            $this->db->commit();

            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $professionalId, int $limit = 50): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        try {
            $stmt = $this->db->prepare(
                "SELECT l.*, b.booking_code
                 FROM pro_wallet_ledger l
                 LEFT JOIN service_bookings b ON b.id = l.booking_id
                 WHERE l.professional_id = ?
                 ORDER BY l.created_at DESC, l.id DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([$professionalId]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string) ($row['entry_type'] ?? '');
            $amount = (int) ($row['amount_paise'] ?? 0);
            $label = match ($type) {
                'recharge' => 'Wallet recharge',
                'commission_debit' => 'Platform fee deducted',
                default => ucwords(str_replace('_', ' ', $type)),
            };
            if (!empty($row['booking_code'])) {
                $label .= ' · ' . $row['booking_code'];
            }

            $out[] = [
                'id' => (string) $row['id'],
                'entry_type' => $type,
                'amount_paise' => $amount,
                'balance_after_paise' => (int) ($row['balance_after_paise'] ?? 0),
                'booking_id' => $row['booking_id'] !== null ? (string) $row['booking_id'] : null,
                'booking_code' => $row['booking_code'] ?? null,
                'utr' => $row['utr'] ?? null,
                'note' => $row['note'] ?? null,
                'label' => $label,
                'created_at' => !empty($row['created_at'])
                    ? IstTime::format((string) $row['created_at'])
                    : null,
            ];
        }

        return $out;
    }

    private function normalizeUtr(string $utr): string
    {
        $utr = strtoupper(trim($utr));
        $utr = preg_replace('/\s+/', '', $utr) ?? '';
        if (strlen($utr) < 8 || strlen($utr) > 64) {
            throw new \InvalidArgumentException('UTR must be 8–64 characters');
        }
        if (!preg_match('/^[A-Z0-9]+$/', $utr)) {
            throw new \InvalidArgumentException('UTR must be letters and numbers only');
        }

        return $utr;
    }
}
