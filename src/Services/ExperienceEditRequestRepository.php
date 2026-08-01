<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Database;

/**
 * Pros request permission to change experience start year after enrollment.
 * Admin approves → professionals.can_edit_experience = 1 until they save once.
 */
final class ExperienceEditRequestRepository
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
            $stmt = $this->db->query("SHOW TABLES LIKE 'experience_edit_requests'");
            self::$tableExists = $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            self::$tableExists = false;
        }

        return self::$tableExists;
    }

    /**
     * @return array<string, mixed> request payload
     */
    public function submit(int $professionalId, ?string $reason = null): array
    {
        if (!$this->tableExists()) {
            throw new \RuntimeException('Experience edit requests are not available');
        }

        $pending = $this->latestForProfessional($professionalId, 'pending');
        if ($pending !== null) {
            return $this->payload($pending);
        }

        $reason = $reason !== null ? trim($reason) : null;
        if ($reason === '') {
            $reason = null;
        }
        if ($reason !== null && mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 500);
        }

        $stmt = $this->db->prepare(
            "INSERT INTO experience_edit_requests
                (professional_id, reason, status, created_at, updated_at)
             VALUES (?, ?, 'pending', NOW(), NOW())"
        );
        $stmt->execute([$professionalId, $reason]);

        $row = $this->findById((int) $this->db->lastInsertId());

        return $row !== null ? $this->payload($row) : [];
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT * FROM experience_edit_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function latestForProfessional(int $professionalId, ?string $status = null): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }
        if ($status !== null) {
            $stmt = $this->db->prepare(
                'SELECT * FROM experience_edit_requests
                 WHERE professional_id = ? AND status = ?
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$professionalId, $status]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM experience_edit_requests
                 WHERE professional_id = ?
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([$professionalId]);
        }
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function pendingCount(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        return (int) $this->db
            ->query("SELECT COUNT(*) FROM experience_edit_requests WHERE status = 'pending'")
            ->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function queue(string $status = 'pending'): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $status = strtolower(trim($status));
        if ($status === 'all') {
            $sql = "SELECT r.*,
                        COALESCE(NULLIF(TRIM(p.full_name), ''), p.display_name, 'Professional') AS professional_name,
                        p.phone_e164 AS professional_phone
                    FROM experience_edit_requests r
                    LEFT JOIN professionals p ON p.id = r.professional_id
                    ORDER BY
                      CASE r.status
                        WHEN 'pending' THEN 0
                        WHEN 'approved' THEN 1
                        ELSE 2
                      END,
                      r.created_at DESC
                    LIMIT 200";
            $stmt = $this->db->query($sql);
        } else {
            if (!in_array($status, ['pending', 'approved', 'rejected', 'used'], true)) {
                $status = 'pending';
            }
            $stmt = $this->db->prepare(
                "SELECT r.*,
                    COALESCE(NULLIF(TRIM(p.full_name), ''), p.display_name, 'Professional') AS professional_name,
                    p.phone_e164 AS professional_phone
                 FROM experience_edit_requests r
                 LEFT JOIN professionals p ON p.id = r.professional_id
                 WHERE r.status = ?
                 ORDER BY r.created_at DESC
                 LIMIT 200"
            );
            $stmt->execute([$status]);
        }

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->payload($row);
        }

        return $items;
    }

    public function approve(int $id, string $reviewer): bool
    {
        if (!$this->tableExists()) {
            return false;
        }
        $row = $this->findById($id);
        if ($row === null || ($row['status'] ?? '') !== 'pending') {
            return false;
        }

        $proId = (int) $row['professional_id'];
        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare(
                "UPDATE experience_edit_requests
                 SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND status = 'pending'"
            );
            $upd->execute([$reviewer, $id]);
            if ($upd->rowCount() < 1) {
                $this->db->rollBack();

                return false;
            }

            $pros = new ProRepository();
            $pros->setCanEditExperience($proId, true);

            $this->db->commit();

            return true;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function reject(int $id, string $reason, string $reviewer): bool
    {
        if (!$this->tableExists()) {
            return false;
        }
        $reason = trim($reason);
        if ($reason === '') {
            return false;
        }
        if (mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 500);
        }

        $stmt = $this->db->prepare(
            "UPDATE experience_edit_requests
             SET status = 'rejected', admin_note = ?, reviewed_by = ?,
                 reviewed_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$reason, $reviewer, $id]);

        return $stmt->rowCount() > 0;
    }

    /** After the pro saves a year change, consume the unlock. */
    public function markApprovedUsedForPro(int $professionalId): void
    {
        if (!$this->tableExists()) {
            return;
        }
        $this->db->prepare(
            "UPDATE experience_edit_requests
             SET status = 'used', updated_at = NOW()
             WHERE professional_id = ? AND status = 'approved'"
        )->execute([$professionalId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function payload(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'professional_id' => (int) ($row['professional_id'] ?? 0),
            'professional_name' => (string) ($row['professional_name'] ?? 'Professional'),
            'professional_phone' => $row['professional_phone'] ?? null,
            'reason' => $row['reason'] ?? null,
            'status' => (string) ($row['status'] ?? 'pending'),
            'admin_note' => $row['admin_note'] ?? null,
            'reviewed_by' => $row['reviewed_by'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'reviewed_at' => $row['reviewed_at'] ?? null,
        ];
    }
}
