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
            ['code' => 'ac', 'name_en' => 'AC Mechanic', 'name_ta' => 'ஏசி மெக்கானிக்', 'icon_key' => 'ac_unit', 'default_visit_fee_paise' => 20000, 'base_price_paise' => 20000, 'sort_order' => 1],
            ['code' => 'plumber', 'name_en' => 'Plumber', 'name_ta' => 'பிளம்பர்', 'icon_key' => 'plumbing', 'default_visit_fee_paise' => 15000, 'base_price_paise' => 15000, 'sort_order' => 2],
            ['code' => 'electrician', 'name_en' => 'Electrician', 'name_ta' => 'மின்சார வேலை', 'icon_key' => 'electrical_services', 'default_visit_fee_paise' => 15000, 'base_price_paise' => 15000, 'sort_order' => 3],
            ['code' => 'ro', 'name_en' => 'RO Water Service', 'name_ta' => 'RO குடிநீர் சேவை', 'icon_key' => 'water_drop', 'default_visit_fee_paise' => 15000, 'base_price_paise' => 15000, 'sort_order' => 4],
            ['code' => 'fridge', 'name_en' => 'Fridge Repair', 'name_ta' => 'குளிர்சாதனப் பழுதுபார்ப்பு', 'icon_key' => 'kitchen', 'default_visit_fee_paise' => 20000, 'base_price_paise' => 20000, 'sort_order' => 5],
            ['code' => 'wash', 'name_en' => 'Washing Machine', 'name_ta' => 'சலவை இயந்திரம்', 'icon_key' => 'local_laundry_service', 'default_visit_fee_paise' => 20000, 'base_price_paise' => 20000, 'sort_order' => 6],
            ['code' => 'car', 'name_en' => 'Car Mechanic', 'name_ta' => 'கார் மெக்கானிக்', 'icon_key' => 'directions_car', 'default_visit_fee_paise' => 20000, 'base_price_paise' => 20000, 'sort_order' => 7],
            ['code' => 'bike', 'name_en' => 'Bike Mechanic', 'name_ta' => 'பைக் மெக்கானிக்', 'icon_key' => 'two_wheeler', 'default_visit_fee_paise' => 15000, 'base_price_paise' => 15000, 'sort_order' => 8],
            ['code' => 'puncture', 'name_en' => 'Puncture / Tyre', 'name_ta' => 'பஞ்சர் / டயர்', 'icon_key' => 'tire_repair', 'default_visit_fee_paise' => 15000, 'base_price_paise' => 15000, 'sort_order' => 9],
            ['code' => 'chimney', 'name_en' => 'Chimney / Hob', 'name_ta' => 'சிம்னி / ஹாப்', 'icon_key' => 'countertops', 'default_visit_fee_paise' => 20000, 'base_price_paise' => 20000, 'sort_order' => 10],
            ['code' => 'sofa', 'name_en' => 'Sofa / Furniture Repair', 'name_ta' => 'சோபா / மரச்சாமான் பழுது', 'icon_key' => 'weekend', 'default_visit_fee_paise' => 20000, 'base_price_paise' => 20000, 'sort_order' => 11],
            ['code' => 'jumpstart', 'name_en' => 'Battery Jump-start', 'name_ta' => 'பேட்டரி ஜம்ப் ஸ்டார்ட்', 'icon_key' => 'battery_charging_full', 'default_visit_fee_paise' => 15000, 'base_price_paise' => 15000, 'sort_order' => 12],
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
            ['id' => 8, 'name' => 'Chennai', 'state' => 'Tamil Nadu', 'latitude' => 13.0827, 'longitude' => 80.2707],
            ['id' => 9, 'name' => 'Coimbatore', 'state' => 'Tamil Nadu', 'latitude' => 11.0168, 'longitude' => 76.9558],
            ['id' => 10, 'name' => 'Madurai', 'state' => 'Tamil Nadu', 'latitude' => 9.9252, 'longitude' => 78.1198],
            ['id' => 11, 'name' => 'Tiruchirappalli', 'state' => 'Tamil Nadu', 'latitude' => 10.7905, 'longitude' => 78.7047],
            ['id' => 12, 'name' => 'Salem', 'state' => 'Tamil Nadu', 'latitude' => 11.6643, 'longitude' => 78.1460],
            ['id' => 13, 'name' => 'Tirunelveli', 'state' => 'Tamil Nadu', 'latitude' => 8.7139, 'longitude' => 77.7567],
            ['id' => 14, 'name' => 'Erode', 'state' => 'Tamil Nadu', 'latitude' => 11.3410, 'longitude' => 77.7172],
            ['id' => 15, 'name' => 'Vellore', 'state' => 'Tamil Nadu', 'latitude' => 12.9165, 'longitude' => 79.1325],
            ['id' => 16, 'name' => 'Thanjavur', 'state' => 'Tamil Nadu', 'latitude' => 10.7869, 'longitude' => 79.1378],
            ['id' => 17, 'name' => 'Dindigul', 'state' => 'Tamil Nadu', 'latitude' => 10.3673, 'longitude' => 77.9803],
            ['id' => 18, 'name' => 'Thoothukudi', 'state' => 'Tamil Nadu', 'latitude' => 8.7642, 'longitude' => 78.1348],
            ['id' => 19, 'name' => 'Nagercoil', 'state' => 'Tamil Nadu', 'latitude' => 8.1833, 'longitude' => 77.4119],
            ['id' => 20, 'name' => 'Kanchipuram', 'state' => 'Tamil Nadu', 'latitude' => 12.8342, 'longitude' => 79.7036],
            ['id' => 21, 'name' => 'Karur', 'state' => 'Tamil Nadu', 'latitude' => 10.9601, 'longitude' => 78.0766],
            ['id' => 22, 'name' => 'Hosur', 'state' => 'Tamil Nadu', 'latitude' => 12.7409, 'longitude' => 77.8253],
            ['id' => 23, 'name' => 'Namakkal', 'state' => 'Tamil Nadu', 'latitude' => 11.2219, 'longitude' => 78.1652],
            ['id' => 24, 'name' => 'Sivakasi', 'state' => 'Tamil Nadu', 'latitude' => 9.4532, 'longitude' => 77.8024],
            ['id' => 25, 'name' => 'Tiruppur', 'state' => 'Tamil Nadu', 'latitude' => 11.1085, 'longitude' => 77.3411],
            ['id' => 26, 'name' => 'Ooty', 'state' => 'Tamil Nadu', 'latitude' => 11.4064, 'longitude' => 76.6932],
            ['id' => 27, 'name' => 'Kumbakonam', 'state' => 'Tamil Nadu', 'latitude' => 10.9617, 'longitude' => 79.3881],
            ['id' => 28, 'name' => 'Nagapattinam', 'state' => 'Tamil Nadu', 'latitude' => 10.7656, 'longitude' => 79.8445],
            ['id' => 29, 'name' => 'Mayiladuthurai', 'state' => 'Tamil Nadu', 'latitude' => 11.1015, 'longitude' => 79.6520],
            ['id' => 30, 'name' => 'Ariyalur', 'state' => 'Tamil Nadu', 'latitude' => 11.1401, 'longitude' => 79.0756],
            ['id' => 31, 'name' => 'Perambalur', 'state' => 'Tamil Nadu', 'latitude' => 11.2340, 'longitude' => 78.8820],
            ['id' => 32, 'name' => 'Dharmapuri', 'state' => 'Tamil Nadu', 'latitude' => 12.1211, 'longitude' => 78.1582],
            ['id' => 33, 'name' => 'Krishnagiri', 'state' => 'Tamil Nadu', 'latitude' => 12.5186, 'longitude' => 78.2137],
            ['id' => 34, 'name' => 'Theni', 'state' => 'Tamil Nadu', 'latitude' => 10.0104, 'longitude' => 77.4778],
            ['id' => 35, 'name' => 'Tenkasi', 'state' => 'Tamil Nadu', 'latitude' => 8.9558, 'longitude' => 77.3153],
            ['id' => 36, 'name' => 'Chengalpattu', 'state' => 'Tamil Nadu', 'latitude' => 12.6819, 'longitude' => 79.9888],
            ['id' => 37, 'name' => 'Tiruvannamalai', 'state' => 'Tamil Nadu', 'latitude' => 12.2253, 'longitude' => 79.0747],
            ['id' => 38, 'name' => 'Ranipet', 'state' => 'Tamil Nadu', 'latitude' => 12.9279, 'longitude' => 79.3197],
            ['id' => 39, 'name' => 'Tirupattur', 'state' => 'Tamil Nadu', 'latitude' => 12.4983, 'longitude' => 78.5609],
            ['id' => 40, 'name' => 'Pudukkottai', 'state' => 'Tamil Nadu', 'latitude' => 10.3797, 'longitude' => 78.8208],
            ['id' => 41, 'name' => 'Sivaganga', 'state' => 'Tamil Nadu', 'latitude' => 9.8433, 'longitude' => 78.4809],
            ['id' => 42, 'name' => 'Ramanathapuram', 'state' => 'Tamil Nadu', 'latitude' => 9.3639, 'longitude' => 78.8395],
            ['id' => 43, 'name' => 'Karaikudi', 'state' => 'Tamil Nadu', 'latitude' => 10.0667, 'longitude' => 78.7833],
            ['id' => 44, 'name' => 'Chidambaram', 'state' => 'Tamil Nadu', 'latitude' => 11.3994, 'longitude' => 79.6914],
            ['id' => 45, 'name' => 'Kallakurichi', 'state' => 'Tamil Nadu', 'latitude' => 11.7460, 'longitude' => 78.9595],
            ['id' => 46, 'name' => 'Ambur', 'state' => 'Tamil Nadu', 'latitude' => 12.7916, 'longitude' => 78.7164],
            ['id' => 47, 'name' => 'Pollachi', 'state' => 'Tamil Nadu', 'latitude' => 10.6589, 'longitude' => 77.0083],
            ['id' => 48, 'name' => 'Rajapalayam', 'state' => 'Tamil Nadu', 'latitude' => 9.4529, 'longitude' => 77.5534],
            ['id' => 49, 'name' => 'Yanam', 'state' => 'Puducherry', 'latitude' => 16.7340, 'longitude' => 82.2176],
            ['id' => 50, 'name' => 'Mahe', 'state' => 'Puducherry', 'latitude' => 11.7010, 'longitude' => 75.5340],
            ['id' => 51, 'name' => 'Bengaluru', 'state' => 'Karnataka', 'latitude' => 12.9716, 'longitude' => 77.5946],
            ['id' => 52, 'name' => 'Hyderabad', 'state' => 'Telangana', 'latitude' => 17.3850, 'longitude' => 78.4867],
            ['id' => 53, 'name' => 'Visakhapatnam', 'state' => 'Andhra Pradesh', 'latitude' => 17.6868, 'longitude' => 83.2185],
            ['id' => 54, 'name' => 'Thiruvananthapuram', 'state' => 'Kerala', 'latitude' => 8.5241, 'longitude' => 76.9366],
            ['id' => 55, 'name' => 'Kochi', 'state' => 'Kerala', 'latitude' => 9.9312, 'longitude' => 76.2673],
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
