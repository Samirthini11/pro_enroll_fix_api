<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\BookingRepository;

/**
 * Flutter: EarningsTab
 * GET /v1/screens/home-earnings
 */
final class HomeEarningsScreen extends ScreenHandler
{
    /** @return array<string, int> */
    private static function emptySummary(): array
    {
        return [
            'today_paise' => 0,
            'week_paise' => 0,
            'month_paise' => 0,
            'payouts_this_month_paise' => 0,
            'pending_payout_paise' => 0,
            'jobs_today' => 0,
        ];
    }

    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $pro = $this->proRow($request);
        if ($pro === null) {
            Response::ok([
                'screen' => 'home_earnings',
                'summary' => self::emptySummary(),
                'rating_avg' => 0,
                'rating_count' => 0,
                'jobs_completed' => 0,
            ]);
            return;
        }

        try {
            $bookings = new BookingRepository();
            $summary = $bookings->earningsSummaryForProfessional((int) $pro['id']);
        } catch (\Throwable) {
            $summary = self::emptySummary();
        }

        Response::ok([
            'screen' => 'home_earnings',
            'summary' => $summary,
            'rating_avg' => (float) ($pro['rating_avg'] ?? 0),
            'rating_count' => (int) ($pro['rating_count'] ?? 0),
            'jobs_completed' => (int) ($pro['jobs_completed'] ?? 0),
            'listing_held' => (bool) ($pro['listing_held'] ?? false),
            'free_bookings_used' => (int) ($pro['free_bookings_used'] ?? 0),
        ]);
    }
}
