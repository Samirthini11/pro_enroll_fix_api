<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;

/**
 * Flutter: OtpVerifyScreen — OTP verified via POST /v1/auth/otp/verify (JWT issued).
 * POST /v1/screens/auth-otp  (refresh profile / next_route with existing JWT)
 */
final class AuthOtpScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        if ($request->method !== 'POST' && $request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $this->ensurePro($request);
        $profile = $this->pros->profilePayload($this->uid($request));
        $isSignIn = (string) $request->input('mode', 'sign_up') === 'sign_in';

        $next = '/onboard/category';
        if ($isSignIn && ($profile['kyc_status'] ?? '') === 'verified') {
            $next = '/home';
        }

        Response::ok([
            'screen' => 'auth_otp',
            'verified' => true,
            'profile' => $profile,
            'next_route' => $next,
        ]);
    }
}
