<?php

declare(strict_types=1);

/**
 * Cron: auto-complete bookings stuck in awaiting_payment.
 *
 *   php scripts/auto_complete_awaiting_payment.php
 *   php scripts/auto_complete_awaiting_payment.php --hours=24
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use ProEnroll\Api\Services\BookingRepository;

$hours = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--hours=')) {
        $hours = (int) substr($arg, 8);
    }
}

$n = (new BookingRepository())->autoCompleteStaleAwaitingPayments($hours);
echo "Auto-completed {$n} booking(s)\n";
