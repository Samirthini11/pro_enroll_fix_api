<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\ReferenceData;

/**
 * Flutter: VisitFeeScreen
 * PUT /v1/screens/onboard-fee
 */
final class OnboardFeeScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if ($request->method === 'GET') {
            Response::ok([
                'screen' => 'onboard_fee',
                'defaults_by_category' => ReferenceData::defaultFees(),
                'base_prices_by_category' => ReferenceData::basePrices(),
            ]);
            return;
        }

        if (!$this->requireAuth($request)) {
            return;
        }

        if ($request->method !== 'PUT') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $fee = (int) $request->input('visit_fee_paise', 0);
        if ($fee < 100) {
            Response::fail('visit_fee_paise required (min 100 paise)', 422);
            return;
        }

        $this->ensurePro($request);
        $this->pros->updateProfile($this->uid($request), ['visit_fee_paise' => $fee]);

        Response::ok([
            'screen' => 'onboard_fee',
            'visit_fee_paise' => $fee,
            'next_route' => '/kyc',
            'profile' => $this->pros->profilePayload($this->uid($request)),
        ]);
    }
}
