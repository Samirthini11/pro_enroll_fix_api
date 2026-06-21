<?php

declare(strict_types=1);

/**
 * End-to-end location flow test (Pro Fix customer + Pro Enroll pro).
 * Run: C:\xampp\php\php.exe scripts/test_location_flow.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use ProEnroll\Api\Database;
use ProEnroll\Api\Services\BookingRepository;
use ProEnroll\Api\Services\ProRepository;

$failures = [];

function check(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "  OK  $label\n";
    } else {
        echo " FAIL $label\n";
        $failures[] = $label;
    }
}

echo "=== Location flow integration test ===\n\n";

$db = Database::connection();
$pros = new ProRepository();
$bookings = new BookingRepository();

// ── 1. Pro has home geo in MySQL ────────────────────────────────────────
echo "1. Pro location (onboarding → professionals.home_lat/lng)\n";
$pro = $pros->findById(1);
check($pro !== null, 'Pro #1 exists');
check($pro !== null && $pro['home_lat'] !== null && $pro['home_lng'] !== null, 'Pro #1 has home_lat/lng saved');
if ($pro !== null) {
    echo "     home: {$pro['home_lat']}, {$pro['home_lng']} radius={$pro['work_radius_km']}km\n";
}

// ── 2. Customer search with GPS coords ──────────────────────────────────
echo "\n2. Pro Fix — search pros near customer GPS (Pondicherry)\n";
$customerLat = 11.9350;
$customerLng = 79.8120;
$results = $pros->searchForCustomer(1, null, null, $customerLat, $customerLng);
check($results !== [], 'Search returns pros in city_id=1');
$found = false;
foreach ($results as $r) {
    if ($r['id'] === '1') {
        $found = true;
        check(isset($r['distance_km']) && $r['distance_km'] > 0, 'Pro #1 has distance_km from GPS');
        echo "     Pro #1 distance: {$r['distance_km']} km\n";
        check(
            $r['distance_km'] <= (int) $r['work_radius_km'] + 0.1,
            'Pro #1 within work_radius (or close — filter may apply)',
        );
    }
}
check($found, 'Pro #1 appears in nearby search');

// ── 3. Customer booking with geo ────────────────────────────────────────
echo "\n3. Pro Fix — create booking with address_lat/lng\n";
$cust = $db->query("SELECT id FROM customers ORDER BY id ASC LIMIT 1")->fetch();
check($cust !== false, 'At least one customer in DB');
$bookingId = null;
if ($cust !== false && $pro !== null) {
    $row = $bookings->create([
        'customer_id' => (int) $cust['id'],
        'professional_id' => 1,
        'category_code' => 'ac',
        'problem_description' => 'Location flow test — AC not cooling',
        'address_text' => 'Beach Road, Pondicherry',
        'address_lat' => 11.9340,
        'address_lng' => 79.8150,
        'city_id' => 1,
        'visit_fee_paise' => 20000,
        'scheduled_at' => date('Y-m-d H:i:s', time() + 7200),
    ]);
    $bookingId = (int) $row['id'];
    check($row['address_lat'] !== null && $row['address_lng'] !== null, 'Booking geo saved in MySQL');
    echo "     booking_id=$bookingId lat={$row['address_lat']} lng={$row['address_lng']}\n";
}

// ── 4. Pro jobs near you ────────────────────────────────────────────────
echo "\n4. Pro Enroll — job offers use booking GPS for distance\n";
if ($bookingId !== null && $pro !== null) {
    $offers = $bookings->listOffersForProfessional(1, ['ac', 'plumber']);
    $match = null;
    foreach ($offers as $o) {
        if ((int) $o['id'] === $bookingId) {
            $match = $o;
            break;
        }
    }
    check($match !== null, 'New booking appears as offer for pro #1');
    if ($match !== null) {
        $payload = $bookings->offerPayload(
            $match,
            (float) $pro['home_lat'],
            (float) $pro['home_lng'],
        );
        check($payload['distance_km'] > 0 && $payload['distance_km'] < 50, 'Offer distance computed from booking GPS');
        echo "     offer distance: {$payload['distance_km']} km\n";
    }
}

// ── 5. Accept offer → active job ────────────────────────────────────────
echo "\n5. Pro Enroll — accept offer persists active job\n";
if ($bookingId !== null) {
    $active = $bookings->acceptOffer($bookingId, 1);
    check($active !== null, 'Accept offer sets status en_route');
    if ($active !== null) {
        $job = $bookings->activeJobPayload(
            $active,
            (float) ($pro['home_lat'] ?? 0),
            (float) ($pro['home_lng'] ?? 0),
        );
        check($job['status'] === 'on_the_way', 'Active job status mapped for app');
        echo "     active job distance: {$job['distance_km']} km\n";
    }
    // Reset for repeated runs
    $db->prepare(
        "UPDATE service_bookings SET status='confirmed', updated_at=NOW() WHERE id=?"
    )->execute([$bookingId]);
}

// ── Summary ─────────────────────────────────────────────────────────────
echo "\n=== Summary ===\n";
if ($failures === []) {
    echo "All location flow checks passed.\n";
    exit(0);
}

echo count($failures) . " check(s) failed:\n";
foreach ($failures as $f) {
    echo "  - $f\n";
}
exit(1);
