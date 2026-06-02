<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;

/**
 * Flutter: AuthLandingScreen (/auth/landing)
 * GET /v1/screens/auth-landing
 */
final class AuthLandingScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        Response::ok([
            'screen' => 'auth_landing',
            'value_props' => [
                ['icon' => 'verified', 'title' => 'KYC verified', 'body' => 'Build trust with customers'],
                ['icon' => 'payments', 'title' => 'Daily payouts', 'body' => 'UPI payout by 7 PM'],
                ['icon' => 'school', 'title' => 'Free training', 'body' => 'Upskill for higher earnings'],
            ],
            'modes' => ['sign_in', 'sign_up'],
        ]);
    }
}
