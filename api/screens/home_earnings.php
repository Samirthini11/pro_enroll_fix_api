<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;

/**
 * Flutter: EarningsTab
 * GET /v1/screens/home-earnings
 */
final class HomeEarningsScreen extends ScreenHandler
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

        Response::ok([
            'screen' => 'home_earnings',
            'summary' => [
                'today_paise' => 65000,
                'week_paise' => 420000,
                'month_paise' => 1850000,
                'payouts_this_month_paise' => 1620000,
                'pending_payout_paise' => 23000,
                'jobs_today' => 3,
            ],
        ]);
    }
}
