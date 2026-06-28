<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Device;

use ProEnroll\Api\Auth\FirebaseCredentials;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\DeviceTokenRepository;

/**
 * GET /v1/health/push — verify FCM HTTP v1 (service account) is ready on this server.
 */
final class PushHealthEndpoint
{
    public function handle(Request $request): void
    {
        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $diag = FirebaseCredentials::diagnostics();
        $tokenCount = 0;

        try {
            $repo = new DeviceTokenRepository();
            $tokenCount = count($repo->listRecentTokens(5));
        } catch (\Throwable) {
            // Table may not exist yet on fresh installs.
        }

        Response::ok([
            'push' => $diag,
            'device_tokens_sample_count' => $tokenCount,
            'setup' => [
                'step_1' => 'Firebase Console → proenroll-4ff13 → Service accounts → Generate new private key',
                'step_2' => 'Upload JSON to config/firebase-service-account.json on VPS',
                'step_3' => 'Set FIREBASE_CREDENTIALS=config/firebase-service-account.json in .env',
                'step_4' => 'Run composer install && composer dump-autoload -o',
                'not_sufficient' => ['google-services.json', 'Android API key', 'FCM device token alone'],
            ],
        ], $diag['fcm_http_v1_ready'] ? 200 : 503);
    }
}
