<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Database;
use ProEnroll\Api\ReferenceData;

/**
 * Admin KYC queue and document review backed by professionals + pro_documents.
 */
final class AdminRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /** @return array<string, int> */
    public function dashboardStats(): array
    {
        $kycPending = (int) $this->db->query(
            "SELECT COUNT(*) FROM professionals WHERE kyc_status = 'in_review'"
        )->fetchColumn();

        $docsPending = (int) $this->db->query(
            "SELECT COUNT(*) FROM pro_documents
             WHERE status = 'pending' AND kind IN ('shop_photo', 'cert')"
        )->fetchColumn();

        $approvedToday = (int) $this->db->query(
            "SELECT COUNT(*) FROM professionals
             WHERE kyc_status = 'verified' AND DATE(updated_at) = CURDATE()"
        )->fetchColumn();

        $rejectedToday = (int) $this->db->query(
            "SELECT COUNT(*) FROM professionals
             WHERE kyc_status = 'rejected' AND DATE(updated_at) = CURDATE()"
        )->fetchColumn();

        $totalVerified = (int) $this->db->query(
            "SELECT COUNT(*) FROM professionals WHERE kyc_status = 'verified'"
        )->fetchColumn();

        return [
            'kyc_pending' => $kycPending,
            'docs_pending' => $docsPending,
            'approved_today' => $approvedToday,
            'rejected_today' => $rejectedToday,
            'total_verified_pros' => $totalVerified,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function kycQueue(?string $status = null): array
    {
        $sql = 'SELECT * FROM professionals WHERE full_name IS NOT NULL';
        $params = [];

        if ($status !== null && $status !== '') {
            $dbStatus = $this->mapReviewStatus($status);
            $sql .= ' AND kyc_status = ?';
            $params[] = $dbStatus;
        } else {
            $sql .= " AND kyc_status IN ('in_review', 'verified', 'rejected')";
        }

        $sql .= ' ORDER BY updated_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = $this->buildKycPayload($row);
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function kycDetail(int $proId): ?array
    {
        $pro = (new ProRepository())->findById($proId);
        if ($pro === null) {
            return null;
        }

        return $this->buildKycPayload($pro);
    }

    public function approveKyc(int $proId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE professionals
             SET kyc_status = 'verified', kyc_rejected_reason = NULL, updated_at = NOW()
             WHERE id = ? AND kyc_status = 'in_review'"
        );
        $stmt->execute([$proId]);

        return $stmt->rowCount() > 0;
    }

    public function rejectKyc(int $proId, string $reason): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE professionals
             SET kyc_status = 'rejected', kyc_rejected_reason = ?, updated_at = NOW()
             WHERE id = ? AND kyc_status = 'in_review'"
        );
        $stmt->execute([$reason, $proId]);

        return $stmt->rowCount() > 0;
    }

    /** @return list<array<string, mixed>> */
    public function documentQueue(?string $kind = null, ?string $status = null): array
    {
        $sql = 'SELECT d.*, p.full_name, p.display_name, p.city_id
                FROM pro_documents d
                INNER JOIN professionals p ON p.id = d.professional_id
                WHERE d.kind IN (\'shop_photo\', \'cert\')';
        $params = [];

        if ($kind !== null && $kind !== '') {
            $sql .= ' AND d.kind = ?';
            $params[] = $kind;
        }
        if ($status !== null && $status !== '') {
            $sql .= ' AND d.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY d.uploaded_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = $this->buildDocumentPayload($row);
        }

        return $out;
    }

    public function approveDocument(int $documentId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE pro_documents
             SET status = 'approved', rejected_reason = NULL, reviewed_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$documentId]);

        return $stmt->rowCount() > 0;
    }

    public function rejectDocument(int $documentId, string $reason): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE pro_documents
             SET status = 'rejected', rejected_reason = ?, reviewed_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$reason, $documentId]);

        return $stmt->rowCount() > 0;
    }

    /** @param list<string> $documentTypes */
    public function seedDocumentsFromKycUpload(int $professionalId, array $documentTypes): void
    {
        $map = [
            'shop' => ['kind' => 'shop_photo', 'label' => 'Shop / workshop photo'],
            'shop_photo' => ['kind' => 'shop_photo', 'label' => 'Shop / workshop photo'],
            'cert' => ['kind' => 'cert', 'label' => 'Training certificate'],
            'tools' => ['kind' => 'cert', 'label' => 'Tools / training certificate'],
            'pan' => ['kind' => 'pan', 'label' => 'PAN card'],
        ];

        foreach ($documentTypes as $type) {
            $key = strtolower((string) $type);
            $meta = $map[$key] ?? ['kind' => 'other', 'label' => ucfirst($key)];
            $this->insertDocumentIfMissing($professionalId, $meta['kind'], $meta['label']);
        }

        $this->ensureCoreKycDocuments($professionalId);
    }

    public function ensureCoreKycDocuments(int $professionalId): void
    {
        $pro = (new ProRepository())->findById($professionalId);
        if ($pro === null) {
            return;
        }

        if (!empty($pro['aadhaar_last4'])) {
            $this->insertDocumentIfMissing($professionalId, 'aadhaar', 'Aadhaar (masked)', 'approved');
        }

        $selfieStatus = in_array((string) $pro['kyc_status'], ['in_review', 'verified'], true)
            ? 'approved'
            : 'pending';
        $this->insertDocumentIfMissing($professionalId, 'selfie', 'Selfie + face match', $selfieStatus);
    }

    private function insertDocumentIfMissing(
        int $professionalId,
        string $kind,
        string $label,
        string $status = 'pending',
    ): void {
        $check = $this->db->prepare(
            'SELECT id FROM pro_documents WHERE professional_id = ? AND kind = ? LIMIT 1'
        );
        $check->execute([$professionalId, $kind]);
        if ($check->fetch()) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO pro_documents (professional_id, kind, label, status, uploaded_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$professionalId, $kind, $label, $status]);
    }

    /** @param array<string, mixed> $pro */
    private function buildKycPayload(array $pro): array
    {
        $proId = (int) $pro['id'];
        $skills = (new ProRepository())->getSkills($proId);
        $primary = null;
        foreach ($skills as $skill) {
            if ($skill['is_primary']) {
                $primary = $skill['category_code'];
                break;
            }
        }
        $primary ??= $skills[0]['category_code'] ?? 'ac';

        $cityName = '—';
        if ($pro['city_id'] !== null) {
            $city = ReferenceData::cityById((int) $pro['city_id']);
            $cityName = $city['name'] ?? '—';
        }

        $displayName = (string) ($pro['display_name'] ?? '');
        if ($displayName === '' && !empty($pro['full_name'])) {
            $catLabel = $primary !== null ? strtoupper($primary) : 'Pro';
            $displayName = trim((string) $pro['full_name']) . ' ' . $catLabel;
        }

        $this->ensureCoreKycDocuments($proId);
        $documents = $this->documentsForPro($proId);

        return [
            'pro_id' => $proId,
            'full_name' => (string) ($pro['full_name'] ?? ''),
            'display_name' => $displayName,
            'phone_e164' => (string) ($pro['phone_e164'] ?? ''),
            'city' => $cityName,
            'address' => null,
            'skills' => array_map(static fn (array $s) => [
                'category_code' => $s['category_code'],
                'experience_years' => (int) $s['experience_years'],
                'is_primary' => (bool) $s['is_primary'],
            ], $skills),
            'work_radius_km' => (int) $pro['work_radius_km'],
            'visit_fee_paise' => (int) $pro['visit_fee_paise'],
            'aadhaar_last4' => (string) ($pro['aadhaar_last4'] ?? ''),
            'face_match_score' => $pro['face_match_score'] !== null
                ? (float) $pro['face_match_score']
                : 0.0,
            'submitted_at' => (string) ($pro['updated_at'] ?? $pro['created_at']),
            'status' => $this->mapDbReviewStatus((string) $pro['kyc_status']),
            'rejected_reason' => $pro['kyc_rejected_reason'] ?? null,
            'documents' => $documents,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function documentsForPro(int $proId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM pro_documents WHERE professional_id = ? ORDER BY uploaded_at ASC'
        );
        $stmt->execute([$proId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'kind' => $row['kind'],
                'label' => $row['label'],
                'status' => $row['status'],
                'thumbnail_url' => $row['thumbnail_url'],
                'uploaded_at' => $row['uploaded_at'],
                'rejected_reason' => $row['rejected_reason'],
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function buildDocumentPayload(array $row): array
    {
        $cityName = '—';
        if ($row['city_id'] !== null) {
            $city = ReferenceData::cityById((int) $row['city_id']);
            $cityName = $city['name'] ?? '—';
        }

        $proName = (string) ($row['display_name'] ?? $row['full_name'] ?? 'Pro');

        return [
            'document_id' => (int) $row['id'],
            'pro_id' => (int) $row['professional_id'],
            'pro_name' => $proName,
            'city' => $cityName,
            'kind' => $row['kind'],
            'label' => $row['label'],
            'submitted_at' => $row['uploaded_at'],
            'status' => $row['status'],
            'thumbnail_url' => $row['thumbnail_url'],
            'rejected_reason' => $row['rejected_reason'],
            'notes' => $row['kind'] === 'shop_photo'
                ? 'Verify shop/workshop matches registered address'
                : 'Verify training certificate authenticity',
        ];
    }

    private function mapReviewStatus(string $status): string
    {
        return match ($status) {
            'in_review', 'inReview' => 'in_review',
            'verified' => 'verified',
            'rejected' => 'rejected',
            default => 'in_review',
        };
    }

    private function mapDbReviewStatus(string $dbStatus): string
    {
        return match ($dbStatus) {
            'verified' => 'verified',
            'rejected' => 'rejected',
            'in_review' => 'in_review',
            default => 'in_review',
        };
    }
}
