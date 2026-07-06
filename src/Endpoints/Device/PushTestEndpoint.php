<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Device;

use ProEnroll\Api\Auth\FirebaseCredentials;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\JwtTokenMiddleware;
use ProEnroll\Api\Services\CustomerRepository;
use ProEnroll\Api\Services\DeviceTokenRepository;
use ProEnroll\Api\Services\ProRepository;
use ProEnroll\Api\Services\PushNotificationService;

/**
 * POST /v1/device/push-test
 * Sends a test notification to FCM tokens registered for the current user (Bearer JWT).
 *
 * Body (optional): { "title": "...", "body": "...", "role": "professional"|"customer" }
 */
final class PushTestEndpoint
{
    public function handle(Request $request): void
    {
        if (!JwtTokenMiddleware::require($request)) {
            return;
        }

        if ($request->method !== 'POST') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $phone = trim((string) ($request->authUser['phone'] ?? ''));
        if ($phone === '') {
            Response::fail('Phone missing from session', 401, 'unauthorized');
            return;
        }

        if (!FirebaseCredentials::isAvailable()) {
            Response::fail(FirebaseCredentials::setupHint(), 503, 'fcm_not_configured');
            return;
        }

        $roleFilter = (string) $request->input('role', '');
        $roles = in_array($roleFilter, ['professional', 'customer'], true)
            ? [$roleFilter]
            : ['professional', 'customer'];

        $repo = new DeviceTokenRepository();
        $tokens = [];
        foreach ($roles as $role) {
            $tokens = array_merge($tokens, $repo->tokensForPhone($phone, $role));
            $authUid = $this->authUidForRole($phone, $role, (string) ($request->authUser['sub'] ?? ''));
            if ($authUid !== '') {
                $tokens = array_merge($tokens, $repo->tokensForAuthUid($authUid, $role));
            }
        }
        $tokens = array_values(array_unique(array_filter($tokens)));

        if ($tokens === []) {
            Response::fail(
                'No FCM tokens registered. Call POST /v1/device/push-token after login.',
                404,
                'no_device_tokens',
            );
            return;
        }

        $title = trim((string) $request->input('title', 'Pro-Enroll test'));
        $body = trim((string) $request->input('body', 'Push notifications are working.'));
        if ($title === '') {
            $title = 'Pro-Enroll test';
        }
        if ($body === '') {
            $body = 'Push notifications are working.';
        }

        $push = new PushNotificationService();
        $sent = $push->sendToTokens($tokens, $title, $body, [
            'type' => 'test',
            'route' => '/',
        ]);

        Response::ok([
            'sent' => $sent,
            'tokens_found' => count($tokens),
            'fcm_http_v1_ready' => FirebaseCredentials::diagnostics()['oauth_messaging_ok'] ?? false,
            'phone_e164' => $phone,
            'roles_checked' => $roles,
        ], $sent > 0 ? 200 : 502);
    }

    private function authUidForRole(string $phone, string $role, string $jwtSub): string
    {
        if ($role === 'customer') {
            $customer = (new CustomerRepository())->findByPhone($phone);

            return (string) ($customer['auth_uid'] ?? $jwtSub);
        }

        $pro = (new ProRepository())->findByPhone($phone);

        return (string) ($pro['firebase_uid'] ?? $jwtSub);
    }
}
