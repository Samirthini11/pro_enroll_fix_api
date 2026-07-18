<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\BookingRepository;

/**
 * Flutter: WalletTab + EarningsTab
 * GET  /v1/screens/home-earnings
 * POST /v1/screens/home-earnings  { "action": "mark_platform_fee_paid", "utr": "..." }
 */
final class HomeEarningsScreen extends ScreenHandler
{
    /** @return array<string, mixed> */
    private static function emptySummary(): array
    {
        return [
            'today_paise' => 0,
            'week_paise' => 0,
            'month_paise' => 0,
            'payouts_this_month_paise' => 0,
            'pending_payout_paise' => 0,
            'jobs_today' => 0,
            'platform_fee_due_paise' => 0,
        ];
    }

    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        $pro = $this->proRow($request);
        if ($pro === null) {
            Response::ok([
                'screen' => 'home_earnings',
                'summary' => self::emptySummary(),
                'credit_history' => [],
                'rating_avg' => 0,
                'rating_count' => 0,
                'jobs_completed' => 0,
            ]);
            return;
        }

        $bookings = new BookingRepository();
        $proId = (int) $pro['id'];

        if ($request->method === 'POST') {
            $action = (string) $request->input('action', '');
            if ($action !== 'mark_platform_fee_paid') {
                Response::fail('Unknown action', 422, 'validation');
                return;
            }

            $utr = trim((string) $request->input('utr', ''));
            if ($utr === '') {
                Response::fail('Enter UTR number after paying via UPI', 422, 'utr_required');
                return;
            }

            try {
                $updated = $bookings->markPlatformFeePaidViaUpi($proId, $utr);
            } catch (\InvalidArgumentException $e) {
                Response::fail($e->getMessage(), 422, 'validation');
                return;
            }

            if ($updated < 1) {
                Response::fail('No unpaid platform fee found', 400, 'nothing_to_pay');
                return;
            }

            $summary = $bookings->earningsSummaryForProfessional($proId);
            Response::ok([
                'screen' => 'home_earnings',
                'marked_paid' => $updated,
                'utr' => strtoupper(preg_replace('/\s+/', '', $utr) ?? $utr),
                'summary' => $summary,
                'credit_history' => $bookings->creditHistoryForProfessional($proId),
                'rating_avg' => (float) ($pro['rating_avg'] ?? 0),
                'rating_count' => (int) ($pro['rating_count'] ?? 0),
                'jobs_completed' => (int) ($pro['jobs_completed'] ?? 0),
            ]);
            return;
        }

        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        try {
            $summary = $bookings->earningsSummaryForProfessional($proId);
            $history = $bookings->creditHistoryForProfessional($proId);
        } catch (\Throwable) {
            $summary = self::emptySummary();
            $history = [];
        }

        Response::ok([
            'screen' => 'home_earnings',
            'summary' => $summary,
            'credit_history' => $history,
            'rating_avg' => (float) ($pro['rating_avg'] ?? 0),
            'rating_count' => (int) ($pro['rating_count'] ?? 0),
            'jobs_completed' => (int) ($pro['jobs_completed'] ?? 0),
            'listing_held' => (bool) ($pro['listing_held'] ?? false),
            'free_bookings_used' => (int) ($pro['free_bookings_used'] ?? 0),
        ]);
    }
}
