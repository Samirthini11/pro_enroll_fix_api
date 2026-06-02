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
              address_text, city_id, status, visit_fee_paise, scheduled_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([
            $code,
            $data['customer_id'],
            $data['professional_id'],
            $data['category_code'],
            $data['problem_description'],
            $data['address_text'],
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
            'city_id' => (int) $row['city_id'],
            'city_name' => $city['name'] ?? '',
            'visit_fee_paise' => (int) $row['visit_fee_paise'],
            'scheduled_at' => date(DATE_ATOM, strtotime($row['scheduled_at'])),
            'completed_at' => $row['completed_at']
                ? date(DATE_ATOM, strtotime($row['completed_at']))
                : null,
            'created_at' => date(DATE_ATOM, strtotime($row['created_at'])),
            'professional' => [
                'id' => (string) $row['professional_id'],
                'full_name' => $row['pro_name'],
                'phone_e164' => $row['pro_phone'] ?? null,
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

    /** @return list<array<string, mixed>> */
    private static function trackingSteps(string $current): array
    {
        $steps = [
            ['key' => 'confirmed', 'label' => 'Booking confirmed'],
            ['key' => 'en_route', 'label' => 'Technician en route'],
            ['key' => 'arrived', 'label' => 'Arrived at your location'],
            ['key' => 'in_progress', 'label' => 'Repair in progress'],
            ['key' => 'completed', 'label' => 'Work completed'],
        ];
        $order = array_column($steps, 'key');
        $idx = array_search($current, $order, true);
        if ($idx === false && $current === 'awaiting_payment') {
            $idx = 3;
        }
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
}
