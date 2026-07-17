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
        self::safe(
            static fn () => (new PushNotificationService())->notifyProfessionalNewBooking($pro, $booking),
            'new_booking_pro',
        );
    }

    public static function acceptedForCustomer(array $booking, ?array $pro = null): void
    {
        self::safe(
            static fn () => (new PushNotificationService())->notifyCustomerBookingAccepted($booking, $pro),
            'booking_accepted_customer',
        );
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

    public static function cancelledForPro(array $booking): void
    {
        self::safe(
            static fn () => (new PushNotificationService())->notifyProfessionalBookingCancelled($booking),
            'booking_cancelled_pro',
        );
    }

    public static function visitFeePaidForPro(array $booking): void
    {
        self::safe(
            static fn () => (new PushNotificationService())->notifyProfessionalVisitFeePaid($booking),
            'visit_fee_paid_pro',
        );
    }

    /** @param callable(): int $fn */
    private static function safe(callable $fn, string $label = 'push'): void
    {
        try {
            $sent = $fn();
            if ($sent === 0) {
                $push = new PushNotificationService();
                error_log(sprintf(
                    'Booking push %s: 0 messages sent (fcm_configured=%s)',
                    $label,
                    $push->isConfigured() ? 'yes' : 'no',
                ));
            }
        } catch (\Throwable $e) {
            error_log('Booking push failed (' . $label . '): ' . $e->getMessage());
        }
    }
}
