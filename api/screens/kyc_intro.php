<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;

/**
 * Flutter: KycIntroScreen
 * GET /v1/screens/kyc-intro
 */
final class KycIntroScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $this->ensurePro($request);
        Response::ok([
            'screen' => 'kyc_intro',
            'steps' => [
                ['step' => 1, 'title' => 'Aadhaar', 'body' => 'OTP-based verification'],
                ['step' => 2, 'title' => 'Selfie', 'body' => 'Live face match'],
                ['step' => 3, 'title' => 'Documents', 'body' => 'Optional certificates'],
            ],
            'profile' => $this->pros->profilePayload($this->uid($request)),
        ]);
    }
}
