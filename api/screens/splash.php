<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;

/**
 * Flutter: SplashScreen (/)
 * GET /v1/screens/splash
 */
final class SplashScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405, 'method_not_allowed');
            return;
        }

        Response::ok([
            'screen' => 'splash',
            'app_name' => 'Pro-Enroll',
            'min_app_version' => '1.0.0',
            'default_language' => 'en',
            'supported_languages' => [
                ['code' => 'en', 'label' => 'English', 'native_label' => 'English'],
                ['code' => 'ta', 'label' => 'Tamil', 'native_label' => 'தமிழ்'],
            ],
            'auth' => [
                'provider' => 'jwt_otp',
                'otp_send' => 'POST /v1/auth/otp/send',
                'otp_verify' => 'POST /v1/auth/otp/verify',
                'header' => 'Authorization: Bearer <jwt>',
            ],
        ]);
    }
}
