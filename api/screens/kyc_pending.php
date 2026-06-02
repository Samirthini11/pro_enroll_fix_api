<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;

/**
 * Flutter: PendingReviewScreen
 * GET /v1/screens/kyc-pending
 * POST /v1/screens/kyc-pending/simulate-approval  (dev only)
 */
final class KycPendingScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        $this->ensurePro($request);
        $uid = $this->uid($request);

        if ($request->method === 'POST' && str_ends_with($request->path, '/simulate-approval')) {
            if (!\ProEnroll\Api\Config::bool('APP_DEBUG')) {
                Response::fail('Not available', 403);
                return;
            }
            $this->pros->updateProfile($uid, ['kyc_status' => 'verified']);
            Response::ok([
                'screen' => 'kyc_pending',
                'kyc_status' => 'verified',
                'next_route' => '/home',
            ]);
            return;
        }

        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $profile = $this->pros->profilePayload($uid);
        Response::ok([
            'screen' => 'kyc_pending',
            'kyc_status' => $profile['kyc_status'] ?? 'in_review',
            'eta_hours' => 24,
            'profile' => $profile,
        ]);
    }
}
