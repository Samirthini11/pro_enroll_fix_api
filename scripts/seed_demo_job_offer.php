<?php

declare(strict_types=1);

/**
 * Seed a demo customer booking so the pro app "Jobs near you" tab shows a real offer.
 *
 * Usage (from pro_enroll_api root, MySQL running):
 *   php scripts/seed_demo_job_offer.php
 *   php scripts/seed_demo_job_offer.php --pro-phone=+919876543210
 */

require __DIR__ . '/../vendor/autoload.php';

use ProEnroll\Api\Database;
use ProEnroll\Api\Services\BookingRepository;
use ProEnroll\Api\Services\ProRepository;

$proPhone = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--pro-phone=')) {
        $proPhone = substr($arg, strlen('--pro-phone='));
    }
}

$db = Database::connection();
$pros = new ProRepository();

if ($proPhone !== null) {
    $pro = $pros->findByPhone($proPhone);
} else {
    $stmt = $db->query(
        "SELECT p.* FROM professionals p
         INNER JOIN professional_skills ps ON ps.professional_id = p.id
         WHERE p.full_name IS NOT NULL
         ORDER BY p.id ASC
         LIMIT 1"
    );
    $pro = $stmt->fetch() ?: null;
}

if ($pro === null) {
    fwrite(STDERR, "No professional found. Complete pro onboarding first or pass --pro-phone=+91...\n");
    exit(1);
}

$proId = (int) $pro['id'];
$skills = $pros->getSkills($proId);
if ($skills === []) {
    fwrite(STDERR, "Pro #{$proId} has no skills. Run onboard-category first.\n");
    exit(1);
}

$category = $skills[0]['category_code'];
$cityId = (int) ($pro['city_id'] ?? 1);

$customerUid = 'seed_cust_' . bin2hex(random_bytes(4));
$db->prepare(
    'INSERT INTO customers (auth_uid, phone_e164, full_name, city_id, created_at, updated_at)
     VALUES (?, ?, ?, ?, NOW(), NOW())'
)->execute([
    $customerUid,
    '+919999' . random_int(100000, 999999),
    'Demo Customer',
    $cityId,
]);
$customerId = (int) $db->lastInsertId();

$bookings = new BookingRepository();
$row = $bookings->create([
    'customer_id' => $customerId,
    'professional_id' => $proId,
    'category_code' => $category,
    'problem_description' => 'AC not cooling — bedroom split AC, 1.5T (seeded demo job)',
    'address_text' => 'Mission Street, Pondicherry',
    'address_lat' => 11.9416,
    'address_lng' => 79.8083,
    'city_id' => $cityId,
    'visit_fee_paise' => (int) ($pro['visit_fee_paise'] ?? 15000),
    'scheduled_at' => date('Y-m-d H:i:s', time() + 3600),
]);

echo "Seeded job offer for pro #{$proId} ({$pro['full_name']})\n";
echo "  booking_id: {$row['id']}\n";
echo "  booking_code: {$row['booking_code']}\n";
echo "  category: {$category}\n";
echo "  customer: Demo Customer (#{$customerId})\n";
echo "\nOpen the pro app Jobs tab (online) to see the offer.\n";
