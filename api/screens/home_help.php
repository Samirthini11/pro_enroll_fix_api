<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;

/**
 * Flutter: HelpTab
 * GET /v1/screens/home-help
 */
final class HomeHelpScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        Response::ok([
            'screen' => 'home_help',
            'faq' => [
                ['q' => 'When do I get paid?', 'a' => 'Visit fees are paid out daily by 7 PM to your UPI.'],
                ['q' => 'How is KYC verified?', 'a' => 'Aadhaar OTP + live selfie; review within 24 hours.'],
                ['q' => 'Can I reject a job?', 'a' => 'Yes before accept; after accept use support for cancellations.'],
            ],
            'support_phone' => '+914132000000',
            'support_whatsapp' => '+919876543210',
        ]);
    }
}
