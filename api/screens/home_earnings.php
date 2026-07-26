<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\BookingRepository;
use ProEnroll\Api\Services\PlatformSettingsRepository;
use ProEnroll\Api\Services\WalletLedgerRepository;
use ProEnroll\Api\Services\WalletRechargeRepository;

/**
 * Flutter: WalletTab + EarningsTab
 * GET  /v1/screens/home-earnings
 * POST /v1/screens/home-earnings
 *   { "action": "recharge_wallet", "amount_paise": 5000, "utr": "..." }  // pending admin approval
 *   { "action": "mark_platform_fee_paid", "utr": "..." }  // legacy unpaid fee clear
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
            'wallet_balance_paise' => 0,
            'jobs_today' => 0,
            'platform_fee_due_paise' => 0,
            'wallet_min_accept_paise' => 5000,
            'wallet_recharge_min_paise' => 5000,
            'visit_commission_percent' => 10,
            'free_booking_limit' => 5,
        ];
    }

    /** @return array<string, mixed> */
    private function payload(
        BookingRepository $bookings,
        WalletLedgerRepository $ledger,
        int $proId,
        array $pro,
    ): array {
        $summary = $bookings->earningsSummaryForProfessional($proId);
        $walletHistory = $ledger->history($proId);
        $creditHistory = $walletHistory !== []
            ? $walletHistory
            : $bookings->creditHistoryForProfessional($proId);

        $recharges = new WalletRechargeRepository();
        $summary['pending_recharge_paise'] = $recharges->pendingAmountPaise($proId);

        return [
            'screen' => 'home_earnings',
            'summary' => $summary,
            'credit_history' => $creditHistory,
            'wallet_history' => $walletHistory,
            'recharge_requests' => $recharges->listForProfessional($proId),
            'rating_avg' => (float) ($pro['rating_avg'] ?? 0),
            'rating_count' => (int) ($pro['rating_count'] ?? 0),
            'jobs_completed' => (int) ($pro['jobs_completed'] ?? 0),
            'listing_held' => (bool) ($pro['listing_held'] ?? false),
            'free_bookings_used' => (int) ($pro['free_bookings_used'] ?? 0),
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
                'wallet_history' => [],
                'rating_avg' => 0,
                'rating_count' => 0,
                'jobs_completed' => 0,
            ]);
            return;
        }

        $bookings = new BookingRepository();
        $ledger = new WalletLedgerRepository();
        $proId = (int) $pro['id'];

        if ($request->method === 'POST') {
            $action = (string) $request->input('action', '');

            if ($action === 'recharge_wallet') {
                $amountPaise = (int) $request->input('amount_paise', 0);
                $utr = trim((string) $request->input('utr', ''));
                if ($utr === '') {
                    Response::fail('Enter UTR number after paying via UPI', 422, 'utr_required');
                    return;
                }
                try {
                    // Wallet is credited only after an admin approves this request.
                    $submitted = (new WalletRechargeRepository())
                        ->submit($proId, $amountPaise, $utr);
                } catch (\InvalidArgumentException $e) {
                    Response::fail($e->getMessage(), 422, 'validation');
                    return;
                } catch (\Throwable $e) {
                    Response::fail($e->getMessage(), 500, 'recharge_failed');
                    return;
                }

                $pro = $this->proRow($request) ?? $pro;
                $out = $this->payload($bookings, $ledger, $proId, $pro);
                $out['recharge_request'] = $submitted;
                $out['message'] = 'Recharge submitted. Wallet is credited after admin approval.';
                $out['utr'] = strtoupper(preg_replace('/\s+/', '', $utr) ?? $utr);
                Response::ok($out);
                return;
            }

            if ($action === 'mark_platform_fee_paid') {
                $utr = trim((string) $request->input('utr', ''));
                if ($utr === '') {
                    Response::fail('Enter UTR number after paying via UPI', 422, 'utr_required');
                    return;
                }

                try {
                    $updated = $bookings->markPlatformFeePaidViaUpiAndSync($proId, $utr);
                } catch (\InvalidArgumentException $e) {
                    Response::fail($e->getMessage(), 422, 'validation');
                    return;
                }

                if ($updated < 1) {
                    Response::fail('No unpaid platform fee found — use wallet recharge instead', 400, 'nothing_to_pay');
                    return;
                }

                $pro = $this->proRow($request) ?? $pro;
                $out = $this->payload($bookings, $ledger, $proId, $pro);
                $out['marked_paid'] = $updated;
                $out['utr'] = strtoupper(preg_replace('/\s+/', '', $utr) ?? $utr);
                Response::ok($out);
                return;
            }

            Response::fail('Unknown action', 422, 'validation');
            return;
        }

        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        try {
            $bookings->syncListingHoldForWallet($proId);
            $pro = $this->proRow($request) ?? $pro;
            $out = $this->payload($bookings, $ledger, $proId, $pro);
        } catch (\Throwable) {
            $out = [
                'screen' => 'home_earnings',
                'summary' => self::emptySummary(),
                'credit_history' => [],
                'wallet_history' => [],
                'rating_avg' => (float) ($pro['rating_avg'] ?? 0),
                'rating_count' => (int) ($pro['rating_count'] ?? 0),
                'jobs_completed' => (int) ($pro['jobs_completed'] ?? 0),
            ];
        }

        // Ensure UPI URI uses selected/suggested recharge when settings exist.
        if (isset($out['summary']) && is_array($out['summary'])) {
            $settings = new PlatformSettingsRepository();
            $amt = (int) ($out['summary']['suggested_recharge_paise']
                ?? $settings->walletRechargeMinPaise());
            $out['summary']['company_upi_pay_uri'] = $settings->companyUpiPayUri(
                $amt,
                'Pro Enroll wallet recharge',
            );
        }

        Response::ok($out);
    }
}
