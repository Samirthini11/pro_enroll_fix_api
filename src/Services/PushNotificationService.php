<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use Kreait\Firebase\Exception\Messaging\NotFound as FcmTokenNotFound;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use ProEnroll\Api\Auth\FirebaseCredentials;

final class PushNotificationService
{
    public function isConfigured(): bool
    {
        return FirebaseCredentials::isAvailable();
    }

    /**
     * @param array<string, string> $data
     */
    public function sendToTokens(
        array $tokens,
        string $title,
        string $body,
        array $data = [],
    ): int {
        $tokens = array_values(array_unique(array_filter($tokens)));
        if ($tokens === [] || !$this->isConfigured()) {
            if ($tokens !== [] && !$this->isConfigured()) {
                error_log('FCM skipped: ' . FirebaseCredentials::setupHint());
            }
            return 0;
        }

        $payload = [];
        foreach ($data as $key => $value) {
            $payload[(string) $key] = (string) $value;
        }
        $payload['title'] = $title;
        $payload['body'] = $body;

        $messaging = FirebaseCredentials::messaging();
        $tokenRepo = new DeviceTokenRepository();
        $sent = 0;

        foreach ($tokens as $token) {
            try {
                $message = CloudMessage::withTarget('token', $token)
                    ->withNotification(Notification::create($title, $body))
                    ->withData($payload)
                    ->withAndroidConfig(
                        AndroidConfig::fromArray([
                            'priority' => 'high',
                            'notification' => [
                                'channel_id' => 'proconnect_alerts',
                                'sound' => 'default',
                                'notification_priority' => 'PRIORITY_HIGH',
                            ],
                        ]),
                    );
                $messaging->send($message);
                $sent++;
            } catch (\Throwable $e) {
                if ($this->isInvalidFcmToken($e)) {
                    $tokenRepo->deleteByToken($token);
                    error_log('FCM removed stale token: ' . substr($token, 0, 12) . '…');
                } else {
                    error_log('FCM send failed: ' . $e->getMessage());
                }
            }
        }

        return $sent;
    }

    private function isInvalidFcmToken(\Throwable $e): bool
    {
        if ($e instanceof FcmTokenNotFound) {
            return true;
        }

        if ($e instanceof MessagingException) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'not found')
                || str_contains($message, 'not registered')
                || str_contains($message, 'invalid registration')
                || str_contains($message, 'unregistered')) {
                return true;
            }
        }

        return false;
    }

    public function notifyProfessionalNewBooking(array $pro, array $booking): int
    {
        $tokens = $this->professionalTokens($pro);
        if ($tokens === []) {
            error_log(sprintf(
                'Pro push: no tokens (pro_id=%s phone=%s booking_id=%s)',
                (string) ($pro['id'] ?? ''),
                (string) ($pro['phone_e164'] ?? ''),
                (string) ($booking['id'] ?? ''),
            ));
            return 0;
        }

        $category = strtoupper((string) ($booking['category_code'] ?? 'service'));
        $bookingId = (string) ($booking['id'] ?? '');

        return $this->sendToTokens(
            $tokens,
            'New job request',
            "A customer booked you for {$category}. Tap to accept or reject.",
            [
                'type' => 'job_offer',
                'booking_id' => $bookingId,
                'route' => '/job/offer',
            ],
        );
    }

    public function notifyProfessionalBookingCancelled(array $booking): int
    {
        $pro = $this->proForBooking($booking);
        if ($pro === null) {
            return 0;
        }

        $tokens = $this->professionalTokens($pro);
        if ($tokens === []) {
            return 0;
        }

        $code = (string) ($booking['booking_code'] ?? '');
        $bookingId = (string) ($booking['id'] ?? '');

        return $this->sendToTokens(
            $tokens,
            'Booking cancelled',
            'Customer cancelled booking' . ($code !== '' ? " {$code}" : '') . ' before you were on the way.',
            [
                'type' => 'booking_cancelled',
                'booking_id' => $bookingId,
                'route' => '/home',
            ],
        );
    }

    public function notifyProfessionalVisitFeePaid(array $booking): int
    {
        $pro = $this->proForBooking($booking);
        if ($pro === null) {
            return 0;
        }

        $tokens = $this->professionalTokens($pro);
        if ($tokens === []) {
            return 0;
        }

        $code = (string) ($booking['booking_code'] ?? '');
        $credit = isset($booking['pro_credit_paise']) && $booking['pro_credit_paise'] !== null
            ? (int) $booking['pro_credit_paise']
            : (int) ($booking['visit_fee_paise'] ?? 0);
        $creditText = $credit >= 100
            ? ' ₹' . number_format($credit / 100, 0) . ' credited to your wallet.'
            : ' Amount credited to your wallet.';

        return $this->sendToTokens(
            $tokens,
            'Visit fee paid',
            'Customer paid for booking' . ($code !== '' ? " {$code}" : '') . '.' . $creditText,
            [
                'type' => 'visit_fee_paid',
                'booking_id' => (string) ($booking['id'] ?? ''),
                'route' => '/home',
            ],
        );
    }

    public function notifyCustomerBookingConfirmed(array $booking, ?array $pro = null): int
    {
        $pro ??= $this->proForBooking($booking);
        $tokens = $this->customerTokensForBooking($booking);
        if ($tokens === []) {
            return 0;
        }

        $proName = trim((string) ($pro['full_name'] ?? 'Your pro'));
        $code = (string) ($booking['booking_code'] ?? '');
        $bookingId = (string) ($booking['id'] ?? '');

        return $this->sendToTokens(
            $tokens,
            'Booking confirmed',
            "{$proName} received your request" . ($code !== '' ? " ({$code})" : '') . '. Visit fee is paid after work is done.',
            [
                'type' => 'booking_confirmed',
                'booking_id' => $bookingId,
                'route' => '/customer/booking',
            ],
        );
    }

    public function notifyCustomerBookingAccepted(array $booking, ?array $pro = null): int
    {
        $pro ??= $this->proForBooking($booking);
        $tokens = $this->customerTokensForBooking($booking);
        if ($tokens === []) {
            error_log(sprintf(
                'Customer accept push skipped: no tokens (booking_id=%s customer_id=%s)',
                (string) ($booking['id'] ?? ''),
                (string) ($booking['customer_id'] ?? ''),
            ));
            return 0;
        }

        $proName = trim((string) ($pro['full_name'] ?? 'Your pro')) ?: 'Your pro';
        $bookingId = (string) ($booking['id'] ?? '');

        return $this->sendToTokens(
            $tokens,
            'Booking accepted',
            "{$proName} accepted your job and is on the way.",
            [
                'type' => 'booking_accepted',
                'booking_id' => $bookingId,
                'route' => '/customer/booking',
            ],
        );
    }

    public function notifyCustomerBookingRejected(array $booking, ?array $pro = null): int
    {
        $pro ??= $this->proForBooking($booking);
        $tokens = $this->customerTokensForBooking($booking);
        if ($tokens === []) {
            return 0;
        }

        $proName = trim((string) ($pro['full_name'] ?? 'The pro')) ?: 'The pro';
        $bookingId = (string) ($booking['id'] ?? '');

        return $this->sendToTokens(
            $tokens,
            'Booking declined',
            "{$proName} is unavailable for this booking. Try another pro nearby.",
            [
                'type' => 'booking_rejected',
                'booking_id' => $bookingId,
                'route' => '/customer/bookings',
            ],
        );
    }

    public function notifyCustomerJobStatus(array $booking, string $apiStatus, ?array $pro = null): int
    {
        $pro ??= $this->proForBooking($booking);
        $tokens = $this->customerTokensForBooking($booking);
        if ($tokens === []) {
            return 0;
        }

        $proName = trim((string) ($pro['full_name'] ?? 'Your pro')) ?: 'Your pro';
        $bookingId = (string) ($booking['id'] ?? '');

        [$title, $body] = match ($apiStatus) {
            'awaiting_payment' => ['Pay visit fee', "{$proName} finished the job. Pay the visit fee in the app to complete."],
            'on_the_way' => ['Pro on the way', "{$proName} is heading to your location."],
            'in_progress' => ['Work started', "{$proName} has started working on your job."],
            'completed' => ['Job update', "{$proName} marked the job as done."],
            default => ['Booking update', "Your booking status changed to {$apiStatus}."],
        };

        return $this->sendToTokens(
            $tokens,
            $title,
            $body,
            [
                'type' => 'booking_status',
                'booking_id' => $bookingId,
                'status' => $apiStatus,
                'route' => '/customer/booking',
            ],
        );
    }

    public function notifyCustomerBookingCompleted(array $booking, ?array $pro = null, ?int $finalAmountPaise = null): int
    {
        $pro ??= $this->proForBooking($booking);
        $tokens = $this->customerTokensForBooking($booking);
        if ($tokens === []) {
            return 0;
        }

        $proName = trim((string) ($pro['full_name'] ?? 'Your pro')) ?: 'Your pro';
        $bookingId = (string) ($booking['id'] ?? '');
        $amount = $finalAmountPaise ?? (isset($booking['final_amount_paise']) ? (int) $booking['final_amount_paise'] : null);
        $amountText = $amount !== null && $amount >= 100
            ? ' Amount: ₹' . number_format($amount / 100, 0)
            : '';

        return $this->sendToTokens(
            $tokens,
            'Job completed',
            "{$proName} completed your service.{$amountText}",
            [
                'type' => 'booking_completed',
                'booking_id' => $bookingId,
                'route' => '/customer/booking',
            ],
        );
    }

    /** @param array<string, mixed> $pro */
    private function professionalTokens(array $pro): array
    {
        $repo = new DeviceTokenRepository();
        $authUid = (string) ($pro['firebase_uid'] ?? '');
        $phone = (string) ($pro['phone_e164'] ?? '');

        $tokens = array_values(array_unique(array_merge(
            $authUid !== '' ? $repo->tokensForAuthUid($authUid, 'professional') : [],
            $phone !== '' ? $repo->tokensForPhone($phone, 'professional') : [],
        )));

        // Same device often registers under customer role only — include those too.
        if ($phone !== '') {
            $tokens = array_values(array_unique(array_merge(
                $tokens,
                $repo->tokensForPhone($phone, 'customer'),
            )));
        }

        return $tokens;
    }

    /** @param array<string, mixed> $booking */
    private function customerTokensForBooking(array $booking): array
    {
        $repo = new DeviceTokenRepository();
        $tokens = [];

        $customerId = (int) ($booking['customer_id'] ?? 0);
        if ($customerId >= 1) {
            $tokens = array_merge($tokens, $repo->tokensForCustomerId($customerId));

            $customer = (new CustomerRepository())->findById($customerId);
            if ($customer !== null) {
                $tokens = array_merge($tokens, $this->customerTokens($customer));
            }
        }

        foreach ($this->customerPhoneCandidates($booking) as $phone) {
            $tokens = array_merge($tokens, $repo->tokensForPhone($phone, 'customer'));
        }

        $unique = array_values(array_unique(array_filter($tokens)));
        if ($unique === []) {
            error_log(sprintf(
                'Customer push: no tokens (booking_id=%s customer_id=%s phones=%s)',
                (string) ($booking['id'] ?? ''),
                (string) ($booking['customer_id'] ?? ''),
                implode(',', $this->customerPhoneCandidates($booking)),
            ));
        }

        return $unique;
    }

    /** @param array<string, mixed> $customer */
    private function customerTokens(array $customer): array
    {
        $repo = new DeviceTokenRepository();
        $authUid = (string) ($customer['auth_uid'] ?? '');
        $phone = (string) ($customer['phone_e164'] ?? '');

        return array_values(array_unique(array_merge(
            $authUid !== '' ? $repo->tokensForAuthUid($authUid, 'customer') : [],
            $phone !== '' ? $repo->tokensForPhone($phone, 'customer') : [],
            $phone !== '' ? $repo->tokensForPhone($phone, 'professional') : [],
        )));
    }

    /** @param array<string, mixed> $booking
     * @return list<string>
     */
    private function customerPhoneCandidates(array $booking): array
    {
        $phones = [];
        foreach (['customer_phone', 'customer_phone_e164'] as $key) {
            $value = trim((string) ($booking[$key] ?? ''));
            if ($value !== '') {
                $phones[] = $value;
            }
        }

        $customerId = (int) ($booking['customer_id'] ?? 0);
        if ($customerId >= 1) {
            $customer = (new CustomerRepository())->findById($customerId);
            if ($customer !== null) {
                $phone = trim((string) ($customer['phone_e164'] ?? ''));
                if ($phone !== '') {
                    $phones[] = $phone;
                }
            }
        }

        return array_values(array_unique($phones));
    }

    /** @param array<string, mixed> $booking */
    private function proForBooking(array $booking): ?array
    {
        $proId = (int) ($booking['professional_id'] ?? 0);
        if ($proId < 1) {
            return null;
        }

        return (new ProRepository())->findById($proId);
    }
}
