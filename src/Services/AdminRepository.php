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

    /** @var array<string, bool> */
    private array $columnCache = [];

    private ?bool $hasProDocuments = null;

    private ?bool $hasCustomers = null;

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

        $docsPending = 0;
        if ($this->hasProDocumentsTable()) {
            $docsPending = (int) $this->db->query(
                "SELECT COUNT(*) FROM pro_documents
                 WHERE status = 'pending' AND kind IN ('shop_photo', 'cert')"
            )->fetchColumn();
        }

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

        $totalRegisteredPros = (int) $this->db->query(
            "SELECT COUNT(*) FROM professionals WHERE phone_e164 IS NOT NULL"
        )->fetchColumn();

        $totalRegisteredCustomers = 0;
        if ($this->hasCustomersTable()) {
            $totalRegisteredCustomers = (int) $this->db->query(
                'SELECT COUNT(*) FROM customers'
            )->fetchColumn();
        }

        return [
            'kyc_pending' => $kycPending,
            'docs_pending' => $docsPending,
            'approved_today' => $approvedToday,
            'rejected_today' => $rejectedToday,
            'total_verified_pros' => $totalVerified,
            'total_registered_pros' => $totalRegisteredPros,
            'total_registered_customers' => $totalRegisteredCustomers,
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, page: int, limit: int, total: int, total_pages: int, has_more: bool}
     */
    public function listProfessionals(int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = max(1, min(50, $limit));
        $offset = ($page - 1) * $limit;

        $total = (int) $this->db->query(
            'SELECT COUNT(*) FROM professionals WHERE phone_e164 IS NOT NULL'
        )->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT * FROM professionals
             WHERE phone_e164 IS NOT NULL
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->buildProfessionalListItem($row);
        }

        return $this->paginatedResponse($items, $total, $page, $limit);
    }

    /** @return array<string, mixed>|null */
    public function professionalDetail(int $proId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM professionals WHERE id = ? AND phone_e164 IS NOT NULL LIMIT 1'
        );
        $stmt->execute([$proId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $profile = $this->buildProfessionalListItem($row);
        $stats = $this->bookingStatsForProfessional($proId);

        return [
            ...$profile,
            'booking_count' => $stats['booking_count'],
            'work_complete_count' => $stats['work_complete_count'],
            'rating_avg' => (float) ($row['rating_avg'] ?? 0),
            'rating_count' => (int) ($row['rating_count'] ?? 0),
            'work_radius_km' => (int) ($row['work_radius_km'] ?? 0),
            'visit_fee_paise' => (int) ($row['visit_fee_paise'] ?? 0),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, page: int, limit: int, total: int, total_pages: int, has_more: bool}
     */
    public function listProfessionalBookings(
        int $proId,
        int $page = 1,
        int $limit = 20,
        ?string $statusFilter = null,
    ): array {
        return $this->listBookingsForMember('professional_id', $proId, $page, $limit, $statusFilter, 'customer');
    }

    /**
     * @return array{items: list<array<string, mixed>>, page: int, limit: int, total: int, total_pages: int, has_more: bool}
     */
    public function listCustomers(int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = max(1, min(50, $limit));

        if (!$this->hasCustomersTable()) {
            return $this->paginatedResponse([], 0, $page, $limit);
        }

        $offset = ($page - 1) * $limit;
        $total = (int) $this->db->query('SELECT COUNT(*) FROM customers')->fetchColumn();

        $stmt = $this->db->prepare(
            'SELECT * FROM customers ORDER BY created_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->buildCustomerListItem($row);
        }

        return $this->paginatedResponse($items, $total, $page, $limit);
    }

    /** @return array<string, mixed>|null */
    public function customerDetail(int $customerId): ?array
    {
        if (!$this->hasCustomersTable()) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
        $stmt->execute([$customerId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $profile = $this->buildCustomerListItem($row);
        $stats = $this->bookingStatsForCustomer($customerId);

        return [
            ...$profile,
            'booking_count' => $stats['booking_count'],
            'work_complete_count' => $stats['work_complete_count'],
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, page: int, limit: int, total: int, total_pages: int, has_more: bool}
     */
    public function listCustomerBookings(
        int $customerId,
        int $page = 1,
        int $limit = 20,
        ?string $statusFilter = null,
    ): array {
        return $this->listBookingsForMember('customer_id', $customerId, $page, $limit, $statusFilter, 'professional');
    }

    /** @param array<string, mixed> $pro */
    private function buildProfessionalListItem(array $pro): array
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
        $primary ??= $skills[0]['category_code'] ?? null;

        $cityName = $this->cityNameForId($pro['city_id'] !== null ? (int) $pro['city_id'] : null);

        $displayName = (string) ($pro['display_name'] ?? '');
        if ($displayName === '' && !empty($pro['full_name'])) {
            $displayName = (string) $pro['full_name'];
        }

        $stats = $this->bookingStatsForProfessional($proId);

        return [
            'id' => $proId,
            'full_name' => (string) ($pro['full_name'] ?? ''),
            'display_name' => $displayName,
            'phone_e164' => (string) ($pro['phone_e164'] ?? ''),
            'city' => $cityName,
            'kyc_status' => (string) ($pro['kyc_status'] ?? 'not_started'),
            'is_available' => (bool) ($pro['is_available'] ?? false),
            'registered_at' => (string) ($pro['created_at'] ?? ''),
            'primary_category' => $primary,
            'booking_count' => $stats['booking_count'],
            'work_complete_count' => $stats['work_complete_count'],
        ];
    }

    /** @param array<string, mixed> $row */
    private function buildCustomerListItem(array $row): array
    {
        $customerId = (int) $row['id'];
        $stats = $this->bookingStatsForCustomer($customerId);

        return [
            'id' => $customerId,
            'full_name' => (string) ($row['full_name'] ?? ''),
            'phone_e164' => (string) ($row['phone_e164'] ?? ''),
            'city' => $this->cityNameForId($row['city_id'] !== null ? (int) $row['city_id'] : null),
            'registered_at' => (string) ($row['created_at'] ?? ''),
            'booking_count' => $stats['booking_count'],
            'work_complete_count' => $stats['work_complete_count'],
        ];
    }

  /** @param list<array<string, mixed>> $items */
    private function paginatedResponse(array $items, int $total, int $page, int $limit): array
    {
        $totalPages = $limit > 0 ? (int) ceil($total / $limit) : 0;

        return [
            'items' => $items,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_more' => $page < $totalPages,
        ];
    }

    private function cityNameForId(?int $cityId): string
    {
        if ($cityId === null) {
            return '—';
        }
        $city = ReferenceData::cityById($cityId);

        return $city['name'] ?? '—';
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
        if ($this->hasColumn('professionals', 'kyc_rejected_reason')) {
            $stmt = $this->db->prepare(
                "UPDATE professionals
                 SET kyc_status = 'verified', kyc_rejected_reason = NULL, updated_at = NOW()
                 WHERE id = ? AND kyc_status = 'in_review'"
            );
        } else {
            $stmt = $this->db->prepare(
                "UPDATE professionals
                 SET kyc_status = 'verified', updated_at = NOW()
                 WHERE id = ? AND kyc_status = 'in_review'"
            );
        }
        $stmt->execute([$proId]);

        return $stmt->rowCount() > 0;
    }

    public function rejectKyc(int $proId, string $reason): bool
    {
        if ($this->hasColumn('professionals', 'kyc_rejected_reason')) {
            $stmt = $this->db->prepare(
                "UPDATE professionals
                 SET kyc_status = 'rejected', kyc_rejected_reason = ?, updated_at = NOW()
                 WHERE id = ? AND kyc_status = 'in_review'"
            );
            $stmt->execute([$reason, $proId]);
        } else {
            $stmt = $this->db->prepare(
                "UPDATE professionals
                 SET kyc_status = 'rejected', updated_at = NOW()
                 WHERE id = ? AND kyc_status = 'in_review'"
            );
            $stmt->execute([$proId]);
        }

        return $stmt->rowCount() > 0;
    }

    /** @return list<array<string, mixed>> */
    public function documentQueue(?string $kind = null, ?string $status = null): array
    {
        if (!$this->hasProDocumentsTable()) {
            return [];
        }
        $sql = 'SELECT d.*, p.full_name, ' . $this->proDisplayNameSelectSql('p') . ', p.city_id
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
        if (!$this->hasProDocumentsTable()) {
            return false;
        }
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
        if (!$this->hasProDocumentsTable()) {
            return false;
        }
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
        if (!$this->hasProDocumentsTable()) {
            return;
        }
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
        if (!$this->hasProDocumentsTable()) {
            return;
        }
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
        if (!$this->hasProDocumentsTable()) {
            return [];
        }
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

    private function hasProDocumentsTable(): bool
    {
        if ($this->hasProDocuments !== null) {
            return $this->hasProDocuments;
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute(['pro_documents']);
        $this->hasProDocuments = ((int) $stmt->fetchColumn()) > 0;

        return $this->hasProDocuments;
    }

    private function hasCustomersTable(): bool
    {
        if ($this->hasCustomers !== null) {
            return $this->hasCustomers;
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute(['customers']);
        $this->hasCustomers = ((int) $stmt->fetchColumn()) > 0;

        return $this->hasCustomers;
    }

    private ?bool $hasBookings = null;

    private function hasBookingsTable(): bool
    {
        if ($this->hasBookings !== null) {
            return $this->hasBookings;
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute(['service_bookings']);
        $this->hasBookings = ((int) $stmt->fetchColumn()) > 0;

        return $this->hasBookings;
    }

    /** @return array{booking_count: int, work_complete_count: int} */
    private function bookingStatsForProfessional(int $proId): array
    {
        if (!$this->hasBookingsTable()) {
            return ['booking_count' => 0, 'work_complete_count' => 0];
        }

        $stmt = $this->db->prepare(
            'SELECT
                COUNT(*) AS booking_count,
                SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) AS work_complete_count
             FROM service_bookings
             WHERE professional_id = ?'
        );
        $stmt->execute([$proId]);
        $row = $stmt->fetch() ?: [];

        return [
            'booking_count' => (int) ($row['booking_count'] ?? 0),
            'work_complete_count' => (int) ($row['work_complete_count'] ?? 0),
        ];
    }

    /** @return array{booking_count: int, work_complete_count: int} */
    private function bookingStatsForCustomer(int $customerId): array
    {
        if (!$this->hasBookingsTable()) {
            return ['booking_count' => 0, 'work_complete_count' => 0];
        }

        $stmt = $this->db->prepare(
            'SELECT
                COUNT(*) AS booking_count,
                SUM(CASE WHEN status = \'completed\' THEN 1 ELSE 0 END) AS work_complete_count
             FROM service_bookings
             WHERE customer_id = ?'
        );
        $stmt->execute([$customerId]);
        $row = $stmt->fetch() ?: [];

        return [
            'booking_count' => (int) ($row['booking_count'] ?? 0),
            'work_complete_count' => (int) ($row['work_complete_count'] ?? 0),
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>, page: int, limit: int, total: int, total_pages: int, has_more: bool}
     */
    private function listBookingsForMember(
        string $memberColumn,
        int $memberId,
        int $page,
        int $limit,
        ?string $statusFilter,
        string $counterparty,
    ): array {
        $page = max(1, $page);
        $limit = max(1, min(50, $limit));

        if (!$this->hasBookingsTable()) {
            return $this->paginatedResponse([], 0, $page, $limit);
        }

        $allowedColumns = ['professional_id', 'customer_id'];
        if (!in_array($memberColumn, $allowedColumns, true)) {
            return $this->paginatedResponse([], 0, $page, $limit);
        }

        [$statusSql, $statusParams] = $this->bookingStatusFilterSql($statusFilter);
        $offset = ($page - 1) * $limit;

        $countSql = "SELECT COUNT(*) FROM service_bookings b WHERE b.$memberColumn = ? $statusSql";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute(array_merge([$memberId], $statusParams));
        $total = (int) $countStmt->fetchColumn();

        $join = $counterparty === 'customer'
            ? 'INNER JOIN customers c ON c.id = b.customer_id'
            : 'INNER JOIN professionals p ON p.id = b.professional_id';
        $counterpartyName = $counterparty === 'customer'
            ? 'c.full_name AS counterparty_name'
            : $this->proCounterpartyNameSql();

        $sql = "SELECT b.*, $counterpartyName
                FROM service_bookings b
                $join
                WHERE b.$memberColumn = ? $statusSql
                ORDER BY b.created_at DESC
                LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($sql);
        $idx = 1;
        $stmt->bindValue($idx++, $memberId, PDO::PARAM_INT);
        foreach ($statusParams as $param) {
            $stmt->bindValue($idx++, $param);
        }
        $stmt->bindValue($idx++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($idx++, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = $this->buildAdminBookingItem($row, $counterparty);
        }

        return $this->paginatedResponse($items, $total, $page, $limit);
    }

    /** @return array{0: string, 1: list<string>} */
    private function bookingStatusFilterSql(?string $filter): array
    {
        $filter = strtolower(trim((string) $filter));
        if ($filter === '' || $filter === 'all') {
            return ['', []];
        }

        return match ($filter) {
            'completed' => [" AND b.status = 'completed'", []],
            'cancelled' => [" AND b.status = 'cancelled'", []],
            'active' => [
                " AND b.status IN ('pending', 'confirmed', 'en_route', 'arrived', 'in_progress', 'awaiting_payment')",
                [],
            ],
            default => [' AND b.status = ?', [$filter]],
        };
    }

    /** @param array<string, mixed> $row */
    private function buildAdminBookingItem(array $row, string $counterparty): array
    {
        $catCode = (string) ($row['category_code'] ?? '');

        return [
            'id' => (int) $row['id'],
            'booking_code' => (string) ($row['booking_code'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'status_label' => BookingRepository::statusLabel((string) ($row['status'] ?? '')),
            'category_code' => $catCode,
            'category_name' => $this->categoryNameForCode($catCode),
            'counterparty_name' => (string) ($row['counterparty_name'] ?? ''),
            'counterparty_role' => $counterparty,
            'scheduled_at' => (string) ($row['scheduled_at'] ?? ''),
            'completed_at' => $row['completed_at'] ? (string) $row['completed_at'] : null,
            'visit_fee_paise' => (int) ($row['visit_fee_paise'] ?? 0),
        ];
    }

    private function categoryNameForCode(string $code): string
    {
        foreach (ReferenceData::categories() as $category) {
            if (($category['code'] ?? '') === $code) {
                return (string) ($category['name_en'] ?? $code);
            }
        }
        foreach (ReferenceData::staticCategories() as $category) {
            if (($category['code'] ?? '') === $code) {
                return (string) ($category['name_en'] ?? $code);
            }
        }

        return $code;
    }

    /** SQL fragment: display_name column or NULL alias when column missing. */
    private function proDisplayNameSelectSql(string $alias = 'p'): string
    {
        if ($this->hasColumn('professionals', 'display_name')) {
            return "{$alias}.display_name";
        }

        return 'NULL AS display_name';
    }

    /** SQL fragment: professional name for booking counterparty. */
    private function proCounterpartyNameSql(): string
    {
        if ($this->hasColumn('professionals', 'display_name')) {
            return "COALESCE(NULLIF(p.display_name, ''), p.full_name) AS counterparty_name";
        }

        return 'p.full_name AS counterparty_name';
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->columnCache)) {
            return $this->columnCache[$key];
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        $this->columnCache[$key] = ((int) $stmt->fetchColumn()) > 0;

        return $this->columnCache[$key];
    }
}
