<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Database;
use ProEnroll\Api\ReferenceData;

final class BookingRepository
{
    private PDO $db;

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

        $id = (int) $this->db->lastInsertId();
        return $this->findById($id) ?? [];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT b.*, p.full_name AS pro_name, p.phone_e164 AS pro_phone,
                    p.rating_avg AS pro_rating_avg, p.rating_count AS pro_rating_count,
                    p.kyc_status AS pro_kyc_status
             FROM service_bookings b
             INNER JOIN professionals p ON p.id = b.professional_id
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
        $stmt = $this->db->prepare(
            'UPDATE service_bookings
             SET status = ?, completed_at = NOW(), updated_at = NOW()
             WHERE id = ? AND customer_id = ?
               AND status IN (\'in_progress\', \'awaiting_payment\', \'arrived\', \'en_route\', \'confirmed\')'
        );
        $stmt->execute(['completed', $bookingId, $customerId]);
        return $stmt->rowCount() > 0;
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
            'final_amount_paise' => isset($row['final_amount_paise']) && $row['final_amount_paise'] !== null
                ? (int) $row['final_amount_paise'] : null,
            'total_due_paise' => self::totalDuePaise($row),
            'status_label' => self::statusLabel((string) $row['status']),
            'scheduled_at' => date(DATE_ATOM, strtotime($row['scheduled_at'])),
            'completed_at' => $row['completed_at']
                ? date(DATE_ATOM, strtotime($row['completed_at']))
                : null,
            'created_at' => date(DATE_ATOM, strtotime($row['created_at'])),
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
            'can_mark_completed' => in_array($row['status'], [
                'confirmed', 'en_route', 'arrived', 'in_progress', 'awaiting_payment',
            ], true),
        ];
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
        $stmt = $this->db->prepare(
            "UPDATE service_bookings
             SET status = 'en_route', updated_at = NOW()
             WHERE id = ? AND professional_id = ? AND status = 'confirmed'"
        );
        $stmt->execute([$bookingId, $professionalId]);
        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->findActiveForProfessional($professionalId, $bookingId);
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
        $sql .= ' ORDER BY b.updated_at DESC LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function updateActiveJobStatus(int $bookingId, int $professionalId, string $apiStatus): bool
    {
        $dbStatus = self::apiStatusToDb($apiStatus);
        $stmt = $this->db->prepare(
            "UPDATE service_bookings
             SET status = ?, updated_at = NOW()
             WHERE id = ? AND professional_id = ?
               AND status IN ('en_route', 'arrived', 'in_progress', 'awaiting_payment')"
        );
        $stmt->execute([$dbStatus, $bookingId, $professionalId]);

        return $stmt->rowCount() > 0;
    }

    public function completeActiveJob(int $bookingId, int $professionalId, ?int $finalAmountPaise = null): bool
    {
        if ($finalAmountPaise !== null && $finalAmountPaise >= 100) {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET status = 'completed', completed_at = NOW(), updated_at = NOW(),
                     final_amount_paise = ?
                 WHERE id = ? AND professional_id = ?
                   AND status IN ('en_route', 'arrived', 'in_progress', 'awaiting_payment')"
            );
            $stmt->execute([$finalAmountPaise, $bookingId, $professionalId]);
        } else {
            $stmt = $this->db->prepare(
                "UPDATE service_bookings
                 SET status = 'completed', completed_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND professional_id = ?
                   AND status IN ('en_route', 'arrived', 'in_progress', 'awaiting_payment')"
            );
            $stmt->execute([$bookingId, $professionalId]);
        }
        if ($stmt->rowCount() === 0) {
            return false;
        }

        $this->db->prepare(
            'UPDATE professionals SET jobs_completed = jobs_completed + 1, updated_at = NOW() WHERE id = ?'
        )->execute([$professionalId]);

        return true;
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
            'preferred_time' => date(DATE_ATOM, $scheduled),
            'expires_at' => date(DATE_ATOM, $created + 3600),
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
            'customer_lat' => $row['address_lat'] !== null ? (float) $row['address_lat'] : null,
            'customer_lng' => $row['address_lng'] !== null ? (float) $row['address_lng'] : null,
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
            'in_progress' => 'in_progress',
            'awaiting_payment' => 'in_progress',
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
        $amount = 'COALESCE(NULLIF(final_amount_paise, 0), visit_fee_paise)';

        $stmt = $this->db->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN DATE(completed_at) = CURDATE() THEN $amount ELSE 0 END), 0) AS today_paise,
                COALESCE(SUM(CASE WHEN completed_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN $amount ELSE 0 END), 0) AS week_paise,
                COALESCE(SUM(CASE WHEN YEAR(completed_at) = YEAR(CURDATE()) AND MONTH(completed_at) = MONTH(CURDATE()) THEN $amount ELSE 0 END), 0) AS month_paise,
                COALESCE(SUM(CASE WHEN DATE(completed_at) = CURDATE() THEN 1 ELSE 0 END), 0) AS jobs_today,
                COALESCE(SUM(CASE WHEN YEAR(completed_at) = YEAR(CURDATE()) AND MONTH(completed_at) = MONTH(CURDATE()) AND DATE(completed_at) < CURDATE() THEN $amount ELSE 0 END), 0) AS payouts_this_month_paise
             FROM service_bookings
             WHERE professional_id = ?
               AND status = 'completed'
               AND completed_at IS NOT NULL"
        );
        $stmt->execute([$professionalId]);
        $row = $stmt->fetch() ?: [];

        $today = (int) ($row['today_paise'] ?? 0);
        $month = (int) ($row['month_paise'] ?? 0);
        $paidThisMonth = (int) ($row['payouts_this_month_paise'] ?? 0);

        return [
            'today_paise' => $today,
            'week_paise' => (int) ($row['week_paise'] ?? 0),
            'month_paise' => $month,
            'payouts_this_month_paise' => $paidThisMonth,
            'pending_payout_paise' => $today,
            'jobs_today' => (int) ($row['jobs_today'] ?? 0),
        ];
    }
}
