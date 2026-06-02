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
        $stmt = $this->db->prepare('SELECT * FROM professionals WHERE phone_e164 = ? LIMIT 1');
        $stmt->execute([$phoneE164]);
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
            'full_name', 'city_id', 'home_lat', 'home_lng',
            'work_radius_km', 'visit_fee_paise',
            'is_available', 'kyc_status', 'aadhaar_last4', 'upi_id',
            'bank_account_no', 'bank_ifsc', 'language_code',
        ];
        $sets = [];
        $params = [];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $sets[] = "$key = ?";
            $params[] = $value;
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
     * Search enrolled technicians for customer app.
     *
     * @return list<array<string, mixed>>
     */
    public function searchForCustomer(
        int $cityId,
        ?string $categoryCode = null,
        ?string $query = null,
        ?float $customerLat = null,
        ?float $customerLng = null,
    ): array {
        $sql = 'SELECT DISTINCT p.* FROM professionals p
                INNER JOIN professional_skills ps ON ps.professional_id = p.id
                WHERE p.full_name IS NOT NULL
                  AND p.city_id = ?
                  AND p.kyc_status IN (\'verified\', \'in_review\')';
        $params = [$cityId];

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
            $skills = $this->getSkills((int) $pro['id']);
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
                    continue;
                }
            }

            $proLat = $pro['home_lat'] !== null ? (float) $pro['home_lat'] : null;
            $proLng = $pro['home_lng'] !== null ? (float) $pro['home_lng'] : null;

            if ($proLat !== null && $proLng !== null && $customerLat !== null && $customerLng !== null) {
                $dist = self::haversineKm($customerLat, $customerLng, $proLat, $proLng);
            } else {
                $dist = round(0.8 + ((int) $pro['id'] % 7) * 0.35, 1);
            }

            $out[] = [
                'id' => (string) $pro['id'],
                'full_name' => $pro['full_name'],
                'phone_masked' => self::maskPhone($pro['phone_e164'] ?? ''),
                'city_id' => (int) $pro['city_id'],
                'work_radius_km' => (int) $pro['work_radius_km'],
                'visit_fee_paise' => (int) $pro['visit_fee_paise'],
                'is_available' => (bool) $pro['is_available'],
                'kyc_verified' => $pro['kyc_status'] === 'verified',
                'kyc_status' => $pro['kyc_status'],
                'rating_avg' => (float) $pro['rating_avg'],
                'rating_count' => (int) $pro['rating_count'],
                'jobs_completed' => (int) $pro['jobs_completed'],
                'pro_score' => (int) $pro['pro_score'],
                'distance_km' => $dist,
                'primary_category_code' => $primary,
                'skills' => array_map(static fn ($s) => [
                    'category_code' => $s['category_code'],
                    'experience_years' => (int) $s['experience_years'],
                    'is_primary' => (bool) $s['is_primary'],
                ], $skills),
            ];
        }
        usort($out, static fn ($a, $b) => $a['distance_km'] <=> $b['distance_km']);
        return $out;
    }

    private static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
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
    public function customerDetail(int $id, ?string $categoryCode = null): ?array
    {
        $pro = $this->findById($id);
        if ($pro === null || $pro['full_name'] === null) {
            return null;
        }
        $list = $this->searchForCustomer(
            (int) $pro['city_id'],
            $categoryCode,
        );
        foreach ($list as $item) {
            if ($item['id'] === (string) $id) {
                return $item;
            }
        }
        return null;
    }

    private static function maskPhone(string $phone): string
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
            'skills' => array_map(static fn ($s) => [
                'category_code' => $s['category_code'],
                'experience_years' => (int) $s['experience_years'],
                'is_primary' => (bool) $s['is_primary'],
            ], $skills),
        ];
    }
}
