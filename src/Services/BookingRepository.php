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

    /** @var bool|null Cached schema probe for stuck-en-route tracking columns. */
    private static ?bool $hasProStuckTrackColumns = null;

    /** Minutes a technician may stay put after accept before customer can cancel. */
    public const STUCK_CANCEL_MINUTES = 10;

    /** Max cancellations / rejects per IST calendar day (each side). */
    public const DAILY_CANCEL_LIMIT = 5;

    /** Movement (km) that resets the stuck timer (~100 m). */
    private const STUCK_MOVE_THRESHOLD_KM = 0.1;

    /** @var bool|null Cached schema probe for cancelled_by / cancelled_at. */
    private static ?bool $hasCancelledByColumns = null;

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
     * Customer may cancel while waiting for accept (confirmed), or while en_route
     * if the technician has not meaningfully moved for STUCK_CANCEL_MINUTES.
     * Also blocked after DAILY_CANCEL_LIMIT cancels today.
     */
    public function cancelForCustomer(int $bookingId, int $customerId): bool
    {
        $row = $this->findByIdForCustomer($bookingId, $customerId);
        if ($row === null || !$this->canCustomerCancel($row)) {
            return false;
        }

        $status = (string) ($row['status'] ?? '');
        if (!in_array($status, ['confirmed', 'en_route'], true)) {
            return false;
        }

        if ($this->hasCancelledByColumns()) {
            $stmt = $this->db->prepare(
                'UPDATE service_bookings
                 SET status = ?, cancelled_by = ?, cancelled_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND customer_id = ?
                   AND status = ?'
            );
            $stmt->execute(['cancelled', 'customer', $bookingId, $customerId, $status]);
        } else {
            $stmt = $this->db->prepare(
                'UPDATE service_bookings
                 SET status = ?, updated_at = NOW()
                 WHERE id = ? AND customer_id = ?
                   AND status = ?'
            );
            $stmt->execute(['cancelled', $bookingId, $customerId, $status]);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function canCustomerCancel(array $row): bool
    {
        $customerId = (int) ($row['customer_id'] ?? 0);
        if ($customerId > 0 && $this->customerDailyCancelsRemaining($customerId) <= 0) {
            return false;
        }

        $status = (string) ($row['status'] ?? '');
        if ($status === 'confirmed') {
            return true;
        }
        if ($status !== 'en_route') {
            return false;
        }

        return $this->stuckMinutes($row) >= self::STUCK_CANCEL_MINUTES;
    }

    public function customerDailyCancelCount(int $customerId): int
    {
        return $this->dailyCancelCount('customer', $customerId);
    }

    public function customerDailyCancelsRemaining(int $customerId): int
    {
        return max(0, self::DAILY_CANCEL_LIMIT - $this->customerDailyCancelCount($customerId));
    }

    public function professionalDailyCancelCount(int $professionalId): int
    {
        return $this->dailyCancelCount('professional', $professionalId);
    }

    public function professionalDailyCancelsRemaining(int $professionalId): int
    {
        return max(0, self::DAILY_CANCEL_LIMIT - $this->professionalDailyCancelCount($professionalId));
    }

    /**
     * @param 'customer'|'professional' $by
     */
    private function dailyCancelCount(string $by, int $actorId): int
    {
        if ($actorId <= 0 || !$this->hasCancelledByColumns()) {
            return 0;
        }

        [$dayStart, $dayEnd] = $this->istDayBoundsMysql();
        if ($by === 'customer') {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM service_bookings
                 WHERE customer_id = ?
                   AND status = 'cancelled'
                   AND cancelled_by = 'customer'
                   AND cancelled_at >= ? AND cancelled_at < ?"
            );
        } else {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM service_bookings
                 WHERE professional_id = ?
                   AND status = 'cancelled'
                   AND cancelled_by = 'professional'
                   AND cancelled_at >= ? AND cancelled_at < ?"
            );
        }
        $stmt->execute([$actorId, $dayStart, $dayEnd]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{0: string, 1: string} [startMysql, endMysql) in IST */
    private function istDayBoundsMysql(): array
    {
        $tz = new \DateTimeZone(IstTime::ZONE);
        $start = new \DateTimeImmutable('today', $tz);
        $end = $start->modify('+1 day');

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    public function dailyCancelLimitMessage(string $side = 'customer'): string
    {
        return 'You can cancel or reject at most '
            . self::DAILY_CANCEL_LIMIT
            . ' times per day. Try again tomorrow.';
    }

    /**
     * Minutes since the technician last moved (or since accept if never moved).
     *
     * @param array<string, mixed> $row
     */
    public function stuckMinutes(array $row): int
    {
        $anchor = $this->stuckAnchorAt($row);
        if ($anchor === null) {
            return 0;
        }
        $seconds = max(0, time() - $anchor);

        return (int) floor($seconds / 60);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stuckAnchorAt(array $row): ?int
    {
        foreach (['pro_last_moved_at', 'accepted_at', 'updated_at'] as $key) {
            if (!empty($row[$key])) {
                $ts = strtotime((string) $row[$key]);
                if ($ts !== false) {
                    return $ts;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function customerCancelHint(array $row): ?string
    {
        $customerId = (int) ($row['customer_id'] ?? 0);
        if ($customerId > 0 && $this->customerDailyCancelsRemaining($customerId) <= 0) {
            return $this->dailyCancelLimitMessage('customer');
        }

        $status = (string) ($row['status'] ?? '');
        if ($status === 'confirmed') {
            $left = $this->customerDailyCancelsRemaining($customerId);

            return 'You can cancel until the technician starts heading your way. '
                . $left . '/' . self::DAILY_CANCEL_LIMIT . ' cancels left today.';
        }
        if ($status !== 'en_route') {
            return null;
        }
        if ($this->canCustomerCancel($row)) {
            return 'Technician has not moved for '
                . self::STUCK_CANCEL_MINUTES
                . '+ minutes. You can cancel and book another technician.';
        }
        $left = max(0, self::STUCK_CANCEL_MINUTES - $this->stuckMinutes($row));

        return 'If the technician stays in the same place for '
            . self::STUCK_CANCEL_MINUTES
            . ' minutes, you can cancel. Available in about '
            . $left
            . ' min.';
    }

    /**
     * @param array<string, mixed> $row
     */
    public function customerCancelUnlockAt(array $row): ?string
    {
        $customerId = (int) ($row['customer_id'] ?? 0);
        if ($customerId > 0 && $this->customerDailyCancelsRemaining($customerId) <= 0) {
            return null;
        }

        $status = (string) ($row['status'] ?? '');
        if ($status !== 'en_route' || $this->canCustomerCancel($row)) {
            return null;
        }
        $anchor = $this->stuckAnchorAt($row);
        if ($anchor === null) {
            return null;
        }

        return IstTime::formatTs($anchor + (self::STUCK_CANCEL_MINUTES * 60));
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
        // Completion is only via visit-fee payment (or auto-timeout). No separate customer Complete.
        return false;
    }

    /**
     * Auto-cancel confirmed offers the pro did not accept before scheduled_at
     * (default booking window ≈ 1 hour). Notifies each customer once.
     *
     * @return int number of bookings cancelled
     */
    public function expireStaleConfirmedOffers(): int
    {
        $stmt = $this->db->query(
            "SELECT b.*, c.full_name AS customer_name, c.phone_e164 AS customer_phone
             FROM service_bookings b
             INNER JOIN customers c ON c.id = b.customer_id
             WHERE b.status = 'confirmed'
               AND COALESCE(b.scheduled_at, DATE_ADD(b.created_at, INTERVAL 1 HOUR)) < NOW()
             ORDER BY COALESCE(b.scheduled_at, b.created_at) ASC
             LIMIT 100"
        );
        $rows = $stmt !== false ? $stmt->fetchAll() : [];
        if ($rows === false || $rows === []) {
            return 0;
        }

        $reason = 'Offer expired — professional did not accept in time';
        $done = 0;
        foreach ($rows as $row) {
            $bookingId = (int) $row['id'];
            if ($this->hasCancelReasonColumn() && $this->hasCancelledByColumns()) {
                // Leave cancelled_by NULL so this does not count against daily cancel limits.
                $upd = $this->db->prepare(
                    "UPDATE service_bookings
                     SET status = 'cancelled',
                         cancel_reason = ?,
                         cancelled_by = NULL,
                         cancelled_at = NOW(),
                         updated_at = NOW()
                     WHERE id = ? AND status = 'confirmed'"
                );
                $upd->execute([$reason, $bookingId]);
            } elseif ($this->hasCancelReasonColumn()) {
                $upd = $this->db->prepare(
                    "UPDATE service_bookings
                     SET status = 'cancelled',
                         cancel_reason = ?,
                         updated_at = NOW()
                     WHERE id = ? AND status = 'confirmed'"
                );
                $upd->execute([$reason, $bookingId]);
            } elseif ($this->hasCancelledByColumns()) {
                $upd = $this->db->prepare(
                    "UPDATE service_bookings
                     SET status = 'cancelled',
                         cancelled_by = NULL,
                         cancelled_at = NOW(),
                         updated_at = NOW()
                     WHERE id = ? AND status = 'confirmed'"
                );
                $upd->execute([$bookingId]);
            } else {
                $upd = $this->db->prepare(
                    "UPDATE service_bookings
                     SET status = 'cancelled', updated_at = NOW()
                     WHERE id = ? AND status = 'confirmed'"
                );
                $upd->execute([$bookingId]);
            }

            if ($upd->rowCount() === 0) {
                continue;
            }
            $done++;
            $row['status'] = 'cancelled';
            $row['cancel_reason'] = $reason;
            BookingPushNotifier::offerExpiredForCustomer($row);
        }

        return $done;
    }

    /** @param array<string, mixed> $row */
    public function isOfferExpired(array $row): bool
    {
        $scheduled = strtotime((string) ($row['scheduled_at'] ?? ''));
        if ($scheduled === false || $scheduled <= 0) {
            $created = strtotime((string) ($row['created_at'] ?? '')) ?: time();
            $scheduled = $created + 3600;
        }

        return $scheduled < time();
    }

    /**
     * Auto-complete jobs stuck in awaiting_payment after the configured hours.
     * Treats unpaid visit fee as settled offline (method = timeout) and settles wallet.
     *
     * @return int number of bookings completed
     */
    public function autoCompleteStaleAwaitingPayments(?int $hours = null): int
    {
        $settings = new PlatformSettingsRepository();
        $hours = $hours ?? $settings->awaitingPaymentAutoCompleteHours();
        if ($hours <= 0) {
            return 0;
        }

        $stmt = $this->db->prepare(
            "SELECT id, professional_id, customer_id
             FROM service_bookings
             WHERE status = 'awaiting_payment'
               AND updated_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY updated_at ASC
             LIMIT 100"
        );
        $stmt->execute([$hours]);
        $rows = $stmt->fetchAll();
        if ($rows === false || $rows === []) {
            return 0;
        }

        $done = 0;
        foreach ($rows as $row) {
            $bookingId = (int) $row['id'];
            $professionalId = (int) $row['professional_id'];
            if ($this->hasVisitFeePaymentColumns()) {
                $upd = $this->db->prepare(
                    "UPDATE service_bookings
                     SET visit_fee_paid = 1,
                         visit_fee_paid_at = COALESCE(visit_fee_paid_at, NOW()),
                         visit_fee_payment_method = COALESCE(NULLIF(visit_fee_payment_method, ''), 'timeout'),
                         status = 'completed',
                         completed_at = COALESCE(completed_at, NOW()),
                         updated_at = NOW()
                     WHERE id = ?
                       AND status = 'awaiting_payment'"
                );
                $upd->execute([$bookingId]);
            } else {
                $upd = $this->db->prepare(
                    "UPDATE service_bookings
                     SET status = 'completed',
                         completed_at = COALESCE(completed_at, NOW()),
                         updated_at = NOW()
                     WHERE id = ?
                       AND status = 'awaiting_payment'"
                );
                $upd->execute([$bookingId]);
            }
            if ($upd->rowCount() === 0) {
                continue;
            }
            $this->settleCommissionAndCredit($bookingId, $professionalId);
            $done++;
        }

        return $done;
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
            'can_cancel' => $this->canCustomerCancel($row),
            'cancel_hint' => $this->customerCancelHint($row),
            'cancel_unlock_at' => $this->customerCancelUnlockAt($row),
            'cancels_remaining_today' => $this->customerDailyCancelsRemaining((int) ($row['customer_id'] ?? 0)),
            'daily_cancel_limit' => self::DAILY_CANCEL_LIMIT,
            // Pay visit fee = customer confirmation; no separate Complete action.
            'can_mark_completed' => false,
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

        if (!in_array((string) $active['status'], ['en_route', 'arrived'], true)) {
            return false;
        }

        $pros = new ProRepository();
        if (!$pros->updateLastLocation($professionalId, $lat, $lng)) {
            return false;
        }

        if ($this->hasProStuckTrackColumns() && (string) $active['status'] === 'en_route') {
            $prevLat = isset($active['pro_track_lat']) && $active['pro_track_lat'] !== null
                ? (float) $active['pro_track_lat'] : null;
            $prevLng = isset($active['pro_track_lng']) && $active['pro_track_lng'] !== null
                ? (float) $active['pro_track_lng'] : null;

            if ($prevLat === null || $prevLng === null) {
                // First fix: store baseline without resetting the accept timer.
                $this->db->prepare(
                    'UPDATE service_bookings
                     SET pro_track_lat = ?, pro_track_lng = ?, updated_at = NOW()
                     WHERE id = ? AND professional_id = ?'
                )->execute([$lat, $lng, $bookingId, $professionalId]);
            } elseif (ProRepository::haversineKm($prevLat, $prevLng, $lat, $lng) >= self::STUCK_MOVE_THRESHOLD_KM) {
                $this->db->prepare(
                    'UPDATE service_bookings
                     SET pro_track_lat = ?, pro_track_lng = ?, pro_last_moved_at = NOW(), updated_at = NOW()
                     WHERE id = ? AND professional_id = ?'
                )->execute([$lat, $lng, $bookingId, $professionalId]);
            } else {
                $this->db->prepare(
                    'UPDATE service_bookings SET updated_at = NOW() WHERE id = ? AND professional_id = ?'
                )->execute([$bookingId, $professionalId]);
            }
        } else {
            $this->db->prepare(
                'UPDATE service_bookings SET updated_at = NOW() WHERE id = ? AND professional_id = ?'
            )->execute([$bookingId, $professionalId]);
        }

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
            'awaiting_payment' => 'Confirm & pay visit fee',
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
            ['key' => 'awaiting_payment', 'label' => 'Confirm & pay'],
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

        $this->expireStaleConfirmedOffers();

        $placeholders = implode(',', array_fill(0, count($categoryCodes), '?'));
        $stmt = $this->db->prepare(
            "SELECT b.*, c.full_name AS customer_name, c.phone_e164 AS customer_phone
             FROM service_bookings b
             INNER JOIN customers c ON c.id = b.customer_id
             WHERE b.professional_id = ?
               AND b.status = 'confirmed'
               AND b.category_code IN ($placeholders)
               AND COALESCE(b.scheduled_at, DATE_ADD(b.created_at, INTERVAL 1 HOUR)) >= NOW()
             ORDER BY b.created_at DESC"
        );
        $stmt->execute(array_merge([$professionalId], $categoryCodes));

        return $stmt->fetchAll();
    }

    /**
     * Recent bookings for this professional (all statuses) for Jobs tab history.
     *
     * @return list<array<string, mixed>>
     */
    public function listHistoryForProfessional(int $professionalId, int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            "SELECT b.*, c.full_name AS customer_name, c.phone_e164 AS customer_phone,
                    r.stars AS rating_stars, r.review_text AS rating_review
             FROM service_bookings b
             INNER JOIN customers c ON c.id = b.customer_id
             LEFT JOIN booking_ratings r ON r.booking_id = b.id
             WHERE b.professional_id = ?
             ORDER BY COALESCE(b.completed_at, b.updated_at, b.created_at) DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $professionalId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function historyPayload(array $row): array
    {
        $dbStatus = (string) ($row['status'] ?? '');

        $phone = (string) ($row['customer_phone'] ?? '');
        // Contact details only while the job is live; hide once it is closed.
        $isLive = in_array($dbStatus, [
            'confirmed', 'en_route', 'arrived', 'in_progress', 'awaiting_payment',
        ], true);

        return [
            'id' => (string) $row['id'],
            'code' => $row['booking_code'],
            'category_code' => $row['category_code'],
            'category_name' => $this->categoryName((string) $row['category_code']),
            'problem' => $row['problem_description'],
            'customer_name' => self::customerDisplayName($row),
            'customer_area_name' => (string) ($row['address_text'] ?? ''),
            'customer_phone_e164' => $isLive && $phone !== '' ? $phone : null,
            'customer_phone_masked' => $phone !== '' ? ProRepository::maskPhone($phone) : null,
            'visit_fee_paise' => (int) ($row['visit_fee_paise'] ?? 0),
            'pro_credit_paise' => isset($row['pro_credit_paise']) && $row['pro_credit_paise'] !== null
                ? (int) $row['pro_credit_paise'] : null,
            'commission_paise' => isset($row['commission_paise']) && $row['commission_paise'] !== null
                ? (int) $row['commission_paise'] : null,
            'commission_waived' => !empty($row['commission_waived']),
            'status' => $dbStatus,
            'status_label' => self::proHistoryStatusLabel($dbStatus),
            'visit_fee_paid' => !empty($row['visit_fee_paid']),
            'visit_fee_payment_method' => $row['visit_fee_payment_method'] ?? null,
            'rating_stars' => isset($row['rating_stars']) && $row['rating_stars'] !== null
                ? (int) $row['rating_stars'] : null,
            'rating_review' => $row['rating_review'] ?? null,
            'created_at' => !empty($row['created_at'])
                ? IstTime::format((string) $row['created_at'])
                : null,
            'completed_at' => !empty($row['completed_at'])
                ? IstTime::format((string) $row['completed_at'])
                : null,
            'updated_at' => !empty($row['updated_at'])
                ? IstTime::format((string) $row['updated_at'])
                : null,
        ];
    }

    private static function proHistoryStatusLabel(string $db): string
    {
        return match ($db) {
            'confirmed' => 'New offer',
            'en_route' => 'On the way',
            'arrived' => 'Arrived',
            'in_progress' => 'In progress',
            'awaiting_payment' => 'Awaiting payment',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => ucwords(str_replace('_', ' ', $db)),
        };
    }

    public function findOfferForProfessional(int $bookingId, int $professionalId): ?array
    {
        $this->expireStaleConfirmedOffers();

        $stmt = $this->db->prepare(
            "SELECT b.*, c.full_name AS customer_name, c.phone_e164 AS customer_phone
             FROM service_bookings b
             INNER JOIN customers c ON c.id = b.customer_id
             WHERE b.id = ? AND b.professional_id = ? AND b.status = 'confirmed'
             LIMIT 1"
        );
        $stmt->execute([$bookingId, $professionalId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if ($this->isOfferExpired($row)) {
            $this->expireStaleConfirmedOffers();
            return null;
        }

        return $row;
    }

    public function acceptOffer(int $bookingId, int $professionalId): ?array
    {
        $offer = $this->findOfferForProfessional($bookingId, $professionalId);
        if ($offer === null) {
            return null;
        }
        if ($this->isOfferExpired($offer)) {
            $this->expireStaleConfirmedOffers();
            return null;
        }

        $trackLat = null;
        $trackLng = null;
        $pros = new ProRepository();
        $pro = $pros->findById($professionalId);
        if ($pro !== null) {
            [$trackLat, $trackLng] = ProRepository::resolveCoords($pro, 3600);
        }

        $sets = ["status = 'en_route'", 'updated_at = NOW()'];
        $params = [];
        if ($this->hasAcceptedAtColumn()) {
            $sets[] = 'accepted_at = NOW()';
        }
        if ($this->hasProStuckTrackColumns()) {
            $sets[] = 'pro_last_moved_at = NOW()';
            $sets[] = 'pro_track_lat = ?';
            $sets[] = 'pro_track_lng = ?';
            $params[] = $trackLat;
            $params[] = $trackLng;
        }
        $sql = 'UPDATE service_bookings SET ' . implode(', ', $sets)
            . ' WHERE id = ? AND professional_id = ? AND status = \'confirmed\''
            . ' AND COALESCE(scheduled_at, DATE_ADD(created_at, INTERVAL 1 HOUR)) >= NOW()';
        $params[] = $bookingId;
        $params[] = $professionalId;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            $this->expireStaleConfirmedOffers();
            return null;
        }

        return $this->findActiveForProfessional($professionalId, $bookingId);
    }

    /**
     * Prepaid wallet balance (UPI recharges − platform fee debits).
     * Falls back to legacy net (job credits − unpaid fee) if ledger missing.
     */
    public function netWalletPaise(int $professionalId): int
    {
        $ledger = new WalletLedgerRepository();
        if ($ledger->tableExists()) {
            return $ledger->balancePaise($professionalId);
        }

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

    public function canProfessionalAccept(int $professionalId, ?int $visitFeePaise = null): bool
    {
        return $this->acceptWalletGate($professionalId, $visitFeePaise)['ok'];
    }

    /** Hold / release listing based on prepaid wallet floor (after free tier). */
    public function syncListingHoldForWallet(int $professionalId): void
    {
        $pros = new ProRepository();
        if ($this->canProfessionalAccept($professionalId)) {
            $pros->releaseListing($professionalId);
        } else {
            $pros->holdListing($professionalId);
        }
    }

    /**
     * First N jobs are free. After that, prepaid wallet must stay ≥ min (₹50)
     * and cover this offer's platform fee when visit fee is known.
     *
     * @return array{ok: bool, net_paise: int, min_paise: int, message: string}
     */
    public function acceptWalletGate(int $professionalId, ?int $visitFeePaise = null): array
    {
        $settings = new PlatformSettingsRepository();
        $min = $settings->walletMinAcceptPaise();
        $balance = $this->netWalletPaise($professionalId);
        $used = $this->freeBookingsUsed($professionalId);
        $remaining = max(0, $settings->freeBookingLimit() - $used);

        if ($remaining > 0) {
            return [
                'ok' => true,
                'net_paise' => $balance,
                'min_paise' => $min,
                'message' => 'OK',
            ];
        }

        $needed = $min;
        if ($visitFeePaise !== null && $visitFeePaise > 0) {
            $commission = (int) round($visitFeePaise * $settings->visitCommissionPercent() / 100);
            $needed = max($min, $commission);
        }

        $ok = $balance >= $needed;
        $neededRupees = (int) round($needed / 100);

        return [
            'ok' => $ok,
            'net_paise' => $balance,
            'min_paise' => $needed,
            'message' => $ok
                ? 'OK'
                : sprintf(
                    'Wallet balance too low. Recharge at least ₹%d via company UPI to accept jobs (min ₹%d).',
                    $neededRupees,
                    (int) round($min / 100),
                ),
        ];
    }

    /**
     * Pro may reject an open offer anytime, or cancel an accepted job while
     * still on the way. Once arrived / working, cancelling is no longer allowed.
     */
    public function rejectOffer(int $bookingId, int $professionalId): bool
    {
        return $this->cancelByProfessional($bookingId, $professionalId);
    }

    public function cancelByProfessional(int $bookingId, int $professionalId, ?string $reason = null): bool
    {
        $booking = $this->findById($bookingId);
        if ($booking === null || (int) $booking['professional_id'] !== $professionalId) {
            return false;
        }

        if ($this->professionalDailyCancelsRemaining($professionalId) <= 0) {
            return false;
        }

        $status = (string) ($booking['status'] ?? '');
        if (!self::isProfessionalCancellableStatus($status)) {
            return false;
        }

        $isLateReject = $status === 'en_route';
        $reason = $reason !== null ? trim($reason) : null;
        if ($reason === '') {
            $reason = null;
        }

        if ($this->hasCancelReasonColumn() && $this->hasCancelledByColumns()) {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET status = 'cancelled', cancel_reason = ?, cancelled_by = 'professional',
                     cancelled_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND professional_id = ?
                   AND status IN ('confirmed', 'en_route', 'arrived')"
            );
            $stmt->execute([$reason, $bookingId, $professionalId]);
        } elseif ($this->hasCancelledByColumns()) {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET status = 'cancelled', cancelled_by = 'professional',
                     cancelled_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND professional_id = ?
                   AND status IN ('confirmed', 'en_route', 'arrived')"
            );
            $stmt->execute([$bookingId, $professionalId]);
        } elseif ($this->hasCancelReasonColumn()) {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET status = 'cancelled', cancel_reason = ?, updated_at = NOW()
                 WHERE id = ? AND professional_id = ?
                   AND status IN ('confirmed', 'en_route', 'arrived')"
            );
            $stmt->execute([$reason, $bookingId, $professionalId]);
        } else {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET status = 'cancelled', updated_at = NOW()
                 WHERE id = ? AND professional_id = ?
                   AND status IN ('confirmed', 'en_route', 'arrived')"
            );
            $stmt->execute([$bookingId, $professionalId]);
        }

        if ($stmt->rowCount() === 0) {
            return false;
        }

        // Charge the wallet penalty for rejecting after heading out.
        if ($isLateReject) {
            $penalty = $this->lateRejectPenaltyPaise($booking);
            if ($penalty > 0) {
                $ledger = new WalletLedgerRepository();
                if ($ledger->tableExists()) {
                    try {
                        $ledger->debitLateRejectPenalty($professionalId, $bookingId, $penalty);
                        $this->syncListingHoldForWallet($professionalId);
                    } catch (\Throwable) {
                        // Penalty is best-effort; low balance is caught by listing hold.
                    }
                }
            }
        }

        return true;
    }

    /** Whether the pro can still cancel: open offer, or on the way (and under daily limit). */
    public function canProfessionalCancel(array $row): bool
    {
        $proId = (int) ($row['professional_id'] ?? 0);
        if ($proId > 0 && $this->professionalDailyCancelsRemaining($proId) <= 0) {
            return false;
        }

        return self::isProfessionalCancellableStatus((string) ($row['status'] ?? ''));
    }

    private static function isProfessionalCancellableStatus(string $status): bool
    {
        // Allowed until work starts (in_progress).
        return in_array($status, ['confirmed', 'en_route', 'arrived'], true);
    }

    /** Late reject (en_route) requires a reason from the professional. */
    public static function rejectRequiresReason(string $status): bool
    {
        return $status === 'en_route';
    }

    /**
     * Wallet penalty (in paise) for rejecting this booking now.
     * Only applies while on the way (en_route) and when the toggle is on.
     *
     * @param array<string, mixed> $row
     */
    public function lateRejectPenaltyPaise(array $row): int
    {
        if ((string) ($row['status'] ?? '') !== 'en_route') {
            return 0;
        }

        $settings = new PlatformSettingsRepository();
        if (!$settings->lateRejectPenaltyEnabled()) {
            return 0;
        }

        $percent = $settings->visitCommissionPercent();
        $visitFee = (int) ($row['visit_fee_paise'] ?? 0);
        if ($percent <= 0 || $visitFee <= 0) {
            return 0;
        }

        return (int) round($visitFee * $percent / 100);
    }

    private ?bool $hasCancelReason = null;

    private function hasCancelReasonColumn(): bool
    {
        if ($this->hasCancelReason !== null) {
            return $this->hasCancelReason;
        }

        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM service_bookings LIKE 'cancel_reason'");
            $this->hasCancelReason = $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            $this->hasCancelReason = false;
        }

        return $this->hasCancelReason;
    }

    public function findActiveForProfessional(int $professionalId, ?int $bookingId = null): ?array
    {
        // Include awaiting_payment so pro can confirm cash / offline payment received.
        // Prefer the job already in progress (not the newest accept) so accepting
        // another offer cannot steal the active slot from the current job.
        $sql = "SELECT b.*, c.full_name AS customer_name, c.phone_e164 AS customer_phone
                FROM service_bookings b
                INNER JOIN customers c ON c.id = b.customer_id
                WHERE b.professional_id = ?
                  AND b.status IN ('en_route', 'arrived', 'in_progress', 'awaiting_payment')";
        $params = [$professionalId];
        if ($bookingId !== null) {
            $sql .= ' AND b.id = ?';
            $params[] = $bookingId;
        }
        $acceptedOrder = $this->hasAcceptedAtColumn()
            ? 'COALESCE(b.accepted_at, b.updated_at, b.created_at)'
            : 'COALESCE(b.updated_at, b.created_at)';
        $sql .= " ORDER BY
                    CASE b.status
                        WHEN 'in_progress' THEN 1
                        WHEN 'arrived' THEN 2
                        WHEN 'awaiting_payment' THEN 3
                        WHEN 'en_route' THEN 4
                        ELSE 5
                    END ASC,
                    $acceptedOrder ASC,
                    b.id ASC
                  LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Another live job that must be finished/cancelled before [bookingId] can
     * advance (on the way / arrived / start work). Accept remains allowed.
     *
     * @return array<string, mixed>|null
     */
    public function findBlockingJobForAdvance(int $professionalId, int $bookingId): ?array
    {
        $primary = $this->findActiveForProfessional($professionalId);
        if ($primary === null) {
            return null;
        }
        // This booking is the current job — allow next steps.
        if ((int) ($primary['id'] ?? 0) === $bookingId) {
            return null;
        }

        // A different job is still open — block advancing this queued accept.
        return $primary;
    }

    /** True when the pro already has a live job other than [excludeBookingId]. */
    public function hasOtherActiveJob(int $professionalId, ?int $excludeBookingId = null): bool
    {
        $sql = "SELECT 1
                FROM service_bookings
                WHERE professional_id = ?
                  AND status IN ('en_route', 'arrived', 'in_progress', 'awaiting_payment')";
        $params = [$professionalId];
        if ($excludeBookingId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeBookingId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Pro confirms visit fee received offline (cash / UPI outside app) → completed + settle.
     *
     * @return array<string, mixed>|null updated booking row
     */
    public function confirmPaymentReceivedForProfessional(
        int $bookingId,
        int $professionalId,
        string $paymentMethod = 'cash',
    ): ?array {
        $booking = $this->findById($bookingId);
        if ($booking === null || (int) $booking['professional_id'] !== $professionalId) {
            return null;
        }
        if ((string) ($booking['status'] ?? '') !== 'awaiting_payment') {
            return null;
        }

        $method = strtolower(trim($paymentMethod));
        if (!in_array($method, ['cash', 'upi', 'card', 'offline'], true)) {
            $method = 'cash';
        }

        if ($this->hasVisitFeePaymentColumns()) {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET visit_fee_paid = 1,
                     visit_fee_paid_at = COALESCE(visit_fee_paid_at, NOW()),
                     visit_fee_payment_method = ?,
                     status = 'completed',
                     completed_at = COALESCE(completed_at, NOW()),
                     updated_at = NOW()
                 WHERE id = ? AND professional_id = ?
                   AND status = 'awaiting_payment'"
            );
            $stmt->execute([$method, $bookingId, $professionalId]);
        } else {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET status = 'completed',
                     completed_at = COALESCE(completed_at, NOW()),
                     updated_at = NOW()
                 WHERE id = ? AND professional_id = ?
                   AND status = 'awaiting_payment'"
            );
            $stmt->execute([$bookingId, $professionalId]);
        }

        if ($stmt->rowCount() === 0) {
            return $this->findById($bookingId);
        }

        $this->settleCommissionAndCredit($bookingId, $professionalId);

        return $this->findById($bookingId);
    }

    public function updateActiveJobStatus(int $bookingId, int $professionalId, string $apiStatus): bool
    {
        $dbStatus = self::apiStatusToDb($apiStatus);
        $current = $this->findActiveForProfessional($professionalId, $bookingId);
        if ($current === null) {
            return false;
        }
        $from = (string) ($current['status'] ?? '');
        if ($from === $dbStatus) {
            return true;
        }

        // Strict one-step transitions — stops double-tap from skipping stages.
        $allowedNext = match ($from) {
            'confirmed' => ['en_route'],
            'en_route' => ['arrived'],
            'arrived' => ['in_progress'],
            default => [],
        };
        if (!in_array($dbStatus, $allowedNext, true)) {
            return false;
        }

        // Accept while busy is allowed; advancing a queued job is not.
        if ($this->findBlockingJobForAdvance($professionalId, $bookingId) !== null) {
            return false;
        }

        // Start work requires customer OTP verification + wallet charge.
        if ($dbStatus === 'in_progress' && !$this->isStartWorkVerified($bookingId)) {
            return false;
        }

        $stmt = $this->db->prepare(
            "UPDATE service_bookings
             SET status = ?, updated_at = NOW()
             WHERE id = ? AND professional_id = ?
               AND status = ?"
        );
        $stmt->execute([$dbStatus, $bookingId, $professionalId, $from]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Send 6-digit OTP to the customer so the pro can start work after arrival.
     *
     * @return array{request_id: string, expires_in: int, phone_masked: string, commission_paise: int, debug_otp?: string}
     */
    public function sendStartWorkOtp(int $bookingId, int $professionalId): array
    {
        $row = $this->findActiveForProfessional($professionalId, $bookingId);
        if ($row === null || (string) ($row['status'] ?? '') !== 'arrived') {
            throw new \InvalidArgumentException('Mark arrived before requesting start OTP');
        }

        $phone = trim((string) ($row['customer_phone'] ?? ''));
        if ($phone === '') {
            throw new \InvalidArgumentException('Customer phone not available for OTP');
        }

        $this->ensureStartOtpTable();

        // Invalidate previous unused OTPs for this booking.
        $this->db->prepare(
            'UPDATE booking_start_otps
             SET verified_at = COALESCE(verified_at, NOW())
             WHERE booking_id = ? AND verified_at IS NULL'
        )->execute([$bookingId]);

        $otp = (string) random_int(100000, 999999);
        // Dev convenience — same as auth OTP when debug is on.
        if (\ProEnroll\Api\Config::bool('APP_DEBUG', false)
            || \ProEnroll\Api\Config::bool('OTP_DEBUG_RETURN', false)
        ) {
            $otp = '123456';
        }

        $requestId = bin2hex(random_bytes(16));
        $ttl = (int) \ProEnroll\Api\Config::get('OTP_EXPIRY_SECONDS', '600');

        $stmt = $this->db->prepare(
            'INSERT INTO booking_start_otps
                (booking_id, request_id, phone_e164, otp_code, expires_at, created_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())'
        );
        $stmt->execute([$bookingId, $requestId, $phone, $otp, $ttl]);

        $preview = $this->commissionPreviewForPro(
            $professionalId,
            (int) ($row['visit_fee_paise'] ?? 0),
        );

        $out = [
            'request_id' => $requestId,
            'expires_in' => $ttl,
            'phone_masked' => ProRepository::maskPhone($phone),
            'commission_paise' => (int) ($preview['commission_paise'] ?? 0),
            'commission_label' => (string) ($preview['label'] ?? ''),
            // Used server-side for customer push; stripped before API response.
            'notify_otp' => $otp,
        ];
        if (\ProEnroll\Api\Config::bool('OTP_DEBUG_RETURN', false)
            || \ProEnroll\Api\Config::bool('APP_DEBUG', false)
        ) {
            $out['debug_otp'] = $otp;
        }

        return $out;
    }

    /**
     * Verify customer OTP, charge platform fee from pro wallet, then start work.
     *
     * @return array<string, mixed> updated booking row
     */
    public function verifyStartWorkOtpAndBegin(
        int $bookingId,
        int $professionalId,
        string $requestId,
        string $otp,
    ): array {
        $row = $this->findActiveForProfessional($professionalId, $bookingId);
        if ($row === null || (string) ($row['status'] ?? '') !== 'arrived') {
            throw new \InvalidArgumentException('Job must be arrived to start work');
        }

        // Resume if OTP + wallet already succeeded but status update failed.
        if ($this->isStartWorkVerified($bookingId)) {
            $charge = $this->chargePlatformFeeAtStart($bookingId, $professionalId);
            if (!$charge['ok']) {
                throw new \RuntimeException($charge['message']);
            }
            if (!$this->updateActiveJobStatus($bookingId, $professionalId, 'in_progress')) {
                $upd = $this->db->prepare(
                    "UPDATE service_bookings
                     SET status = 'in_progress', updated_at = NOW()
                     WHERE id = ? AND professional_id = ? AND status = 'arrived'"
                );
                $upd->execute([$bookingId, $professionalId]);
            }
            $updated = $this->findActiveForProfessional($professionalId, $bookingId)
                ?? $this->findById($bookingId);
            if ($updated === null) {
                throw new \RuntimeException('Job not found after start');
            }

            return $updated;
        }

        $this->ensureStartOtpTable();
        $stmt = $this->db->prepare(
            'SELECT id, phone_e164, otp_code, expires_at, verified_at
             FROM booking_start_otps
             WHERE request_id = ? AND booking_id = ?
             LIMIT 1'
        );
        $stmt->execute([trim($requestId), $bookingId]);
        $otpRow = $stmt->fetch();
        if ($otpRow === false) {
            throw new \InvalidArgumentException('Invalid or expired OTP request');
        }
        if ($otpRow['verified_at'] !== null) {
            throw new \InvalidArgumentException('OTP already used — request a new one');
        }
        if (strtotime((string) $otpRow['expires_at']) < time()) {
            throw new \InvalidArgumentException('OTP expired — request a new one');
        }

        $this->db->prepare(
            'UPDATE booking_start_otps SET attempt_count = attempt_count + 1 WHERE id = ?'
        )->execute([(int) $otpRow['id']]);

        if (!hash_equals((string) $otpRow['otp_code'], trim($otp))) {
            throw new \InvalidArgumentException('Incorrect OTP');
        }

        // Charge first; only then mark OTP verified.
        $charge = $this->chargePlatformFeeAtStart($bookingId, $professionalId);
        if (!$charge['ok']) {
            throw new \RuntimeException($charge['message']);
        }

        $this->db->prepare(
            'UPDATE booking_start_otps SET verified_at = NOW() WHERE id = ?'
        )->execute([(int) $otpRow['id']]);

        if ($this->hasStartWorkVerifiedColumn()) {
            $this->db->prepare(
                'UPDATE service_bookings
                 SET start_work_verified_at = COALESCE(start_work_verified_at, NOW()),
                     updated_at = NOW()
                 WHERE id = ? AND professional_id = ?'
            )->execute([$bookingId, $professionalId]);
        }

        if (!$this->updateActiveJobStatus($bookingId, $professionalId, 'in_progress')) {
            $upd = $this->db->prepare(
                "UPDATE service_bookings
                 SET status = 'in_progress', updated_at = NOW()
                 WHERE id = ? AND professional_id = ? AND status = 'arrived'"
            );
            $upd->execute([$bookingId, $professionalId]);
            if ($upd->rowCount() === 0) {
                throw new \RuntimeException('Could not start work after OTP');
            }
        }

        $updated = $this->findActiveForProfessional($professionalId, $bookingId)
            ?? $this->findById($bookingId);
        if ($updated === null) {
            throw new \RuntimeException('Job not found after start');
        }

        return $updated;
    }

    /**
     * Debit visit-fee commission from prepaid wallet when work starts (after OTP).
     * Idempotent via wallet ledger. Does not settle pro_credit / jobs_completed
     * (those still run at job completion).
     *
     * @return array{ok: bool, commission_paise: int, message: string}
     */
    public function chargePlatformFeeAtStart(int $bookingId, int $professionalId): array
    {
        $row = $this->findById($bookingId);
        if ($row === null || (int) $row['professional_id'] !== $professionalId) {
            return ['ok' => false, 'commission_paise' => 0, 'message' => 'Booking not found'];
        }

        $settings = new PlatformSettingsRepository();
        $freeLimit = $settings->freeBookingLimit();
        $percent = $settings->visitCommissionPercent();
        $visitFee = (int) ($row['visit_fee_paise'] ?? 0);
        $completed = $this->completedJobsCount($professionalId);
        $isFree = $completed < $freeLimit;
        $commission = 0;
        if (!$isFree && $percent > 0 && $visitFee > 0) {
            $commission = (int) round($visitFee * $percent / 100);
        }

        if ($commission <= 0) {
            if ($this->hasCommissionColumns()) {
                $this->db->prepare(
                    'UPDATE service_bookings
                     SET commission_paise = 0, commission_waived = 1, updated_at = NOW()
                     WHERE id = ? AND pro_credit_paise IS NULL'
                )->execute([$bookingId]);
            }

            return [
                'ok' => true,
                'commission_paise' => 0,
                'message' => 'Free booking — no wallet deduction',
            ];
        }

        $balance = $this->netWalletPaise($professionalId);
        $ledger = new WalletLedgerRepository();
        if ($ledger->tableExists()) {
            $exists = $this->db->prepare(
                "SELECT id FROM pro_wallet_ledger
                 WHERE booking_id = ? AND entry_type = 'commission_debit' LIMIT 1"
            );
            $exists->execute([$bookingId]);
            if ($exists->fetch()) {
                $this->markBookingCommissionPaidFromWallet($bookingId);

                return [
                    'ok' => true,
                    'commission_paise' => $commission,
                    'message' => 'Already charged',
                ];
            }
        }

        if ($balance < $commission) {
            $need = (int) round($commission / 100);

            return [
                'ok' => false,
                'commission_paise' => $commission,
                'message' => "Wallet balance too low. Recharge at least ₹{$need} to start this job.",
            ];
        }

        if ($ledger->tableExists()) {
            $ledger->debitCommission($professionalId, $bookingId, $commission);
            $this->markBookingCommissionPaidFromWallet($bookingId);
        }

        if ($this->hasCommissionColumns()) {
            $this->db->prepare(
                'UPDATE service_bookings
                 SET commission_paise = ?, commission_waived = 0, updated_at = NOW()
                 WHERE id = ? AND pro_credit_paise IS NULL'
            )->execute([$commission, $bookingId]);
        }

        $this->syncListingHoldForWallet($professionalId);

        return [
            'ok' => true,
            'commission_paise' => $commission,
            'message' => 'OK',
        ];
    }

    public function isStartWorkVerified(int $bookingId): bool
    {
        if ($this->hasStartWorkVerifiedColumn()) {
            $stmt = $this->db->prepare(
                'SELECT start_work_verified_at FROM service_bookings WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$bookingId]);
            $val = $stmt->fetchColumn();
            if ($val !== false && $val !== null && (string) $val !== '') {
                return true;
            }
        }

        try {
            $this->ensureStartOtpTable();
            $stmt = $this->db->prepare(
                'SELECT 1 FROM booking_start_otps
                 WHERE booking_id = ? AND verified_at IS NOT NULL
                 LIMIT 1'
            );
            $stmt->execute([$bookingId]);

            return (bool) $stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    private ?bool $hasStartWorkVerified = null;

    private function hasStartWorkVerifiedColumn(): bool
    {
        if ($this->hasStartWorkVerified !== null) {
            return $this->hasStartWorkVerified;
        }
        try {
            $stmt = $this->db->query(
                "SHOW COLUMNS FROM service_bookings LIKE 'start_work_verified_at'"
            );
            $this->hasStartWorkVerified = $stmt !== false && (bool) $stmt->fetch();
            if (!$this->hasStartWorkVerified) {
                try {
                    $this->db->exec(
                        'ALTER TABLE service_bookings
                         ADD COLUMN start_work_verified_at DATETIME NULL DEFAULT NULL'
                    );
                    $this->hasStartWorkVerified = true;
                } catch (\Throwable) {
                    $this->hasStartWorkVerified = false;
                }
            }
        } catch (\Throwable) {
            $this->hasStartWorkVerified = false;
        }

        return $this->hasStartWorkVerified;
    }

    private ?bool $hasStartOtpTable = null;

    private function ensureStartOtpTable(): void
    {
        if ($this->hasStartOtpTable === true) {
            return;
        }
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE 'booking_start_otps'");
            if ($stmt !== false && $stmt->fetch()) {
                $this->hasStartOtpTable = true;

                return;
            }
        } catch (\Throwable) {
        }

        // Auto-create if migration not run yet (dev / live without migrate).
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS booking_start_otps (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                booking_id BIGINT UNSIGNED NOT NULL,
                request_id CHAR(32) NOT NULL,
                phone_e164 VARCHAR(20) NOT NULL,
                otp_code CHAR(6) NOT NULL,
                expires_at DATETIME NOT NULL,
                verified_at DATETIME NULL DEFAULT NULL,
                attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_booking_start_otp_request (request_id),
                KEY idx_booking_start_otp_booking (booking_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->hasStartOtpTable = true;
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
     * Customer pays visit fee after work is done → confirms work + completed + settle pro credit.
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
     * After free tier: deduct percent of visit fee from prepaid wallet.
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
        // Visit fee stays with pro as earnings; platform fee is deducted from prepaid wallet.
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

        // Deduct platform fee from prepaid wallet and mark fee settled.
        // May already be charged at start-work OTP verify — ledger is idempotent.
        if ($commission > 0) {
            $ledger = new WalletLedgerRepository();
            if ($ledger->tableExists()) {
                try {
                    $ledger->debitCommission($professionalId, $bookingId, $commission);
                    $this->markBookingCommissionPaidFromWallet($bookingId);
                } catch (\Throwable) {
                    // Leave as unpaid fee due; listing hold will catch low balance.
                }
            }
        }

        $pros = new ProRepository();
        $pros->incrementJobsCompleted($professionalId);
        if ($isFree && $this->hasProFreeTierColumns()) {
            $pros->incrementFreeBookingsUsed($professionalId);
        }

        // Hold when prepaid wallet drops below min after free tier.
        $this->syncListingHoldForWallet($professionalId);
    }

    private function markBookingCommissionPaidFromWallet(int $bookingId): void
    {
        if (!$this->hasCommissionUpiPaidColumn()) {
            return;
        }
        if ($this->hasCommissionUpiUtrColumn()) {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET commission_upi_paid_at = COALESCE(commission_upi_paid_at, NOW()),
                     commission_upi_utr = COALESCE(commission_upi_utr, 'WALLET'),
                     updated_at = NOW()
                 WHERE id = ?
                   AND commission_paise > 0
                   AND commission_upi_paid_at IS NULL"
            );
            $stmt->execute([$bookingId]);
        } else {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET commission_upi_paid_at = COALESCE(commission_upi_paid_at, NOW()),
                     updated_at = NOW()
                 WHERE id = ?
                   AND commission_paise > 0
                   AND commission_upi_paid_at IS NULL"
            );
            $stmt->execute([$bookingId]);
        }
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
        $prepaid = $this->netWalletPaise($professionalId);
        $min = $settings->walletMinAcceptPaise();
        $rechargeMin = $settings->walletRechargeMinPaise();
        $upi = $settings->companyUpiId();
        $suggestRecharge = $prepaid < $min
            ? max($rechargeMin, $min - $prepaid)
            : $rechargeMin;

        return array_merge($settings->publicPayload(), [
            'free_bookings_used' => $used,
            'free_bookings_remaining' => $remaining,
            'listing_held' => (bool) ($pro['listing_held'] ?? false),
            'platform_fee_due_paise' => $feeDue,
            'wallet_balance_paise' => $prepaid,
            'wallet_net_paise' => $prepaid,
            'wallet_min_accept_paise' => $min,
            'wallet_recharge_min_paise' => $rechargeMin,
            'suggested_recharge_paise' => $suggestRecharge,
            'can_accept_jobs' => $this->canProfessionalAccept($professionalId),
            'company_upi_pay_uri' => $settings->companyUpiPayUri(
                $suggestRecharge,
                'Pro Enroll wallet recharge',
            ),
            'commission_note' => $remaining > 0
                ? sprintf(
                    'First %d jobs free (%d left). After that keep min ₹%d in wallet; %d%% of visit fee is deducted per job. Recharge via company UPI %s.',
                    $limit,
                    $remaining,
                    (int) round($min / 100),
                    $settings->visitCommissionPercent(),
                    $upi,
                )
                : sprintf(
                    'Keep min ₹%d in wallet. Each job deducts %d%% of visit fee. Recharge via company UPI %s.',
                    (int) round($min / 100),
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
            // Share service pin so the pro can decide accept/reject before work starts.
            'customer_lat' => $row['address_lat'] !== null ? (float) $row['address_lat'] : null,
            'customer_lng' => $row['address_lng'] !== null ? (float) $row['address_lng'] : null,
            'distance_km' => self::distanceKm($row, $proLat, $proLng),
            'visit_fee_paise' => (int) $row['visit_fee_paise'],
            'preferred_time' => IstTime::formatTs($scheduled),
            // Pro can accept/reject until the scheduled work start time.
            'expires_at' => IstTime::formatTs($scheduled),
            'created_at' => IstTime::formatTs($created),
            'commission_preview' => $this->commissionPreviewForPro((int) $row['professional_id'], (int) $row['visit_fee_paise']),
            'cancels_remaining_today' => $this->professionalDailyCancelsRemaining((int) ($row['professional_id'] ?? 0)),
            'daily_cancel_limit' => self::DAILY_CANCEL_LIMIT,
            'can_reject' => $this->professionalDailyCancelsRemaining((int) ($row['professional_id'] ?? 0)) > 0,
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
        $credit = max(0, $visitFeePaise);
        $settingsName = $settings->companyUpiId();

        return [
            'is_free_booking' => $isFree,
            'free_bookings_remaining' => $remaining,
            'visit_commission_percent' => $isFree ? 0 : $percent,
            'commission_paise' => $commission,
            'pro_credit_paise' => $credit,
            'company_upi_id' => $settingsName,
            'label' => $isFree
                ? sprintf('Free booking — no wallet deduction (%d left)', $remaining)
                : sprintf(
                    'Visit fee %s · Wallet −%s (%d%%)',
                    '₹' . number_format($credit / 100, 0),
                    '₹' . number_format($commission / 100, 0),
                    $percent,
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
            'scheduled_at' => !empty($row['scheduled_at'])
                ? IstTime::format((string) $row['scheduled_at'])
                : null,
            'accepted_at' => !empty($row['accepted_at'])
                ? IstTime::format((string) $row['accepted_at'])
                : null,
            'updated_at' => !empty($row['updated_at'])
                ? IstTime::format((string) $row['updated_at'])
                : null,
            'can_cancel' => $this->canProfessionalCancel($row),
            'reject_requires_reason' => self::rejectRequiresReason((string) ($row['status'] ?? '')),
            'reject_penalty_paise' => $this->lateRejectPenaltyPaise($row),
            'cancels_remaining_today' => $this->professionalDailyCancelsRemaining((int) ($row['professional_id'] ?? 0)),
            'daily_cancel_limit' => self::DAILY_CANCEL_LIMIT,
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
            // Pro finished work; customer must pay (or pro confirms cash received).
            'awaiting_payment' => 'awaiting_payment',
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
        $earningsWallet = (int) ($row['wallet_balance_paise'] ?? $today);
        $meta = $this->commissionMetaForProfessional($professionalId);
        // Prefer prepaid wallet balance from meta when ledger is active.
        $prepaid = (int) ($meta['wallet_balance_paise'] ?? $this->netWalletPaise($professionalId));

        return array_merge([
            'today_paise' => $today,
            'week_paise' => (int) ($row['week_paise'] ?? 0),
            'month_paise' => (int) ($row['month_paise'] ?? 0),
            'payouts_this_month_paise' => (int) ($row['payouts_this_month_paise'] ?? 0),
            'pending_payout_paise' => $earningsWallet,
            'earnings_balance_paise' => $earningsWallet,
            'wallet_balance_paise' => $prepaid,
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

    private function hasProStuckTrackColumns(): bool
    {
        if (self::$hasProStuckTrackColumns !== null) {
            return self::$hasProStuckTrackColumns;
        }

        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM service_bookings LIKE 'pro_last_moved_at'");
            self::$hasProStuckTrackColumns = $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            self::$hasProStuckTrackColumns = false;
        }

        return self::$hasProStuckTrackColumns;
    }

    private function hasCancelledByColumns(): bool
    {
        if (self::$hasCancelledByColumns !== null) {
            return self::$hasCancelledByColumns;
        }

        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM service_bookings LIKE 'cancelled_by'");
            self::$hasCancelledByColumns = $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            self::$hasCancelledByColumns = false;
        }

        return self::$hasCancelledByColumns;
    }
}
