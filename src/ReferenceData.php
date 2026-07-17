<?php

declare(strict_types=1);

namespace ProEnroll\Api;

final class ReferenceData
{
    /** @return list<array<string, mixed>> */
    public static function categories(): array
    {
        return (new \ProEnroll\Api\Services\CategoryRepository())->listActive();
    }

    /** Hard-coded fallback when DB is unavailable or empty. */
    /** @return list<array<string, mixed>> */
    public static function staticCategories(): array
    {
        return [
            ['code' => 'ac', 'name_en' => 'AC Mechanic', 'name_ta' => 'AC மெக்கானிக்', 'icon_key' => 'ac_unit', 'default_visit_fee_paise' => 20000, 'base_price_paise' => 20000, 'sort_order' => 1],
            ['code' => 'plumber', 'name_en' => 'Plumber', 'name_ta' => 'பிளம்பர்', 'icon_key' => 'plumbing', 'default_visit_fee_paise' => 15000, 'base_price_paise' => 15000, 'sort_order' => 2],
            ['code' => 'electrician', 'name_en' => 'Electrician', 'name_ta' => 'மின்சார வேலை', 'icon_key' => 'electrical_services', 'default_visit_fee_paise' => 15000, 'base_price_paise' => 15000, 'sort_order' => 3],
            ['code' => 'ro', 'name_en' => 'RO Water Service', 'name_ta' => 'RO வாட்டர் சர்வீஸ்', 'icon_key' => 'water_drop', 'default_visit_fee_paise' => 15000, 'base_price_paise' => 15000, 'sort_order' => 4],
            ['code' => 'fridge', 'name_en' => 'Fridge Repair', 'name_ta' => 'குளிர்சாதனம் ரிப்பேர்', 'icon_key' => 'kitchen', 'default_visit_fee_paise' => 20000, 'base_price_paise' => 20000, 'sort_order' => 5],
            ['code' => 'wash', 'name_en' => 'Washing Machine', 'name_ta' => 'வாஷிங் மெஷின்', 'icon_key' => 'local_laundry_service', 'default_visit_fee_paise' => 20000, 'base_price_paise' => 20000, 'sort_order' => 6],
            ['code' => 'car', 'name_en' => 'Car Mechanic', 'name_ta' => 'கார் மெக்கானிக்', 'icon_key' => 'directions_car', 'default_visit_fee_paise' => 20000, 'base_price_paise' => 20000, 'sort_order' => 7],
            ['code' => 'bike', 'name_en' => 'Bike Mechanic', 'name_ta' => 'பைக் மெக்கானிக்', 'icon_key' => 'two_wheeler', 'default_visit_fee_paise' => 15000, 'base_price_paise' => 15000, 'sort_order' => 8],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function cities(): array
    {
        return [
            ['id' => 1, 'name' => 'Pondicherry', 'state' => 'Puducherry', 'latitude' => 11.9416, 'longitude' => 79.8083],
            ['id' => 2, 'name' => 'Karaikal', 'state' => 'Puducherry', 'latitude' => 10.9254, 'longitude' => 79.8380],
            ['id' => 3, 'name' => 'Cuddalore', 'state' => 'Tamil Nadu', 'latitude' => 11.7480, 'longitude' => 79.7714],
            ['id' => 4, 'name' => 'Villupuram', 'state' => 'Tamil Nadu', 'latitude' => 11.9401, 'longitude' => 79.4861],
            ['id' => 5, 'name' => 'Tindivanam', 'state' => 'Tamil Nadu', 'latitude' => 12.2340, 'longitude' => 79.6550],
            ['id' => 6, 'name' => 'Panruti', 'state' => 'Tamil Nadu', 'latitude' => 11.7766, 'longitude' => 79.5529],
            ['id' => 7, 'name' => 'Neyveli', 'state' => 'Tamil Nadu', 'latitude' => 11.5436, 'longitude' => 79.4832],
        ];
    }

    /** @return array<string, int> */
    public static function defaultFees(): array
    {
        $out = [];
        foreach (self::categories() as $c) {
            $out[$c['code']] = $c['default_visit_fee_paise'];
        }
        return $out;
    }

    /** @return array<string, int> */
    public static function basePrices(): array
    {
        $out = [];
        foreach (self::categories() as $c) {
            $out[$c['code']] = (int) ($c['base_price_paise'] ?? $c['default_visit_fee_paise']);
        }
        return $out;
    }

    public static function cityById(int $id): ?array
    {
        foreach (self::cities() as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        return null;
    }

    /** @return list<array<string, mixed>> */
    public static function demoJobOffers(array $categoryCodes): array
    {
        $now = new \DateTimeImmutable();
        $pool = [
            ['cat' => 'ac', 'problem' => 'AC not cooling — bedroom split AC, 1.5T', 'customer' => 'Saraswathi', 'area' => 'Mission Street, Pondicherry', 'distance' => 1.2, 'fee' => 20000],
            ['cat' => 'plumber', 'problem' => 'Kitchen tap is leaking continuously', 'customer' => 'Anjali', 'area' => 'Lawspet, Pondicherry', 'distance' => 2.8, 'fee' => 15000],
            ['cat' => 'ro', 'problem' => 'RO not producing water', 'customer' => 'Murugan R.', 'area' => 'Bharathiar Road, Karaikal', 'distance' => 0.9, 'fee' => 15000],
        ];
        $offers = [];
        $i = 0;
        foreach ($pool as $m) {
            if (!in_array($m['cat'], $categoryCodes, true)) {
                continue;
            }
            $i++;
            $offers[] = [
                'id' => "off_$i",
                'code' => 'PE-2026-' . str_pad((string) (900 + $i), 6, '0', STR_PAD_LEFT),
                'category_code' => $m['cat'],
                'problem' => $m['problem'],
                'customer_name' => $m['customer'],
                'customer_area_name' => $m['area'],
                'distance_km' => $m['distance'],
                'visit_fee_paise' => $m['fee'],
                'preferred_time' => $now->modify("+$i hour")->format(DATE_ATOM),
                'expires_at' => $now->modify('+60 seconds')->format(DATE_ATOM),
            ];
        }
        return $offers;
    }
}
