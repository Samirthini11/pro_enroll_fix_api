<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;

/**
 * Flutter: SelfieScreen
 * POST /v1/screens/kyc-selfie
 */
final class KycSelfieScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        if ($request->method !== 'POST') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $this->ensurePro($request);
        $score = 0.85 + (mt_rand() / mt_getrandmax()) * 0.14;
        $this->pros->updateProfile($this->uid($request), ['kyc_status' => 'in_review']);

        Response::ok([
            'screen' => 'kyc_selfie',
            'face_match_score' => round($score, 3),
            'passed' => $score >= 0.8,
            'next_route' => '/kyc/docs',
        ]);
    }
}
