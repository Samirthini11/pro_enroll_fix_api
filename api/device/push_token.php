<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Device;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\JwtTokenMiddleware;
use ProEnroll\Api\Services\DeviceTokenRepository;

/**
 * POST /v1/device/push-token
 * Body: { "fcm_token", "platform"?: "android"|"ios", "role"?: "professional"|"customer" }
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

        $jwtRole = (string) ($request->authUser['role'] ?? 'professional');
        $role = (string) $request->input('role', $jwtRole);
        if (!in_array($role, ['professional', 'customer'], true)) {
            $role = $jwtRole === 'customer' ? 'customer' : 'professional';
        }

        $authUid = (string) ($request->authUser['sub'] ?? '');
        $phone = (string) ($request->authUser['phone'] ?? '');
        if ($authUid === '' || $phone === '') {
            Response::fail('Invalid session', 401, 'unauthorized');
            return;
        }

        $deviceLabel = (string) $request->input('device_label', 'ProConnect App');

        (new DeviceTokenRepository())->upsert(
            $authUid,
            $phone,
            $token,
            $platform,
            $role,
            $deviceLabel !== '' ? $deviceLabel : null,
        );

        Response::ok(['registered' => true]);
    }
}
