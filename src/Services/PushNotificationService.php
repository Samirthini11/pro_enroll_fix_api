<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use ProEnroll\Api\Auth\FirebaseAuth;
use ProEnroll\Api\Config;

final class PushNotificationService
{
    public function isConfigured(): bool
    {
        return FirebaseAuth::isConfigured();
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
            return 0;
        }

        $messaging = $this->messaging();
        $sent = 0;

        foreach ($tokens as $token) {
            try {
                $message = CloudMessage::withTarget('token', $token)
                    ->withNotification(Notification::create($title, $body))
                    ->withData($data);
                $messaging->send($message);
                $sent++;
            } catch (\Throwable $e) {
                if (Config::bool('APP_DEBUG')) {
                    error_log('FCM send failed: ' . $e->getMessage());
                }
            }
        }

        return $sent;
    }

    public function notifyProfessionalNewBooking(array $pro, array $booking): int
    {
        $authUid = (string) ($pro['firebase_uid'] ?? '');
        if ($authUid === '') {
            return 0;
        }

        $tokens = (new DeviceTokenRepository())->tokensForAuthUid($authUid, 'professional');
        $category = (string) ($booking['category_code'] ?? 'service');
        $bookingId = (string) ($booking['id'] ?? '');

        return $this->sendToTokens(
            $tokens,
            'New job request',
            'A customer booked you for ' . strtoupper($category) . '. Open the app to accept.',
            [
                'type' => 'job_offer',
                'booking_id' => $bookingId,
                'route' => '/job/offer',
            ],
        );
    }

    private function messaging(): \Kreait\Firebase\Contract\Messaging
    {
        $path = $this->credentialsPath();
        if ($path === null) {
            throw new \RuntimeException('Firebase service account JSON not found');
        }

        return (new Factory())
            ->withServiceAccount($path)
            ->createMessaging();
    }

    private function credentialsPath(): ?string
    {
        $configured = Config::get('FIREBASE_CREDENTIALS');
        if ($configured !== null && $configured !== '') {
            if (is_readable($configured)) {
                return $configured;
            }
            $rootRelative = dirname(__DIR__, 2) . '/' . ltrim($configured, '/');
            if (is_readable($rootRelative)) {
                return $rootRelative;
            }
        }

        $default = dirname(__DIR__, 2) . '/config/firebase-service-account.json';
        return is_readable($default) ? $default : null;
    }
}
