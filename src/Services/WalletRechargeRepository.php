<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Database;
use ProEnroll\Api\IstTime;

/**
 * Wallet recharge requests: pro submits UPI payment + UTR, admin approves,
 * and only then is the prepaid wallet credited.
 */
final class WalletRechargeRepository
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
            $stmt = $this->db->query("SHOW TABLES LIKE 'pro_wallet_recharges'");
            self::$tableExists = $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            self::$tableExists = false;
        }

        return self::$tableExists;
    }

    /**
     * Pro submits a recharge for review.
     *
     * @return array<string, mixed> request payload
     */
    public function submit(int $professionalId, int $amountPaise, string $utr, ?string $note = null): array
    {
        if (!$this->tableExists()) {
            throw new \RuntimeException('Wallet recharge approval is not available');
        }

        $settings = new PlatformSettingsRepository();
        $min = $settings->walletRechargeMinPaise();
        if ($amountPaise < $min) {
            throw new \InvalidArgumentException(
                sprintf('Minimum recharge is ₹%d', (int) round($min / 100))
            );
        }
        if ($amountPaise > 10000000) {
            throw new \InvalidArgumentException('Recharge amount is too large');
        }

        $utr = self::normalizeUtr($utr);
        if ($this->utrExists($utr)) {
            throw new \InvalidArgumentException('This UTR was already submitted');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO pro_wallet_recharges
                (professional_id, amount_paise, utr, status, note, created_at, updated_at)
             VALUES (?, ?, ?, 'pending', ?, NOW(), NOW())"
        );
        $stmt->execute([$professionalId, $amountPaise, $utr, $note]);

        $row = $this->findById((int) $this->db->lastInsertId());

        return $row !== null ? $this->payload($row) : [];
    }

    private function utrExists(string $utr): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM pro_wallet_recharges WHERE utr = ? LIMIT 1');
        $stmt->execute([$utr]);
        if ($stmt->fetch()) {
            return true;
        }

        // Also guard against a UTR already credited directly in the ledger.
        try {
            $led = $this->db->prepare('SELECT id FROM pro_wallet_ledger WHERE utr = ? LIMIT 1');
            $led->execute([$utr]);

            return (bool) $led->fetch();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM pro_wallet_recharges WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Recharges submitted by one professional (newest first).
     *
     * @return list<array<string, mixed>>
     */
    public function listForProfessional(int $professionalId, int $limit = 20): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $limit = max(1, min(100, $limit));
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM pro_wallet_recharges
                 WHERE professional_id = ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT {$limit}"
            );
            $stmt->execute([$professionalId]);
            $rows = $stmt->fetchAll() ?: [];
        } catch (\Throwable) {
            return [];
        }

        return array_map(fn (array $r) => $this->payload($r), $rows);
    }

    public function pendingAmountPaise(int $professionalId): int
    {
        if (!$this->tableExists()) {
            return 0;
        }
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(amount_paise), 0) FROM pro_wallet_recharges
                 WHERE professional_id = ? AND status = 'pending'"
            );
            $stmt->execute([$professionalId]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function pendingCount(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }
        try {
            return (int) $this->db
                ->query("SELECT COUNT(*) FROM pro_wallet_recharges WHERE status = 'pending'")
                ->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Admin queue with professional details.
     *
     * @return list<array<string, mixed>>
     */
    public function queue(?string $status = 'pending', int $limit = 100): array
    {
        if (!$this->tableExists()) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $sql = "SELECT r.*, p.full_name, p.phone_e164, p.city_id
                FROM pro_wallet_recharges r
                INNER JOIN professionals p ON p.id = r.professional_id";
        $params = [];
        if ($status !== null && $status !== '' && $status !== 'all') {
            $sql .= ' WHERE r.status = ?';
            $params[] = $status;
        }
        $sql .= " ORDER BY r.created_at DESC, r.id DESC LIMIT {$limit}";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll() ?: [];
        } catch (\Throwable) {
            return [];
        }

        return array_map(fn (array $r) => $this->payload($r), $rows);
    }

    /**
     * Approve a pending recharge and credit the prepaid wallet exactly once.
     */
    public function approve(int $id, string $reviewedBy = 'admin'): bool
    {
        $row = $this->findById($id);
        if ($row === null || (string) $row['status'] !== 'pending') {
            return false;
        }

        $professionalId = (int) $row['professional_id'];
        $amount = (int) $row['amount_paise'];

        // Claim the row first so a double-tap cannot credit twice.
        $claim = $this->db->prepare(
            "UPDATE pro_wallet_recharges
             SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $claim->execute([$reviewedBy, $id]);
        if ($claim->rowCount() === 0) {
            return false;
        }

        $ledger = new WalletLedgerRepository();
        try {
            $result = $ledger->recharge(
                $professionalId,
                $amount,
                (string) $row['utr'],
                'Wallet recharge approved by admin',
                $id,
            );
            $link = $this->db->prepare(
                'UPDATE pro_wallet_recharges SET ledger_id = ?, updated_at = NOW() WHERE id = ?'
            );
            $link->execute([$result['id'], $id]);
        } catch (\Throwable $e) {
            // Roll the request back to pending so the admin can retry.
            $revert = $this->db->prepare(
                "UPDATE pro_wallet_recharges
                 SET status = 'pending', reviewed_by = NULL, reviewed_at = NULL, updated_at = NOW()
                 WHERE id = ? AND status = 'approved'"
            );
            $revert->execute([$id]);
            throw $e;
        }

        (new BookingRepository())->syncListingHoldForWallet($professionalId);

        return true;
    }

    public function reject(int $id, string $reason, string $reviewedBy = 'admin'): bool
    {
        if (!$this->tableExists()) {
            return false;
        }
        $stmt = $this->db->prepare(
            "UPDATE pro_wallet_recharges
             SET status = 'rejected', rejected_reason = ?, reviewed_by = ?,
                 reviewed_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$reason, $reviewedBy, $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function payload(array $row): array
    {
        $status = (string) ($row['status'] ?? 'pending');

        return [
            'id' => (string) $row['id'],
            'professional_id' => (string) $row['professional_id'],
            'professional_name' => $row['full_name'] ?? null,
            'professional_phone' => isset($row['phone_e164'])
                ? ProRepository::maskPhone((string) $row['phone_e164'])
                : null,
            'city_id' => isset($row['city_id']) ? (int) $row['city_id'] : null,
            'amount_paise' => (int) $row['amount_paise'],
            'utr' => (string) $row['utr'],
            'status' => $status,
            'status_label' => match ($status) {
                'pending' => 'Waiting for admin approval',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                default => ucfirst($status),
            },
            'note' => $row['note'] ?? null,
            'rejected_reason' => $row['rejected_reason'] ?? null,
            'reviewed_by' => $row['reviewed_by'] ?? null,
            'reviewed_at' => !empty($row['reviewed_at'])
                ? IstTime::format((string) $row['reviewed_at'])
                : null,
            'created_at' => !empty($row['created_at'])
                ? IstTime::format((string) $row['created_at'])
                : null,
        ];
    }

    public static function normalizeUtr(string $utr): string
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
