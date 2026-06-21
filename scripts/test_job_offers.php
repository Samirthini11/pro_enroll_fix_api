<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use ProEnroll\Api\Services\BookingRepository;
use ProEnroll\Api\Services\ProRepository;

$bookings = new BookingRepository();
$pros = new ProRepository();
$pro = $pros->findById(1);
if ($pro === null) {
    fwrite(STDERR, "Pro #1 not found\n");
    exit(1);
}

$skills = array_column($pros->getSkills(1), 'category_code');
$rows = $bookings->listOffersForProfessional(1, $skills);
echo count($rows) . " offer(s) for pro #1\n";
foreach ($rows as $row) {
    $offer = $bookings->offerPayload($row, 11.9, 79.8);
    echo "  - #{$offer['id']} {$offer['customer_name']}: {$offer['problem']}\n";
}

exit($rows === [] ? 1 : 0);
