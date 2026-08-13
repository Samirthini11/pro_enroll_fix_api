<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Database;

/**
 * Recalculates professionals.pro_score (0–100) from KYC, documents, jobs, and ratings.
 *
 * Formula (sum, clamped 0–100):
 *   KYC        max 30  — verified 30, in_review 18, aadhaar/selfie pending 10, rejected 5
 *   Documents  max 20  — aadhaar / selfie / shop_photo / cert: approved 5, pending+file 2
 *   Jobs       max 25  — min(25, jobs_completed × 2)
 *   Ratings    max 25  — (rating_avg / 5) × 25 × min(1, rating_count / 3)
 */
final class ProScoreService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public function recalculate(int $professionalId): int
    {
        $stmt = $this->db->prepare(
            'SELECT id, kyc_status, face_match_score, rating_avg, rating_count, jobs_completed
             FROM professionals WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$professionalId]);
        $pro = $stmt->fetch();
        if (!$pro) {
            return 0;
        }

        $score = $this->scoreFromRow($pro, $this->documentStats($professionalId));
        $this->persist($professionalId, $score);

        return $score;
    }

    /**
     * Recompute every professional (ops / deploy backfill).
     *
     * @return int Number of rows updated
     */
    public function recalculateAll(): int
    {
        $ids = $this->db->query('SELECT id FROM professionals')->fetchAll(PDO::FETCH_COLUMN);
        $n = 0;
        foreach ($ids as $id) {
            $this->recalculate((int) $id);
            $n++;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $pro
     * @param array<string, array{status: string, has_file: bool}> $docs By kind
     */
    public function scoreFromRow(array $pro, array $docs): int
    {
        $total = $this->kycPoints($pro)
            + $this->documentPoints($docs)
            + $this->jobsPoints((int) ($pro['jobs_completed'] ?? 0))
            + $this->ratingsPoints(
                (float) ($pro['rating_avg'] ?? 0),
                (int) ($pro['rating_count'] ?? 0),
            );

        return max(0, min(100, $total));
    }

    /** @param array<string, mixed> $pro */
    private function kycPoints(array $pro): int
    {
        $status = (string) ($pro['kyc_status'] ?? 'not_started');
        $base = match ($status) {
            'verified' => 30,
            'in_review' => 18,
            'aadhaar_pending', 'selfie_pending' => 10,
            'rejected' => 5,
            default => 0,
        };

        // Small face-match boost while still progressing KYC (already capped by bucket).
        $face = $pro['face_match_score'] ?? null;
        if ($face !== null && $status !== 'verified' && $status !== 'rejected') {
            $faceBoost = (float) $face >= 0.85 ? 2 : ((float) $face >= 0.7 ? 1 : 0);
            $base = min(30, $base + $faceBoost);
        }

        return $base;
    }

    /**
     * @param array<string, array{status: string, has_file: bool}> $docs
     */
    private function documentPoints(array $docs): int
    {
        $kinds = ['aadhaar', 'selfie', 'shop_photo', 'cert'];
        $points = 0;
        foreach ($kinds as $kind) {
            $row = $docs[$kind] ?? null;
            if ($row === null) {
                continue;
            }
            $status = $row['status'];
            if ($status === 'approved') {
                $points += 5;
            } elseif ($status === 'pending' && $row['has_file']) {
                $points += 2;
            }
        }

        // Optional PAN: up to +3 if room remains under the 20 cap.
        $pan = $docs['pan'] ?? null;
        if ($pan !== null && $pan['status'] === 'approved' && $points < 20) {
            $points = min(20, $points + 3);
        }

        return min(20, $points);
    }

    private function jobsPoints(int $jobsCompleted): int
    {
        return min(25, max(0, $jobsCompleted) * 2);
    }

    private function ratingsPoints(float $avg, int $count): int
    {
        if ($count <= 0 || $avg <= 0) {
            return 0;
        }
        $avg = max(0.0, min(5.0, $avg));
        $confidence = min(1.0, $count / 3.0);

        return (int) round(($avg / 5.0) * 25.0 * $confidence);
    }

    /**
     * @return array<string, array{status: string, has_file: bool}>
     */
    private function documentStats(int $professionalId): array
    {
        if (!$this->hasProDocumentsTable()) {
            return [];
        }

        $hasFileUrl = $this->hasColumn('pro_documents', 'file_url');
        $hasThumb = $this->hasColumn('pro_documents', 'thumbnail_url');

        $fileExpr = '0';
        if ($hasFileUrl && $hasThumb) {
            $fileExpr = "(CASE WHEN (file_url IS NOT NULL AND file_url <> '')
                OR (thumbnail_url IS NOT NULL AND thumbnail_url <> '') THEN 1 ELSE 0 END)";
        } elseif ($hasFileUrl) {
            $fileExpr = "(CASE WHEN file_url IS NOT NULL AND file_url <> '' THEN 1 ELSE 0 END)";
        } elseif ($hasThumb) {
            $fileExpr = "(CASE WHEN thumbnail_url IS NOT NULL AND thumbnail_url <> '' THEN 1 ELSE 0 END)";
        }

        $stmt = $this->db->prepare(
            "SELECT kind, status, {$fileExpr} AS has_file
             FROM pro_documents
             WHERE professional_id = ?"
        );
        $stmt->execute([$professionalId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $kind = (string) $row['kind'];
            // Prefer approved over pending if duplicates ever exist.
            if (isset($out[$kind]) && $out[$kind]['status'] === 'approved') {
                continue;
            }
            $out[$kind] = [
                'status' => (string) $row['status'],
                'has_file' => ((int) $row['has_file']) === 1,
            ];
        }

        return $out;
    }

    private function persist(int $professionalId, int $score): void
    {
        $this->db->prepare(
            'UPDATE professionals SET pro_score = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$score, $professionalId]);
    }

    private function hasProDocumentsTable(): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute(['pro_documents']);

        return ((int) $stmt->fetchColumn()) > 0;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return ((int) $stmt->fetchColumn()) > 0;
    }
}
