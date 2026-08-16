<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\ReferralService;

/**
 * Flutter: Refer & Earn (Profile)
 * GET  /v1/screens/home-refer
 * POST /v1/screens/home-refer  { "action": "apply", "referral_code": "PE..." }
 */
final class HomeReferScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        $pro = $this->ensurePro($request);
        $proId = (int) $pro['id'];
        $referrals = new ReferralService();

        if ($request->method === 'GET') {
            Response::ok([
                'screen' => 'home_refer',
                'refer' => $referrals->payloadForProfessional($proId),
            ]);
            return;
        }

        if ($request->method === 'POST') {
            $action = strtolower(trim((string) $request->input('action', 'apply')));
            if ($action !== 'apply') {
                Response::fail('Unknown action', 422, 'validation');
                return;
            }

            $code = (string) $request->input('referral_code', $request->input('code', ''));
            $result = $referrals->applyReferralCode($proId, $code);
            if (!$result['ok']) {
                Response::fail($result['message'], 422, 'referral_apply_failed');
                return;
            }

            Response::ok([
                'screen' => 'home_refer',
                'applied' => true,
                'message' => $result['message'],
                'refer' => $referrals->payloadForProfessional($proId),
            ]);
            return;
        }

        Response::fail('Method not allowed', 405, 'method_not_allowed');
    }
}
