<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Database;
use ProEnroll\Api\IstTime;
use ProEnroll\Api\ReferenceData;

final class BookingRepository
{
    private PDO $db;

    /** @var bool|null Cached schema probe for optional final_amount_paise column. */
    private static ?bool $hasFinalAmountColumn = null;

    /** @var bool|null Cached schema probe for visit fee payment columns. */
    private static ?bool $hasVisitFeePaymentColumns = null;

    /** @var bool|null Cached schema probe for commission columns. */
    private static ?bool $hasCommissionColumns = null;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $code = 'PF-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $visitFeePaid = !empty($data['visit_fee_paid']);
        $paymentMethod = trim((string) ($data['visit_fee_payment_method'] ?? ''));
        if ($paymentMethod === '') {
            $paymentMethod = $visitFeePaid ? 'upi' : null;
        }

        if ($this->hasVisitFeePaymentColumns()) {
            $this->db->prepare(
                'INSERT INTO service_bookings
                 (booking_code, customer_id, professional_id, category_code, problem_description,
                  address_text, address_lat, address_lng, city_id, status, visit_fee_paise,
                  visit_fee_paid, visit_fee_paid_at, visit_fee_payment_method,
                  scheduled_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(?, NOW(), NULL), ?, ?, NOW(), NOW())'
            )->execute([
                $code,
                $data['customer_id'],
                $data['professional_id'],
                $data['category_code'],
                $data['problem_description'],
                $data['address_text'],
                $data['address_lat'] ?? null,
                $data['address_lng'] ?? null,
                $data['city_id'],
                'confirmed',
                $data['visit_fee_paise'],
                $visitFeePaid ? 1 : 0,
                $visitFeePaid ? 1 : 0,
                $paymentMethod,
                $data['scheduled_at'],
            ]);
        } else {
            $this->db->prepare(
                'INSERT INTO service_bookings
                 (booking_code, customer_id, professional_id, category_code, problem_description,
                  address_text, address_lat, address_lng, city_id, status, visit_fee_paise,
                  scheduled_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            )->execute([
                $code,
                $data['customer_id'],
                $data['professional_id'],
                $data['category_code'],
                $data['problem_description'],
                $data['address_text'],
                $data['address_lat'] ?? null,
                $data['address_lng'] ?? null,
                $data['city_id'],
                'confirmed',
                $data['visit_fee_paise'],
                $data['scheduled_at'],
            ]);
        }

        $id = (int) $this->db->lastInsertId();
        return $this->findById($id) ?? [];
    }

    /**
     * Customer may cancel only before the pro is on the way (status still confirmed).
     */
    public function cancelForCustomer(int $bookingId, int $customerId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE service_bookings
             SET status = ?, updated_at = NOW()
             WHERE id = ? AND customer_id = ?
               AND status = \'confirmed\''
        );
        $stmt->execute(['cancelled', $bookingId, $customerId]);
        return $stmt->rowCount() > 0;
    }

    private function hasVisitFeePaymentColumns(): bool
    {
        if (self::$hasVisitFeePaymentColumns !== null) {
            return self::$hasVisitFeePaymentColumns;
        }

        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM service_bookings LIKE 'visit_fee_paid'");
            self::$hasVisitFeePaymentColumns = $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            self::$hasVisitFeePaymentColumns = false;
        }

        return self::$hasVisitFeePaymentColumns;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT b.*, p.full_name AS pro_name, p.phone_e164 AS pro_phone,
                    p.rating_avg AS pro_rating_avg, p.rating_count AS pro_rating_count,
                    p.kyc_status AS pro_kyc_status,
                    p.home_lat AS pro_home_lat, p.home_lng AS pro_home_lng,
                    p.last_lat AS pro_last_lat, p.last_lng AS pro_last_lng,
                    p.last_location_at AS pro_last_location_at,
                    c.full_name AS customer_name, c.phone_e164 AS customer_phone
             FROM service_bookings b
             INNER JOIN professionals p ON p.id = b.professional_id
             INNER JOIN customers c ON c.id = b.customer_id
             WHERE b.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByIdForCustomer(int $id, int $customerId): ?array
    {
        $row = $this->findById($id);
        if ($row === null || (int) $row['customer_id'] !== $customerId) {
            return null;
        }
        return $row;
    }

    /** @var list<string> Statuses that block a new booking for the same pro + service. */
    private const ACTIVE_CUSTOMER_BOOKING_STATUSES = [
        'confirmed',
        'en_route',
        'arrived',
        'in_progress',
        'awaiting_payment',
    ];

    /**
     * Returns an in-process booking for the same customer, professional, and category, if any.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveForCustomerProCategory(
        int $customerId,
        int $professionalId,
        string $categoryCode,
    ): ?array {
        $placeholders = implode(', ', array_fill(0, count(self::ACTIVE_CUSTOMER_BOOKING_STATUSES), '?'));
        $stmt = $this->db->prepare(
            "SELECT b.*
             FROM service_bookings b
             WHERE b.customer_id = ?
               AND b.professional_id = ?
               AND b.category_code = ?
               AND b.status IN ($placeholders)
             ORDER BY b.created_at DESC
             LIMIT 1"
        );
        $stmt->execute(array_merge(
            [$customerId, $professionalId, $categoryCode],
            self::ACTIVE_CUSTOMER_BOOKING_STATUSES,
        ));
        $row = $stmt->fetch();

        return $row !== false ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function listForCustomer(int $customerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT b.*, p.full_name AS pro_name, p.phone_e164 AS pro_phone,
                    p.rating_avg AS pro_rating_avg, p.rating_count AS pro_rating_count,
                    p.kyc_status AS pro_kyc_status
             FROM service_bookings b
             INNER JOIN professionals p ON p.id = b.professional_id
             WHERE b.customer_id = ?
             ORDER BY b.created_at DESC'
        );
        $stmt->execute([$customerId]);
        return $stmt->fetchAll();
    }

    public function markCompleted(int $bookingId, int $customerId): bool
    {
        $booking = $this->findByIdForCustomer($bookingId, $customerId);
        if ($booking === null) {
            return false;
        }

        // Visit fee unpaid → move to payment due (do not settle yet).
        if (empty($booking['visit_fee_paid'])) {
            if (!in_array((string) $booking['status'], [
                'in_progress', 'arrived', 'en_route', 'awaiting_payment',
            ], true)) {
                return false;
            }
            if ((string) $booking['status'] === 'awaiting_payment') {
                return true;
            }
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET status = 'awaiting_payment', updated_at = NOW()
                 WHERE id = ? AND customer_id = ?
                   AND status IN ('in_progress', 'arrived', 'en_route')"
            );
            $stmt->execute([$bookingId, $customerId]);

            return $stmt->rowCount() > 0;
        }

        if (!in_array((string) $booking['status'], [
            'in_progress', 'awaiting_payment', 'arrived', 'en_route', 'confirmed',
        ], true)) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE service_bookings
             SET status = ?, completed_at = NOW(), updated_at = NOW()
             WHERE id = ? AND customer_id = ?
               AND status IN (\'in_progress\', \'awaiting_payment\', \'arrived\', \'en_route\', \'confirmed\')'
        );
        $stmt->execute(['completed', $bookingId, $customerId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }

        $this->settleCommissionAndCredit($bookingId, (int) $booking['professional_id']);

        return true;
    }

    public function addRating(int $bookingId, int $customerId, int $stars, ?string $review): bool
    {
        $booking = $this->findByIdForCustomer($bookingId, $customerId);
        if ($booking === null || $booking['status'] !== 'completed') {
            return false;
        }

        $exists = $this->db->prepare('SELECT id FROM booking_ratings WHERE booking_id = ?');
        $exists->execute([$bookingId]);
        if ($exists->fetch()) {
            return false;
        }

        $this->db->prepare(
            'INSERT INTO booking_ratings (booking_id, stars, review_text, created_at)
             VALUES (?, ?, ?, NOW())'
        )->execute([$bookingId, $stars, $review]);

        $proId = (int) $booking['professional_id'];
        $this->db->prepare(
            'UPDATE professionals p SET
             rating_count = rating_count + 1,
             rating_avg = (
               SELECT ROUND(AVG(stars), 2) FROM booking_ratings br
               INNER JOIN service_bookings sb ON sb.id = br.booking_id
               WHERE sb.professional_id = p.id
             ),
             updated_at = NOW()
             WHERE p.id = ?'
        )->execute([$proId]);

        return true;
    }

    public function getRating(int $bookingId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM booking_ratings WHERE booking_id = ? LIMIT 1');
        $stmt->execute([$bookingId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string, mixed> */
    public function bookingPayload(array $row): array
    {
        $city = ReferenceData::cityById((int) $row['city_id']);
        $catName = $this->categoryName($row['category_code']);
        $rating = $this->getRating((int) $row['id']);

        return [
            'id' => (string) $row['id'],
            'booking_code' => $row['booking_code'],
            'status' => $row['status'],
            'category_code' => $row['category_code'],
            'category_name' => $catName,
            'problem_description' => $row['problem_description'],
            'address_text' => $row['address_text'],
            'address_lat' => $row['address_lat'] !== null ? (float) $row['address_lat'] : null,
            'address_lng' => $row['address_lng'] !== null ? (float) $row['address_lng'] : null,
            'city_id' => (int) $row['city_id'],
            'city_name' => $city['name'] ?? '',
            'visit_fee_paise' => (int) $row['visit_fee_paise'],
            'visit_fee_paid' => (bool) ($row['visit_fee_paid'] ?? false),
            'visit_fee_paid_at' => !empty($row['visit_fee_paid_at'])
                ? IstTime::format((string) $row['visit_fee_paid_at'])
                : null,
            'visit_fee_payment_method' => $row['visit_fee_payment_method'] ?? null,
            'final_amount_paise' => isset($row['final_amount_paise']) && $row['final_amount_paise'] !== null
                ? (int) $row['final_amount_paise'] : null,
            'total_due_paise' => self::totalDuePaise($row),
            // Never expose platform commission / pro credit to customers.
            'status_label' => self::statusLabel((string) $row['status']),
            'scheduled_at' => IstTime::format((string) $row['scheduled_at']),
            'accepted_at' => !empty($row['accepted_at'])
                ? IstTime::format((string) $row['accepted_at'])
                : null,
            'completed_at' => $row['completed_at']
                ? IstTime::format((string) $row['completed_at'])
                : null,
            'created_at' => IstTime::format((string) $row['created_at']),
            'professional' => [
                'id' => (string) $row['professional_id'],
                'full_name' => $row['pro_name'],
                'phone_e164' => $row['pro_phone'] ?? null,
                'phone_masked' => ProRepository::maskPhone((string) ($row['pro_phone'] ?? '')),
                'rating_avg' => (float) ($row['pro_rating_avg'] ?? 0),
                'rating_count' => (int) ($row['pro_rating_count'] ?? 0),
                'kyc_verified' => ($row['pro_kyc_status'] ?? '') === 'verified',
            ],
            'tracking_steps' => self::trackingSteps($row['status']),
            'rating' => $rating === null ? null : [
                'stars' => (int) $rating['stars'],
                'review_text' => $rating['review_text'],
            ],
            'can_rate' => $row['status'] === 'completed' && $rating === null,
            'can_cancel' => $row['status'] === 'confirmed',
            'can_mark_completed' => !empty($row['visit_fee_paid']) && in_array($row['status'], [
                'en_route', 'arrived', 'in_progress', 'awaiting_payment',
            ], true),
            'can_pay_visit_fee' => empty($row['visit_fee_paid']) && ($row['status'] ?? '') === 'awaiting_payment',
            'tracking' => self::trackingPayload($row),
        ];
    }

    /**
     * Live technician location + ETA for customer while job is in progress.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private static function trackingPayload(array $row): ?array
    {
        $status = (string) ($row['status'] ?? '');
        if (!in_array($status, ['en_route', 'arrived'], true)) {
            return null;
        }

        $pro = [
            'home_lat' => $row['pro_home_lat'] ?? null,
            'home_lng' => $row['pro_home_lng'] ?? null,
            'last_lat' => $row['pro_last_lat'] ?? null,
            'last_lng' => $row['pro_last_lng'] ?? null,
            'last_location_at' => $row['pro_last_location_at'] ?? null,
        ];
        [$proLat, $proLng] = ProRepository::resolveCoords($pro);
        if ($proLat === null || $proLng === null) {
            return null;
        }

        $distanceKm = self::distanceKm($row, $proLat, $proLng);
        $updatedAt = $pro['last_location_at'] ?? ($row['updated_at'] ?? null);

        return [
            'pro_lat' => $proLat,
            'pro_lng' => $proLng,
            'distance_km' => $distanceKm,
            'eta_minutes' => ProRepository::etaMinutesFromDistanceKm($distanceKm),
            'updated_at' => $updatedAt !== null ? IstTime::format((string) $updatedAt) : null,
        ];
    }

    public function updateProLocationForActiveJob(
        int $bookingId,
        int $professionalId,
        float $lat,
        float $lng,
    ): bool {
        $active = $this->findActiveForProfessional($professionalId, $bookingId);
        if ($active === null) {
            return false;
        }

        if (!in_array((string) $active['status'], ['en_route', 'arrived', 'in_progress'], true)) {
            return false;
        }

        $pros = new ProRepository();
        if (!$pros->updateLastLocation($professionalId, $lat, $lng)) {
            return false;
        }

        $this->db->prepare(
            'UPDATE service_bookings SET updated_at = NOW() WHERE id = ? AND professional_id = ?'
        )->execute([$bookingId, $professionalId]);

        return true;
    }

    /** @param array<string, mixed> $row */
    private static function totalDuePaise(array $row): int
    {
        $visitFee = (int) $row['visit_fee_paise'];
        $final = isset($row['final_amount_paise']) && $row['final_amount_paise'] !== null
            ? (int) $row['final_amount_paise'] : null;

        if ($final !== null && $final >= 100) {
            return $final;
        }

        return $visitFee;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'confirmed' => 'Booking confirmed',
            'en_route' => 'Technician en route',
            'arrived' => 'Arrived at your location',
            'in_progress' => 'Repair in progress',
            'awaiting_payment' => 'Awaiting payment',
            'completed' => 'Work completed',
            'cancelled' => 'Booking cancelled',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /** @return list<array<string, mixed>> */
    private static function trackingSteps(string $current): array
    {
        if ($current === 'cancelled') {
            return [
                ['key' => 'cancelled', 'label' => 'Booking cancelled', 'state' => 'active'],
            ];
        }

        $steps = [
            ['key' => 'confirmed', 'label' => 'Booking confirmed'],
            ['key' => 'en_route', 'label' => 'Technician en route'],
            ['key' => 'arrived', 'label' => 'Arrived at your location'],
            ['key' => 'in_progress', 'label' => 'Repair in progress'],
            ['key' => 'awaiting_payment', 'label' => 'Payment due'],
            ['key' => 'completed', 'label' => 'Work completed'],
        ];
        $order = array_column($steps, 'key');
        $idx = array_search($current, $order, true);
        if ($idx === false) {
            $idx = 0;
        }

        $out = [];
        foreach ($steps as $i => $step) {
            $state = 'upcoming';
            if ($i < $idx) {
                $state = 'done';
            } elseif ($i === $idx) {
                $state = 'active';
            }
            $out[] = array_merge($step, ['state' => $state]);
        }
        return $out;
    }

    private function categoryName(string $code): string
    {
        foreach (ReferenceData::categories() as $c) {
            if ($c['code'] === $code) {
                return $c['name_en'];
            }
        }
        return $code;
    }

    // ─── Professional job offers (Jobs near you) ───────────────────────────

    /** @return list<array<string, mixed>> */
    public function listOffersForProfessional(int $professionalId, array $categoryCodes): array
    {
        if ($categoryCodes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($categoryCodes), '?'));
        $stmt = $this->db->prepare(
            "SELECT b.*, c.full_name AS customer_name, c.phone_e164 AS customer_phone
             FROM service_bookings b
             INNER JOIN customers c ON c.id = b.customer_id
             WHERE b.professional_id = ?
               AND b.status = 'confirmed'
               AND b.category_code IN ($placeholders)
             ORDER BY b.created_at DESC"
        );
        $stmt->execute(array_merge([$professionalId], $categoryCodes));

        return $stmt->fetchAll();
    }

    public function findOfferForProfessional(int $bookingId, int $professionalId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT b.*, c.full_name AS customer_name, c.phone_e164 AS customer_phone
             FROM service_bookings b
             INNER JOIN customers c ON c.id = b.customer_id
             WHERE b.id = ? AND b.professional_id = ? AND b.status = 'confirmed'
             LIMIT 1"
        );
        $stmt->execute([$bookingId, $professionalId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function acceptOffer(int $bookingId, int $professionalId): ?array
    {
        if ($this->hasAcceptedAtColumn()) {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET status = 'en_route', accepted_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND professional_id = ? AND status = 'confirmed'"
            );
        } else {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET status = 'en_route', updated_at = NOW()
                 WHERE id = ? AND professional_id = ? AND status = 'confirmed'"
            );
        }
        $stmt->execute([$bookingId, $professionalId]);
        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->findActiveForProfessional($professionalId, $bookingId);
    }

    /**
     * Net wallet = unpaid credits − unpaid platform fee.
     * Pros may accept while net >= wallet_min_accept_paise (default −₹200).
     */
    public function netWalletPaise(int $professionalId): int
    {
        $wallet = $this->walletBalancePaiseOnly($professionalId);
        $feeDue = $this->platformFeeDuePaise($professionalId);

        return $wallet - $feeDue;
    }

    private function walletBalancePaiseOnly(int $professionalId): int
    {
        $amount = $this->earningsAmountExpression();
        $walletExpr = $this->walletBalanceExpression($amount);
        try {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM($walletExpr), 0) AS wallet_balance_paise
                 FROM service_bookings
                 WHERE professional_id = ?
                   AND status = 'completed'
                   AND completed_at IS NOT NULL"
            );
            $stmt->execute([$professionalId]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function canProfessionalAccept(int $professionalId): bool
    {
        $settings = new PlatformSettingsRepository();
        $min = $settings->walletMinAcceptPaise();

        return $this->netWalletPaise($professionalId) >= $min;
    }

    /** Hold / release listing based on wallet overdraft limit (not free-tier alone). */
    public function syncListingHoldForWallet(int $professionalId): void
    {
        $pros = new ProRepository();
        if ($this->canProfessionalAccept($professionalId)) {
            $pros->releaseListing($professionalId);
        } else {
            $pros->holdListing($professionalId);
        }
    }

    /** @return array{ok: bool, net_paise: int, min_paise: int, message: string} */
    public function acceptWalletGate(int $professionalId): array
    {
        $settings = new PlatformSettingsRepository();
        $min = $settings->walletMinAcceptPaise();
        $net = $this->netWalletPaise($professionalId);
        $ok = $net >= $min;
        $limitRupees = abs((int) round($min / 100));

        return [
            'ok' => $ok,
            'net_paise' => $net,
            'min_paise' => $min,
            'message' => $ok
                ? 'OK'
                : sprintf(
                    'Wallet overdraft limit reached (₹%d). Pay platform fee in Wallet to accept more jobs.',
                    $limitRupees
                ),
        ];
    }

    public function rejectOffer(int $bookingId, int $professionalId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE service_bookings
             SET status = 'cancelled', updated_at = NOW()
             WHERE id = ? AND professional_id = ? AND status = 'confirmed'"
        );
        $stmt->execute([$bookingId, $professionalId]);

        return $stmt->rowCount() > 0;
    }

    public function findActiveForProfessional(int $professionalId, ?int $bookingId = null): ?array
    {
        // awaiting_payment / completed are finished from the pro side — not an active job.
        $sql = "SELECT b.*, c.full_name AS customer_name, c.phone_e164 AS customer_phone
                FROM service_bookings b
                INNER JOIN customers c ON c.id = b.customer_id
                WHERE b.professional_id = ?
                  AND b.status IN ('en_route', 'arrived', 'in_progress')";
        $params = [$professionalId];
        if ($bookingId !== null) {
            $sql .= ' AND b.id = ?';
            $params[] = $bookingId;
        }
        $sql .= ' ORDER BY b.updated_at DESC LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateActiveJobStatus(int $bookingId, int $professionalId, string $apiStatus): bool
    {
        $dbStatus = self::apiStatusToDb($apiStatus);
        $current = $this->findActiveForProfessional($professionalId, $bookingId);
        if ($current === null) {
            return false;
        }
        if ((string) $current['status'] === $dbStatus) {
            return true;
        }

        $stmt = $this->db->prepare(
            "UPDATE service_bookings
             SET status = ?, updated_at = NOW()
             WHERE id = ? AND professional_id = ?
               AND status IN ('en_route', 'arrived', 'in_progress', 'confirmed')"
        );
        $stmt->execute([$dbStatus, $bookingId, $professionalId]);

        return $stmt->rowCount() > 0;
    }

    public function completeActiveJob(int $bookingId, int $professionalId, ?int $finalAmountPaise = null): bool
    {
        // Work done → await visit-fee payment from customer before settling credit.
        if ($finalAmountPaise !== null && $finalAmountPaise >= 100 && $this->hasFinalAmountColumn()) {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET status = 'awaiting_payment', updated_at = NOW(),
                     final_amount_paise = ?
                 WHERE id = ? AND professional_id = ?
                   AND status IN ('en_route', 'arrived', 'in_progress')"
            );
            $stmt->execute([$finalAmountPaise, $bookingId, $professionalId]);
        } else {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET status = 'awaiting_payment', updated_at = NOW()
                 WHERE id = ? AND professional_id = ?
                   AND status IN ('en_route', 'arrived', 'in_progress')"
            );
            $stmt->execute([$bookingId, $professionalId]);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Customer pays visit fee after work is done → completed + settle pro credit.
     *
     * @return array<string, mixed>|null updated booking row
     */
    public function payVisitFeeForCustomer(int $bookingId, int $customerId, string $paymentMethod): ?array
    {
        $booking = $this->findByIdForCustomer($bookingId, $customerId);
        if ($booking === null) {
            return null;
        }
        if (!empty($booking['visit_fee_paid'])) {
            return $booking;
        }
        if (!in_array((string) $booking['status'], [
            'awaiting_payment',
        ], true)) {
            return null;
        }

        $method = strtolower(trim($paymentMethod));
        if (!in_array($method, ['upi', 'card', 'netbanking'], true)) {
            $method = 'upi';
        }

        if ($this->hasVisitFeePaymentColumns()) {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET visit_fee_paid = 1,
                     visit_fee_paid_at = NOW(),
                     visit_fee_payment_method = ?,
                     status = 'completed',
                     completed_at = COALESCE(completed_at, NOW()),
                     updated_at = NOW()
                 WHERE id = ? AND customer_id = ?
                   AND status = 'awaiting_payment'
                   AND COALESCE(visit_fee_paid, 0) = 0"
            );
            $stmt->execute([$method, $bookingId, $customerId]);
        } else {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET status = 'completed',
                     completed_at = COALESCE(completed_at, NOW()),
                     updated_at = NOW()
                 WHERE id = ? AND customer_id = ?
                   AND status = 'awaiting_payment'"
            );
            $stmt->execute([$bookingId, $customerId]);
        }

        if ($stmt->rowCount() === 0) {
            return $this->findByIdForCustomer($bookingId, $customerId);
        }

        $this->settleCommissionAndCredit($bookingId, (int) $booking['professional_id']);

        return $this->findByIdForCustomer($bookingId, $customerId);
    }

    /**
     * Apply visit-fee commission (or waive for free bookings) and update pro counters / hold.
     */
    public function settleCommissionAndCredit(int $bookingId, int $professionalId): void
    {
        $row = $this->findById($bookingId);
        if ($row === null || (int) $row['professional_id'] !== $professionalId) {
            return;
        }

        // Idempotent: customer + pro can both trigger complete.
        if ($this->hasCommissionColumns()
            && array_key_exists('pro_credit_paise', $row)
            && $row['pro_credit_paise'] !== null
        ) {
            return;
        }

        $settings = new PlatformSettingsRepository();
        $freeLimit = $settings->freeBookingLimit();
        $percent = $settings->visitCommissionPercent();
        $visitFee = (int) ($row['visit_fee_paise'] ?? 0);
        $final = isset($row['final_amount_paise']) && $row['final_amount_paise'] !== null
            ? (int) $row['final_amount_paise'] : null;
        // Platform fee (5% of visit) is paid by pro to company UPI — wallet gets full gross.
        $gross = ($final !== null && $final >= 100) ? $final : $visitFee;

        // Free tier is based on completed jobs; this booking may already be completed.
        $completed = $this->completedJobsCount($professionalId);
        $status = (string) ($row['status'] ?? '');
        $freeUsedBefore = $status === 'completed'
            ? max(0, $completed - 1)
            : $completed;
        $isFree = $freeUsedBefore < $freeLimit;
        $commission = 0;
        if (!$isFree && $percent > 0 && $visitFee > 0) {
            $commission = (int) round($visitFee * $percent / 100);
            $commission = min($commission, $gross);
        }
        $proCredit = max(0, $gross);

        if ($this->hasCommissionColumns()) {
            $upd = $this->db->prepare(
                'UPDATE service_bookings
                 SET commission_paise = ?, pro_credit_paise = ?, commission_waived = ?, updated_at = NOW()
                 WHERE id = ? AND pro_credit_paise IS NULL'
            );
            $upd->execute([
                $commission,
                $proCredit,
                $isFree ? 1 : 0,
                $bookingId,
            ]);
            if ($upd->rowCount() === 0) {
                return;
            }
        }

        $pros = new ProRepository();
        $pros->incrementJobsCompleted($professionalId);
        if ($isFree && $this->hasProFreeTierColumns()) {
            $pros->incrementFreeBookingsUsed($professionalId);
        }

        // Hold only when net wallet drops below configured floor (default −₹200).
        $this->syncListingHoldForWallet($professionalId);
    }

    public function completedJobsCount(int $professionalId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM service_bookings
             WHERE professional_id = ? AND status = 'completed'"
        );
        $stmt->execute([$professionalId]);

        return (int) $stmt->fetchColumn();
    }

    public function freeBookingsUsed(int $professionalId): int
    {
        if ($this->hasProFreeTierColumns()) {
            $stmt = $this->db->prepare(
                'SELECT free_bookings_used FROM professionals WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$professionalId]);
            $v = $stmt->fetchColumn();
            if ($v !== false) {
                return (int) $v;
            }
        }

        $settings = new PlatformSettingsRepository();
        $limit = $settings->freeBookingLimit();
        $completed = $this->completedJobsCount($professionalId);

        return min($completed, $limit);
    }

    /** @return array<string, mixed> */
    public function commissionMetaForProfessional(int $professionalId): array
    {
        $settings = new PlatformSettingsRepository();
        $limit = $settings->freeBookingLimit();
        $used = $this->freeBookingsUsed($professionalId);
        $remaining = max(0, $limit - $used);
        $pro = (new ProRepository())->findById($professionalId);
        $feeDue = $this->platformFeeDuePaise($professionalId);
        $upi = $settings->companyUpiId();

        return array_merge($settings->publicPayload(), [
            'free_bookings_used' => $used,
            'free_bookings_remaining' => $remaining,
            'listing_held' => (bool) ($pro['listing_held'] ?? false),
            'platform_fee_due_paise' => $feeDue,
            'wallet_net_paise' => $this->netWalletPaise($professionalId),
            'wallet_min_accept_paise' => $settings->walletMinAcceptPaise(),
            'can_accept_jobs' => $this->canProfessionalAccept($professionalId),
            'company_upi_pay_uri' => $feeDue > 0
                ? $settings->companyUpiPayUri($feeDue, 'Pro Enroll platform fee')
                : $settings->companyUpiPayUri(0, 'Pro Enroll platform fee'),
            'commission_note' => $remaining > 0
                ? sprintf(
                    'Next %d booking(s) free. After that, pay %d%% platform fee to UPI %s.',
                    $remaining,
                    $settings->visitCommissionPercent(),
                    $upi,
                )
                : sprintf(
                    'Pay platform fee (%d%% of visit) to company UPI %s via QR / UPI app.',
                    $settings->visitCommissionPercent(),
                    $upi,
                ),
        ]);
    }

    /** Unpaid platform fee (commission) for this pro — pay via company UPI. */
    public function platformFeeDuePaise(int $professionalId): int
    {
        if (!$this->hasCommissionColumns()) {
            return 0;
        }

        try {
            if ($this->hasCommissionUpiPaidColumn()) {
                $stmt = $this->db->prepare(
                    "SELECT COALESCE(SUM(commission_paise), 0)
                     FROM service_bookings
                     WHERE professional_id = ?
                       AND status = 'completed'
                       AND COALESCE(commission_waived, 0) = 0
                       AND commission_paise > 0
                       AND commission_upi_paid_at IS NULL"
                );
            } else {
                $stmt = $this->db->prepare(
                    "SELECT COALESCE(SUM(commission_paise), 0)
                     FROM service_bookings
                     WHERE professional_id = ?
                       AND status = 'completed'
                       AND COALESCE(commission_waived, 0) = 0
                       AND commission_paise > 0"
                );
            }
            $stmt->execute([$professionalId]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Mark all unpaid platform fees as paid via company UPI (requires UTR). */
    public function markPlatformFeePaidViaUpi(int $professionalId, string $utr): int
    {
        $utr = strtoupper(trim($utr));
        $utr = preg_replace('/\s+/', '', $utr) ?? '';
        if (strlen($utr) < 8 || strlen($utr) > 64) {
            throw new \InvalidArgumentException('UTR must be 8–64 characters');
        }
        if (!preg_match('/^[A-Z0-9]+$/', $utr)) {
            throw new \InvalidArgumentException('UTR must be letters and numbers only');
        }

        if (!$this->hasCommissionUpiPaidColumn()) {
            return 0;
        }

        if ($this->hasCommissionUpiUtrColumn()) {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET commission_upi_paid_at = NOW(),
                     commission_upi_utr = ?,
                     updated_at = NOW()
                 WHERE professional_id = ?
                   AND status = 'completed'
                   AND COALESCE(commission_waived, 0) = 0
                   AND commission_paise > 0
                   AND commission_upi_paid_at IS NULL"
            );
            $stmt->execute([$utr, $professionalId]);
        } else {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET commission_upi_paid_at = NOW(), updated_at = NOW()
                 WHERE professional_id = ?
                   AND status = 'completed'
                   AND COALESCE(commission_waived, 0) = 0
                   AND commission_paise > 0
                   AND commission_upi_paid_at IS NULL"
            );
            $stmt->execute([$professionalId]);
        }

        return $stmt->rowCount();
    }

    /**
     * After marking fees paid, refresh listing hold from net wallet.
     */
    public function markPlatformFeePaidViaUpiAndSync(int $professionalId, string $utr): int
    {
        $n = $this->markPlatformFeePaidViaUpi($professionalId, $utr);
        if ($n > 0) {
            $this->syncListingHoldForWallet($professionalId);
        }

        return $n;
    }

    /**
     * Credit history rows for wallet screen (newest first).
     *
     * @return list<array<string, mixed>>
     */
    public function creditHistoryForProfessional(int $professionalId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $amount = $this->earningsAmountExpression();
        $hasUtr = $this->hasCommissionUpiUtrColumn();
        $hasPaid = $this->hasCommissionUpiPaidColumn();

        $utrSelect = $hasUtr ? 'commission_upi_utr' : 'NULL AS commission_upi_utr';
        $paidSelect = $hasPaid ? 'commission_upi_paid_at' : 'NULL AS commission_upi_paid_at';

        try {
            $stmt = $this->db->prepare(
                "SELECT
                    id,
                    booking_code,
                    category_code,
                    visit_fee_paise,
                    final_amount_paise,
                    commission_paise,
                    COALESCE(commission_waived, 0) AS commission_waived,
                    ($amount) AS credit_paise,
                    $paidSelect,
                    $utrSelect,
                    completed_at,
                    created_at
                 FROM service_bookings
                 WHERE professional_id = ?
                   AND status = 'completed'
                   AND completed_at IS NOT NULL
                 ORDER BY completed_at DESC
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
            $commission = (int) ($row['commission_paise'] ?? 0);
            $waived = !empty($row['commission_waived']);
            $paidAt = $row['commission_upi_paid_at'] ?? null;
            $out[] = [
                'id' => (string) $row['id'],
                'booking_code' => (string) ($row['booking_code'] ?? ''),
                'category_code' => (string) ($row['category_code'] ?? ''),
                'visit_fee_paise' => (int) ($row['visit_fee_paise'] ?? 0),
                'final_amount_paise' => isset($row['final_amount_paise']) && $row['final_amount_paise'] !== null
                    ? (int) $row['final_amount_paise'] : null,
                'credit_paise' => (int) ($row['credit_paise'] ?? 0),
                'commission_paise' => $commission,
                'commission_waived' => $waived,
                'platform_fee_paid' => $paidAt !== null && $paidAt !== '',
                'commission_upi_utr' => $row['commission_upi_utr'] ?? null,
                'commission_upi_paid_at' => $paidAt
                    ? IstTime::format((string) $paidAt)
                    : null,
                'completed_at' => !empty($row['completed_at'])
                    ? IstTime::format((string) $row['completed_at'])
                    : null,
                'label' => $waived || $commission <= 0
                    ? 'Credit · free / no platform fee'
                    : ($paidAt
                        ? 'Credit · platform fee paid'
                        : 'Credit · platform fee due'),
            ];
        }

        return $out;
    }

    private function hasCommissionUpiPaidColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM service_bookings LIKE 'commission_upi_paid_at'");
            $cached = $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            $cached = false;
        }

        return $cached;
    }

    private function hasCommissionUpiUtrColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM service_bookings LIKE 'commission_upi_utr'");
            $cached = $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            $cached = false;
        }

        return $cached;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function offerPayload(array $row, ?float $proLat = null, ?float $proLng = null): array
    {
        $created = strtotime((string) $row['created_at']) ?: time();
        $scheduled = strtotime((string) $row['scheduled_at']) ?: $created + 3600;

        return [
            'id' => (string) $row['id'],
            'code' => $row['booking_code'],
            'category_code' => $row['category_code'],
            'problem' => $row['problem_description'],
            'customer_name' => self::customerDisplayName($row),
            'customer_area_name' => $row['address_text'],
            'distance_km' => self::distanceKm($row, $proLat, $proLng),
            'visit_fee_paise' => (int) $row['visit_fee_paise'],
            'preferred_time' => IstTime::formatTs($scheduled),
            'expires_at' => IstTime::formatTs($created + 3600),
            'created_at' => IstTime::formatTs($created),
            'commission_preview' => $this->commissionPreviewForPro((int) $row['professional_id'], (int) $row['visit_fee_paise']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commissionPreviewForPro(int $professionalId, int $visitFeePaise): array
    {
        $settings = new PlatformSettingsRepository();
        $limit = $settings->freeBookingLimit();
        $used = $this->freeBookingsUsed($professionalId);
        $remaining = max(0, $limit - $used);
        $percent = $settings->visitCommissionPercent();
        $isFree = $remaining > 0;
        $commission = $isFree ? 0 : (int) round($visitFeePaise * $percent / 100);
        $credit = max(0, $visitFeePaise); // Full visit fee to wallet; fee paid via company UPI.
        $settingsName = $settings->companyUpiId();

        return [
            'is_free_booking' => $isFree,
            'free_bookings_remaining' => $remaining,
            'visit_commission_percent' => $isFree ? 0 : $percent,
            'commission_paise' => $commission,
            'pro_credit_paise' => $credit,
            'company_upi_id' => $settingsName,
            'label' => $isFree
                ? sprintf('Free booking — full visit fee to wallet (%d left)', $remaining)
                : sprintf(
                    'Wallet +%s · Pay platform fee %s to %s',
                    '₹' . number_format($credit / 100, 0),
                    '₹' . number_format($commission / 100, 0),
                    $settingsName,
                ),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function activeJobPayload(array $row, ?float $proLat = null, ?float $proLng = null): array
    {
        return [
            'id' => (string) $row['id'],
            'code' => $row['booking_code'],
            'category_code' => $row['category_code'],
            'problem' => $row['problem_description'],
            'customer_name' => self::customerDisplayName($row),
            'customer_phone_e164' => $row['customer_phone'] ?? null,
            'customer_phone_masked' => ProRepository::maskPhone((string) ($row['customer_phone'] ?? '')),
            'customer_address' => $row['address_text'],
            'customer_area_name' => $row['address_text'],
            'distance_km' => self::distanceKm($row, $proLat, $proLng),
            'visit_fee_paise' => (int) $row['visit_fee_paise'],
            'status' => self::dbStatusToApi((string) $row['status']),
            'final_amount_paise' => isset($row['final_amount_paise']) && $row['final_amount_paise'] !== null
                ? (int) $row['final_amount_paise'] : null,
            'customer_lat' => $row['address_lat'] !== null ? (float) $row['address_lat'] : null,
            'customer_lng' => $row['address_lng'] !== null ? (float) $row['address_lng'] : null,
            'commission_preview' => $this->commissionPreviewForPro((int) $row['professional_id'], (int) $row['visit_fee_paise']),
            'pro_credit_paise' => isset($row['pro_credit_paise']) && $row['pro_credit_paise'] !== null
                ? (int) $row['pro_credit_paise'] : null,
            'commission_paise' => (int) ($row['commission_paise'] ?? 0),
            'accepted_at' => !empty($row['accepted_at'])
                ? IstTime::format((string) $row['accepted_at'])
                : null,
            'updated_at' => !empty($row['updated_at'])
                ? IstTime::format((string) $row['updated_at'])
                : null,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function customerDisplayName(array $row): string
    {
        $name = trim((string) ($row['customer_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return 'Customer';
    }

    /** @param array<string, mixed> $row */
    private static function distanceKm(array $row, ?float $proLat, ?float $proLng): float
    {
        $bookingLat = isset($row['address_lat']) && $row['address_lat'] !== null
            ? (float) $row['address_lat'] : null;
        $bookingLng = isset($row['address_lng']) && $row['address_lng'] !== null
            ? (float) $row['address_lng'] : null;

        if ($proLat !== null && $proLng !== null && $bookingLat !== null && $bookingLng !== null) {
            return ProRepository::haversineKm($proLat, $proLng, $bookingLat, $bookingLng);
        }

        $city = ReferenceData::cityById((int) $row['city_id']);
        if ($proLat !== null && $proLng !== null && $city !== null) {
            return ProRepository::haversineKm(
                $proLat,
                $proLng,
                (float) $city['latitude'],
                (float) $city['longitude'],
            );
        }

        return round(0.8 + ((int) $row['id'] % 7) * 0.35, 1);
    }

    private static function apiStatusToDb(string $api): string
    {
        return match ($api) {
            'on_the_way' => 'en_route',
            'arrived' => 'arrived',
            'in_progress' => 'in_progress',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            default => 'en_route',
        };
    }

    private static function dbStatusToApi(string $db): string
    {
        return match ($db) {
            'en_route' => 'on_the_way',
            'arrived' => 'arrived',
            'in_progress' => 'in_progress',
            // Pro finished work; show completed UI while customer pays visit fee.
            'awaiting_payment' => 'completed',
            'completed' => 'completed',
            'cancelled' => 'cancelled',
            default => 'accepted',
        };
    }

    /**
     * @return array{0: float|null, 1: float|null}
     */
    public static function parseGeoInput(mixed $lat, mixed $lng): array
    {
        if ($lat === null || $lat === '' || $lng === null || $lng === '') {
            return [null, null];
        }

        if (!is_numeric($lat) || !is_numeric($lng)) {
            throw new \InvalidArgumentException('Invalid address_lat or address_lng');
        }

        $latF = (float) $lat;
        $lngF = (float) $lng;

        if ($latF < -90 || $latF > 90 || $lngF < -180 || $lngF > 180) {
            throw new \InvalidArgumentException('address_lat or address_lng out of range');
        }

        return [round($latF, 6), round($lngF, 6)];
    }

    /**
     * Completed-job earnings for professional home tab (amount = final bill or visit fee).
     *
     * @return array<string, int>
     */
    public function earningsSummaryForProfessional(int $professionalId): array
    {
        $amount = $this->earningsAmountExpression();
        $walletExpr = $this->walletBalanceExpression($amount);

        $stmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN DATE(completed_at) = CURDATE() THEN $amount ELSE 0 END), 0) AS today_paise,
                COALESCE(SUM(CASE WHEN completed_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN $amount ELSE 0 END), 0) AS week_paise,
                COALESCE(SUM(CASE WHEN YEAR(completed_at) = YEAR(CURDATE()) AND MONTH(completed_at) = MONTH(CURDATE()) THEN $amount ELSE 0 END), 0) AS month_paise,
                COALESCE(SUM(CASE WHEN DATE(completed_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS jobs_today,
                COALESCE(SUM(CASE WHEN YEAR(completed_at) = YEAR(CURDATE()) AND MONTH(completed_at) = MONTH(CURDATE()) AND DATE(completed_at) < CURDATE() THEN $amount ELSE 0 END), 0) AS payouts_this_month_paise,
                COALESCE(SUM(CASE WHEN DATE(completed_at) = CURDATE() THEN commission_paise ELSE 0 END), 0) AS commission_today_paise,
                COALESCE(SUM($walletExpr), 0) AS wallet_balance_paise
             FROM service_bookings
             WHERE professional_id = ?
               AND status = 'completed'
               AND completed_at IS NOT NULL"
        );

        try {
            $stmt->execute([$professionalId]);
            $row = $stmt->fetch();
        } catch (\Throwable) {
            // Older schema without commission / paid_out columns.
            $stmt = $this->db->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN DATE(completed_at) = CURDATE() THEN $amount ELSE 0 END), 0) AS today_paise,
                    COALESCE(SUM(CASE WHEN completed_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN $amount ELSE 0 END), 0) AS week_paise,
                    COALESCE(SUM(CASE WHEN YEAR(completed_at) = YEAR(CURDATE()) AND MONTH(completed_at) = MONTH(CURDATE()) THEN $amount ELSE 0 END), 0) AS month_paise,
                    COALESCE(SUM(CASE WHEN DATE(completed_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS jobs_today,
                    COALESCE(SUM(CASE WHEN YEAR(completed_at) = YEAR(CURDATE()) AND MONTH(completed_at) = MONTH(CURDATE()) AND DATE(completed_at) < CURDATE() THEN $amount ELSE 0 END), 0) AS payouts_this_month_paise,
                    COALESCE(SUM($amount), 0) AS wallet_balance_paise
                 FROM service_bookings
                 WHERE professional_id = ?
                   AND status = 'completed'
                   AND completed_at IS NOT NULL"
            );
            $stmt->execute([$professionalId]);
            $row = $stmt->fetch();
        }

        if (!is_array($row)) {
            $row = [];
        }

        $today = (int) ($row['today_paise'] ?? 0);
        $wallet = (int) ($row['wallet_balance_paise'] ?? $today);
        $meta = $this->commissionMetaForProfessional($professionalId);

        return array_merge([
            'today_paise' => $today,
            'week_paise' => (int) ($row['week_paise'] ?? 0),
            'month_paise' => (int) ($row['month_paise'] ?? 0),
            'payouts_this_month_paise' => (int) ($row['payouts_this_month_paise'] ?? 0),
            'pending_payout_paise' => $wallet,
            'wallet_balance_paise' => $wallet,
            'jobs_today' => (int) ($row['jobs_today'] ?? 0),
            'commission_today_paise' => (int) ($row['commission_today_paise'] ?? 0),
        ], $meta);
    }

    /** SQL fragment: credit amount still in wallet (not paid out). */
    private function walletBalanceExpression(string $amountExpr): string
    {
        if ($this->hasPaidOutColumn()) {
            return "CASE WHEN paid_out_at IS NULL THEN ($amountExpr) ELSE 0 END";
        }

        return $amountExpr;
    }

    private function hasPaidOutColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM service_bookings LIKE 'paid_out_at'");
            $cached = $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            $cached = false;
        }

        return $cached;
    }

    private function earningsAmountExpression(): string
    {
        if ($this->hasCommissionColumns()) {
            $gross = $this->hasFinalAmountColumn()
                ? 'COALESCE(NULLIF(final_amount_paise, 0), visit_fee_paise)'
                : 'visit_fee_paise';

            return "COALESCE(pro_credit_paise, GREATEST(0, ($gross) - COALESCE(commission_paise, 0)))";
        }

        return $this->hasFinalAmountColumn()
            ? 'COALESCE(NULLIF(final_amount_paise, 0), visit_fee_paise)'
            : 'visit_fee_paise';
    }

    private function hasCommissionColumns(): bool
    {
        if (self::$hasCommissionColumns !== null) {
            return self::$hasCommissionColumns;
        }

        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM service_bookings LIKE 'pro_credit_paise'");
            self::$hasCommissionColumns = $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            self::$hasCommissionColumns = false;
        }

        return self::$hasCommissionColumns;
    }

    private function hasProFreeTierColumns(): bool
    {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM professionals LIKE 'free_bookings_used'");

            return $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasFinalAmountColumn(): bool
    {
        if (self::$hasFinalAmountColumn !== null) {
            return self::$hasFinalAmountColumn;
        }

        try {
            $stmt = $this->db->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'service_bookings'
                   AND COLUMN_NAME = 'final_amount_paise'
                 LIMIT 1"
            );
            self::$hasFinalAmountColumn = $stmt !== false && $stmt->fetch() !== false;
        } catch (\Throwable) {
            self::$hasFinalAmountColumn = false;
        }

        return self::$hasFinalAmountColumn;
    }

    private function hasAcceptedAtColumn(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM service_bookings LIKE 'accepted_at'");
            $cached = $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            $cached = false;
        }

        return $cached;
    }
}
