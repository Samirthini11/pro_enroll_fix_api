<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;

/**
 * Flutter: PhoneInputScreen — OTP is sent via POST /v1/auth/otp/send.
 * POST /v1/screens/auth-phone  (optional profile sync when JWT already issued)
 */
final class AuthPhoneScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        if ($request->method === 'GET') {
            Response::ok([
                'screen' => 'auth_phone',
                'hint' => 'POST /v1/auth/otp/send then /v1/auth/otp/verify to obtain JWT.',
                'country_code' => '+91',
            ]);
            return;
        }

        if ($request->method !== 'POST') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $mode = (string) $request->input('mode', 'sign_up');
        $pro = $this->ensurePro($request);

        Response::ok([
            'screen' => 'auth_phone',
            'mode' => $mode,
            'auth_uid' => $this->uid($request),
            'phone_e164' => $pro['phone_e164'] ?? $request->authUser['phone'],
            'profile' => $this->pros->profilePayload($this->uid($request)),
            'next_route' => ($pro['full_name'] ?? null) ? '/home' : '/onboard/category',
        ]);
    }
}
