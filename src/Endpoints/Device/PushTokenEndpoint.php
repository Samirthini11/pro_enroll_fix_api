<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Device;

use ProEnroll\Api\Auth\FirebaseCredentials;
use ProEnroll\Api\Config;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\JwtTokenMiddleware;
use ProEnroll\Api\Services\CustomerRepository;
use ProEnroll\Api\Services\DeviceTokenRepository;
use ProEnroll\Api\Services\ProRepository;

/**
 * POST /v1/device/push-token
 * Body: { "fcm_token", "platform"?: "android"|"ios", "role"?: "professional"|"customer" }
 *
 * Registers the same FCM token for every account tied to this phone (customer + pro when enrolled).
 */
final class PushTokenEndpoint
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

        $token = trim((string) $request->input('fcm_token', ''));
        if ($token === '' || strlen($token) > 512) {
            Response::fail('Invalid fcm_token', 422, 'validation');
            return;
        }

        $platform = (string) $request->input('platform', 'android');
        if (!in_array($platform, ['android', 'ios', 'web'], true)) {
            $platform = 'android';
        }

        $phone = trim((string) ($request->authUser['phone'] ?? ''));
        if ($phone === '') {
            Response::fail('Invalid session', 401, 'unauthorized');
            return;
        }

        $deviceLabel = (string) $request->input('device_label', 'ProConnect App');
        $registerAll = $request->input('register_all_roles', true);
        if (is_string($registerAll)) {
            $registerAll = !in_array(strtolower($registerAll), ['0', 'false', 'no'], true);
        } else {
            $registerAll = (bool) $registerAll;
        }

        $requestedRole = (string) $request->input('role', '');
        $rolesToRegister = $registerAll
            ? ['customer', 'professional']
            : [$this->normalizeRole($requestedRole, (string) ($request->authUser['role'] ?? 'professional'))];

        $repo = new DeviceTokenRepository();
        $registeredRoles = [];
        $authUids = [];

        try {
            foreach ($rolesToRegister as $role) {
                $authUid = $this->resolveAuthUid($phone, $role, (string) ($request->authUser['sub'] ?? ''));
                if ($authUid === '') {
                    continue;
                }

                $repo->upsert(
                    $authUid,
                    $phone,
                    $token,
                    $platform,
                    $role,
                    $deviceLabel !== '' ? $deviceLabel : null,
                );
                $registeredRoles[] = $role;
                $authUids[$role] = $authUid;
            }
        } catch (\Throwable $e) {
            Response::fail(
                Config::bool('APP_DEBUG') ? $e->getMessage() : 'Could not save push token',
                503,
                'push_token_store_failed',
            );
            return;
        }

        if ($registeredRoles === []) {
            Response::fail('Could not resolve account for push token', 422, 'validation');
            return;
        }

        Response::ok([
            'registered' => true,
            'roles' => $registeredRoles,
            'auth_uids' => $authUids,
            'fcm_configured' => FirebaseCredentials::isAvailable(),
            'fcm_http_v1_ready' => FirebaseCredentials::diagnostics()['oauth_messaging_ok'] ?? false,
            'fcm_setup_hint' => FirebaseCredentials::isAvailable()
                ? null
                : FirebaseCredentials::setupHint(),
        ]);
    }

    private function normalizeRole(string $role, string $jwtRole): string
    {
        if (in_array($role, ['professional', 'customer'], true)) {
            return $role;
        }

        return $jwtRole === 'customer' ? 'customer' : 'professional';
    }

    private function resolveAuthUid(string $phone, string $role, string $jwtSub): string
    {
        if ($role === 'customer') {
            $customer = (new CustomerRepository())->upsertFromPhone($phone);
            $authUid = (string) ($customer['auth_uid'] ?? '');
            if ($authUid !== '') {
                return $authUid;
            }
        } else {
            $pro = (new ProRepository())->findByPhone($phone);
            if ($pro !== null) {
                $uid = (string) ($pro['firebase_uid'] ?? '');
                if ($uid !== '') {
                    return $uid;
                }
            }

            return '';
        }

        return $jwtSub;
    }
}
