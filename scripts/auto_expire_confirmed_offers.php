<?php

declare(strict_types=1);

/**
 * Cron: auto-cancel confirmed bookings the pro did not accept before scheduled_at
 * (≈ 1 hour by default). Notifies the customer.
 *
 *   php scripts/auto_expire_confirmed_offers.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use ProEnroll\Api\Services\BookingRepository;

$n = (new BookingRepository())->expireStaleConfirmedOffers();
echo "Auto-expired {$n} offer(s)\n";
