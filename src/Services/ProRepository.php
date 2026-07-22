<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Database;

/**
 * Persists professional profile data keyed by auth UID (JWT `sub`).
 */
final class ProRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function findByFirebaseUid(string $uid): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM professionals WHERE firebase_uid = ? LIMIT 1');
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByPhone(string $phoneE164): ?array
    {
        $variants = DeviceTokenRepository::phoneVariants($phoneE164);
        if ($variants === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($variants), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM professionals WHERE phone_e164 IN ({$placeholders}) LIMIT 1"
        );
        $stmt->execute($variants);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function upsertFromPhone(string $phoneE164): array
    {
        $existing = $this->findByPhone($phoneE164);
        if ($existing !== null) {
            return $existing;
        }

        $uid = 'pro_' . bin2hex(random_bytes(12));
        $stmt = $this->db->prepare(
            'INSERT INTO professionals (firebase_uid, phone_e164, kyc_status, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([$uid, $phoneE164, 'not_started']);

        return $this->findByFirebaseUid($uid) ?? [];
    }

    public function upsertFromAuth(string $uid, ?string $phoneE164): array
    {
        $existing = $this->findByFirebaseUid($uid);
        if ($existing !== null) {
            if ($phoneE164 !== null && ($existing['phone_e164'] ?? '') !== $phoneE164) {
                $stmt = $this->db->prepare(
                    'UPDATE professionals SET phone_e164 = ?, updated_at = NOW() WHERE firebase_uid = ?'
                );
                $stmt->execute([$phoneE164, $uid]);
            }
            return $this->findByFirebaseUid($uid) ?? $existing;
        }

        if ($phoneE164 !== null) {
            $byPhone = $this->findByPhone($phoneE164);
            if ($byPhone !== null) {
                return $byPhone;
            }
        }

        $stmt = $this->db->prepare(
            'INSERT INTO professionals (firebase_uid, phone_e164, kyc_status, created_at, updated_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([$uid, $phoneE164, 'not_started']);

        return $this->findByFirebaseUid($uid) ?? [];
    }

    /** @deprecated Use upsertFromAuth */
    public function upsertFromFirebase(string $uid, ?string $phoneE164): array
    {
        return $this->upsertFromAuth($uid, $phoneE164);
    }

  /** @param array<string, mixed> $fields */
    public function updateProfile(string $uid, array $fields): array
    {
        $allowed = [
            'full_name', 'display_name', 'city_id', 'home_lat', 'home_lng',
            'work_radius_km', 'visit_fee_paise',
            'is_available', 'kyc_status', 'kyc_rejected_reason',
            'listing_held', 'free_bookings_used',
            'aadhaar_last4', 'face_match_score', 'upi_id',
            'bank_account_no', 'bank_ifsc', 'language_code',
        ];
        $sets = [];
        $params = [];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            if ($key === 'visit_fee_paise') {
                $value = (new PlatformSettingsRepository())->clampVisitFeePaise((int) $value);
            }
            $sets[] = "$key = ?";
            $params[] = $value;
        }

        // Going online refreshes presence so customer search includes them.
        if (
            array_key_exists('is_available', $fields)
            && (int) $fields['is_available'] === 1
            && $this->hasLastSeenColumn()
        ) {
            $sets[] = 'last_seen_at = NOW()';
        }

        if ($sets === []) {
            return $this->findByFirebaseUid($uid) ?? [];
        }
        $sets[] = 'updated_at = NOW()';
        $params[] = $uid;
        $sql = 'UPDATE professionals SET ' . implode(', ', $sets) . ' WHERE firebase_uid = ?';
        $this->db->prepare($sql)->execute($params);
        return $this->findByFirebaseUid($uid) ?? [];
    }

    /** Minutes without heartbeat before an "online" pro is hidden from customers. */
    public const ONLINE_PRESENCE_TTL_MINUTES = 15;

    /** @var bool|null */
    private static ?bool $hasLastSeenColumn = null;

    public function hasLastSeenColumn(): bool
    {
        if (self::$hasLastSeenColumn !== null) {
            return self::$hasLastSeenColumn;
        }

        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM professionals LIKE 'last_seen_at'");
            self::$hasLastSeenColumn = $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            self::$hasLastSeenColumn = false;
        }

        return self::$hasLastSeenColumn;
    }

    /** Heartbeat while pro app is open and available. */
    public function touchPresence(string $uid): bool
    {
        if (!$this->hasLastSeenColumn()) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE professionals
             SET last_seen_at = NOW(), updated_at = NOW()
             WHERE firebase_uid = ? AND is_available = 1'
        );
        $stmt->execute([$uid]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Mark pros offline if they stopped heartbeating (uninstall / kill / crash).
     */
    public function expireStaleOnlinePresence(): int
    {
        if (!$this->hasLastSeenColumn()) {
            return 0;
        }

        $ttl = self::ONLINE_PRESENCE_TTL_MINUTES;
        $stmt = $this->db->prepare(
            "UPDATE professionals
             SET is_available = 0, updated_at = NOW()
             WHERE is_available = 1
               AND (
                 last_seen_at IS NULL
                 OR last_seen_at < (NOW() - INTERVAL {$ttl} MINUTE)
               )"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    /** @param list<array{category_code: string, experience_years: int, is_primary: bool}> $skills */
    public function replaceSkills(string $uid, array $skills): void
    {
        $pro = $this->findByFirebaseUid($uid);
        if ($pro === null) {
            return;
        }
        $proId = (int) $pro['id'];
        $this->db->prepare('DELETE FROM professional_skills WHERE professional_id = ?')->execute([$proId]);
        $stmt = $this->db->prepare(
            'INSERT INTO professional_skills (professional_id, category_code, experience_years, is_primary)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($skills as $s) {
            $stmt->execute([
                $proId,
                $s['category_code'],
                $s['experience_years'],
                $s['is_primary'] ? 1 : 0,
            ]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function getSkills(int $professionalId): array
    {
        $stmt = $this->db->prepare(
            'SELECT category_code, experience_years, is_primary FROM professional_skills WHERE professional_id = ?'
        );
        $stmt->execute([$professionalId]);
        return $stmt->fetchAll();
    }

    /**
     * Post-login / post-OTP route for enrolled professionals (sign-in or sign-up).
     *
     * @param array<string, mixed> $profile From [profilePayload]
     */
    public function resolveNextRouteFromProfile(array $profile): string
    {
        if (($profile['registered'] ?? false) !== true) {
            return '/onboard/category';
        }

        $skills = $profile['skills'] ?? [];
        if (!is_array($skills) || $skills === []) {
            return '/onboard/category';
        }

        $fullName = $profile['full_name'] ?? null;
        if (!is_string($fullName) || trim($fullName) === '') {
            return '/onboard/experience';
        }

        if (($profile['city_id'] ?? null) === null) {
            return '/onboard/location';
        }

        return match ((string) ($profile['kyc_status'] ?? 'not_started')) {
            'verified' => '/home',
            'in_review' => '/kyc/pending',
            'aadhaar_pending' => '/kyc/aadhaar',
            'selfie_pending' => '/kyc/selfie',
            default => '/kyc',
        };
    }

    /**
     * Search enrolled technicians for customer app (online + verified KYC only).
     *
     * @return list<array<string, mixed>>
     */
    public function searchForCustomer(
        int $cityId,
        ?string $categoryCode = null,
        ?string $query = null,
        ?float $customerLat = null,
        ?float $customerLng = null,
        ?int $excludeCustomerId = null,
    ): array {
        $useGeo = $customerLat !== null && $customerLng !== null;

        $heldFilter = $this->hasListingHeldColumn()
            ? ' AND COALESCE(p.listing_held, 0) = 0'
            : '';

        // Drop ghost-online pros (app uninstalled / no heartbeat).
        $this->expireStaleOnlinePresence();

        $presenceFilter = '';
        if ($this->hasLastSeenColumn()) {
            $ttl = self::ONLINE_PRESENCE_TTL_MINUTES;
            $presenceFilter = " AND p.last_seen_at IS NOT NULL
              AND p.last_seen_at >= (NOW() - INTERVAL {$ttl} MINUTE)";
        }

        // Hide pros this customer already has an in-process booking with.
        $busyProFilter = '';
        $params = [];
        if ($excludeCustomerId !== null && $excludeCustomerId > 0) {
            $busyProFilter = " AND p.id NOT IN (
                SELECT DISTINCT sb.professional_id
                FROM service_bookings sb
                WHERE sb.customer_id = ?
                  AND sb.status IN (
                    'confirmed', 'en_route', 'arrived', 'in_progress', 'awaiting_payment'
                  )
            )";
            $params[] = $excludeCustomerId;
        }

        $sql = 'SELECT DISTINCT p.* FROM professionals p
                INNER JOIN professional_skills ps ON ps.professional_id = p.id
                WHERE p.full_name IS NOT NULL
                  AND p.is_available = 1
                  AND p.kyc_status = \'verified\''
            . $heldFilter
            . $presenceFilter
            . $busyProFilter;

        if (!$useGeo) {
            $sql .= ' AND p.city_id = ?';
            $params[] = $cityId;
        }

        if ($categoryCode !== null && $categoryCode !== '') {
            $sql .= ' AND ps.category_code = ?';
            $params[] = $categoryCode;
        }
        if ($query !== null && trim($query) !== '') {
            $sql .= ' AND (p.full_name LIKE ? OR ps.category_code LIKE ?)';
            $q = '%' . trim($query) . '%';
            $params[] = $q;
            $params[] = $q;
        }

        $sql .= ' ORDER BY p.is_available DESC, p.kyc_status = \'verified\' DESC,
                  p.rating_avg DESC, p.jobs_completed DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if ($customerLat === null || $customerLng === null) {
            $city = \ProEnroll\Api\ReferenceData::cityById($cityId);
            if ($city !== null) {
                $customerLat = (float) $city['latitude'];
                $customerLng = (float) $city['longitude'];
            }
        }

        $out = [];
        foreach ($rows as $pro) {
            $item = $this->buildCustomerProPayload(
                $pro,
                $categoryCode,
                $customerLat,
                $customerLng,
                enforceRadius: true,
            );
            if ($item !== null) {
                $out[] = $item;
            }
        }
        usort($out, static function (array $a, array $b): int {
            $da = $a['distance_km'] ?? PHP_FLOAT_MAX;
            $db = $b['distance_km'] ?? PHP_FLOAT_MAX;
            return $da <=> $db;
        });
        return $out;
    }

    /**
     * Customer-facing pro card / detail payload.
     *
     * @param array<string, mixed> $pro Row from professionals table
     * @return array<string, mixed>|null
     */
    public function buildCustomerProPayload(
        array $pro,
        ?string $categoryCode = null,
        ?float $customerLat = null,
        ?float $customerLng = null,
        bool $enforceRadius = false,
    ): ?array {
        $skills = $this->getSkills((int) $pro['id']);
        if ($skills === []) {
            return null;
        }

        $primary = null;
        foreach ($skills as $s) {
            if ($s['is_primary']) {
                $primary = $s['category_code'];
                break;
            }
        }
        $primary ??= $skills[0]['category_code'] ?? 'ac';

        if ($categoryCode !== null && $categoryCode !== '') {
            $hasCat = false;
            foreach ($skills as $s) {
                if ($s['category_code'] === $categoryCode) {
                    $hasCat = true;
                    $primary = $categoryCode;
                    break;
                }
            }
            if (!$hasCat) {
                return null;
            }
        }

        $cityId = (int) $pro['city_id'];
        if ($customerLat === null || $customerLng === null) {
            $city = \ProEnroll\Api\ReferenceData::cityById($cityId);
            if ($city !== null) {
                $customerLat = (float) $city['latitude'];
                $customerLng = (float) $city['longitude'];
            }
        }

        $proLat = $pro['home_lat'] !== null ? (float) $pro['home_lat'] : null;
        $proLng = $pro['home_lng'] !== null ? (float) $pro['home_lng'] : null;

        $dist = null;
        if ($customerLat !== null && $customerLng !== null) {
            if ($proLat !== null && $proLng !== null) {
                $dist = self::haversineKm($customerLat, $customerLng, $proLat, $proLng);
            } else {
                $city = \ProEnroll\Api\ReferenceData::cityById($cityId);
                if ($city !== null) {
                    $dist = self::haversineKm(
                        $customerLat,
                        $customerLng,
                        (float) $city['latitude'],
                        (float) $city['longitude'],
                    );
                }
            }
            if ($dist !== null && $enforceRadius && $dist > (int) $pro['work_radius_km']) {
                return null;
            }
        }

        return [
            'id' => (string) $pro['id'],
            'full_name' => $pro['full_name'],
            'phone_e164' => $pro['phone_e164'] ?? null,
            'phone_masked' => self::maskPhone($pro['phone_e164'] ?? ''),
            'city_id' => $cityId,
            'work_radius_km' => (int) $pro['work_radius_km'],
            'visit_fee_paise' => (new PlatformSettingsRepository())
                ->clampVisitFeePaise((int) $pro['visit_fee_paise']),
            'is_available' => (bool) $pro['is_available'],
            'kyc_verified' => $pro['kyc_status'] === 'verified',
            'kyc_status' => $pro['kyc_status'],
            'rating_avg' => (float) $pro['rating_avg'],
            'rating_count' => (int) $pro['rating_count'],
            'jobs_completed' => (int) $pro['jobs_completed'],
            'pro_score' => (int) $pro['pro_score'],
            'distance_km' => $dist,
            'home_lat' => $proLat,
            'home_lng' => $proLng,
            'primary_category_code' => $primary,
            'skills' => array_map(static fn ($s) => [
                'category_code' => $s['category_code'],
                'experience_years' => (int) $s['experience_years'],
                'is_primary' => (bool) $s['is_primary'],
            ], $skills),
        ];
    }

    public static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return round($r * $c, 1);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM professionals WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public function customerDetail(
        int $id,
        ?string $categoryCode = null,
        ?float $customerLat = null,
        ?float $customerLng = null,
    ): ?array {
        $pro = $this->findById($id);
        if ($pro === null || $pro['full_name'] === null || trim((string) $pro['full_name']) === '') {
            return null;
        }

        return $this->buildCustomerProPayload(
            $pro,
            $categoryCode,
            $customerLat,
            $customerLng,
            enforceRadius: false,
        );
    }

    public static function maskPhone(string $phone): string
    {
        if (strlen($phone) < 8) {
            return $phone;
        }
        return substr($phone, 0, 4) . ' xxx xx' . substr($phone, -2);
    }

    public function profilePayload(string $uid): array
    {
        $pro = $this->findByFirebaseUid($uid);
        if ($pro === null) {
            return ['registered' => false];
        }
        $skills = $this->getSkills((int) $pro['id']);
        return [
            'registered' => true,
            'full_name' => $pro['full_name'],
            'phone_e164' => $pro['phone_e164'],
            'city_id' => $pro['city_id'] !== null ? (int) $pro['city_id'] : null,
            'work_radius_km' => (int) $pro['work_radius_km'],
            'visit_fee_paise' => (int) $pro['visit_fee_paise'],
            'is_available' => (bool) $pro['is_available'],
            'kyc_status' => $pro['kyc_status'],
            'aadhaar_last4' => $pro['aadhaar_last4'],
            'upi_id' => $pro['upi_id'],
            'bank_account_no' => $pro['bank_account_no'],
            'bank_ifsc' => $pro['bank_ifsc'],
            'rating_avg' => (float) $pro['rating_avg'],
            'rating_count' => (int) $pro['rating_count'],
            'jobs_completed' => (int) $pro['jobs_completed'],
            'pro_score' => (int) $pro['pro_score'],
            'language_code' => $pro['language_code'] ?? 'en',
            'free_bookings_used' => (int) ($pro['free_bookings_used'] ?? 0),
            'listing_held' => (bool) ($pro['listing_held'] ?? false),
            'skills' => array_map(static fn ($s) => [
                'category_code' => $s['category_code'],
                'experience_years' => (int) $s['experience_years'],
                'is_primary' => (bool) $s['is_primary'],
            ], $skills),
        ];
    }

    public function incrementJobsCompleted(int $professionalId): void
    {
        $this->db->prepare(
            'UPDATE professionals SET jobs_completed = jobs_completed + 1, updated_at = NOW() WHERE id = ?'
        )->execute([$professionalId]);
    }

    public function incrementFreeBookingsUsed(int $professionalId): void
    {
        if (!$this->hasFreeBookingsUsedColumn()) {
            return;
        }
        $this->db->prepare(
            'UPDATE professionals
             SET free_bookings_used = free_bookings_used + 1, updated_at = NOW()
             WHERE id = ?'
        )->execute([$professionalId]);
    }

    /** Hold listing: go offline and hide from customer search. */
    public function holdListing(int $professionalId): void
    {
        if ($this->hasListingHeldColumn()) {
            $this->db->prepare(
                'UPDATE professionals
                 SET listing_held = 1, is_available = 0, updated_at = NOW()
                 WHERE id = ?'
            )->execute([$professionalId]);
        } else {
            $this->db->prepare(
                'UPDATE professionals SET is_available = 0, updated_at = NOW() WHERE id = ?'
            )->execute([$professionalId]);
        }
    }

    /** Clear listing hold (does not force online). */
    public function releaseListing(int $professionalId): void
    {
        if (!$this->hasListingHeldColumn()) {
            return;
        }
        $this->db->prepare(
            'UPDATE professionals
             SET listing_held = 0, updated_at = NOW()
             WHERE id = ? AND listing_held = 1'
        )->execute([$professionalId]);
    }

    public function isListingHeld(int $professionalId): bool
    {
        if (!$this->hasListingHeldColumn()) {
            return false;
        }
        $stmt = $this->db->prepare('SELECT listing_held FROM professionals WHERE id = ? LIMIT 1');
        $stmt->execute([$professionalId]);
        $v = $stmt->fetchColumn();

        return (int) $v === 1;
    }

    private function hasListingHeldColumn(): bool
    {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM professionals LIKE 'listing_held'");

            return $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasFreeBookingsUsedColumn(): bool
    {
        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM professionals LIKE 'free_bookings_used'");

            return $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @var bool|null */
    private static ?bool $hasLastLocationColumns = null;

    public function hasLastLocationColumns(): bool
    {
        if (self::$hasLastLocationColumns !== null) {
            return self::$hasLastLocationColumns;
        }

        try {
            $stmt = $this->db->query("SHOW COLUMNS FROM professionals LIKE 'last_lat'");
            self::$hasLastLocationColumns = $stmt !== false && (bool) $stmt->fetch();
        } catch (\Throwable) {
            self::$hasLastLocationColumns = false;
        }

        return self::$hasLastLocationColumns;
    }

    public function updateLastLocation(int $professionalId, float $lat, float $lng): bool
    {
        if (!$this->hasLastLocationColumns()) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE professionals
             SET last_lat = ?, last_lng = ?, last_location_at = NOW(), updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$lat, $lng, $professionalId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Prefer fresh live GPS; fall back to home base.
     *
     * @param array<string, mixed> $pro
     * @return array{0: ?float, 1: ?float}
     */
    public static function resolveCoords(array $pro, int $maxAgeSeconds = 600): array
    {
        $lastLat = isset($pro['last_lat']) && $pro['last_lat'] !== null ? (float) $pro['last_lat'] : null;
        $lastLng = isset($pro['last_lng']) && $pro['last_lng'] !== null ? (float) $pro['last_lng'] : null;
        $lastAt = $pro['last_location_at'] ?? null;

        if ($lastLat !== null && $lastLng !== null && $lastAt !== null) {
            $age = time() - strtotime((string) $lastAt);
            if ($age >= 0 && $age <= $maxAgeSeconds) {
                return [$lastLat, $lastLng];
            }
        }

        $homeLat = isset($pro['home_lat']) && $pro['home_lat'] !== null ? (float) $pro['home_lat'] : null;
        $homeLng = isset($pro['home_lng']) && $pro['home_lng'] !== null ? (float) $pro['home_lng'] : null;

        return [$homeLat, $homeLng];
    }

    /** Rough ETA in minutes from distance (urban ~25 km/h). */
    public static function etaMinutesFromDistanceKm(float $distanceKm): int
    {
        if ($distanceKm <= 0.1) {
            return 5;
        }

        return max(5, (int) round(($distanceKm / 25.0) * 60.0));
    }
}
