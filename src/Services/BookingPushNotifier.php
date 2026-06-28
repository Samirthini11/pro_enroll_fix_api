<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

/**
 * Fire-and-forget booking push alerts — never blocks API responses.
 */
final class BookingPushNotifier
{
    public static function newBookingForPro(array $pro, array $booking): void
    {
        self::safe(static fn () => (new PushNotificationService())->notifyProfessionalNewBooking($pro, $booking));
    }

    public static function acceptedForCustomer(array $booking, ?array $pro = null): void
    {
        self::safe(static fn () => (new PushNotificationService())->notifyCustomerBookingAccepted($booking, $pro));
    }

    public static function rejectedForCustomer(array $booking, ?array $pro = null): void
    {
        self::safe(static fn () => (new PushNotificationService())->notifyCustomerBookingRejected($booking, $pro));
    }

    public static function statusForCustomer(array $booking, string $status, ?array $pro = null): void
    {
        self::safe(static fn () => (new PushNotificationService())->notifyCustomerJobStatus($booking, $status, $pro));
    }

    public static function completedForCustomer(array $booking, ?array $pro = null, ?int $finalAmountPaise = null): void
    {
        self::safe(static fn () => (new PushNotificationService())->notifyCustomerBookingCompleted($booking, $pro, $finalAmountPaise));
    }

    public static function confirmedForCustomer(array $booking, ?array $pro = null): void
    {
        self::safe(static fn () => (new PushNotificationService())->notifyCustomerBookingConfirmed($booking, $pro));
    }

    /** @param callable(): int $fn */
    private static function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            error_log('Booking push failed: ' . $e->getMessage());
        }
    }
}
