<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Admin;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\AdminMiddleware;
use ProEnroll\Api\Services\WalletRechargeRepository;

/**
 * GET  /v1/admin/wallet-recharges?status=pending|approved|rejected|all
 * POST /v1/admin/wallet-recharges/:id/approve
 * POST /v1/admin/wallet-recharges/:id/reject   { "reason": "..." }
 */
final class AdminWalletRechargesEndpoint
{
    public function handle(Request $request, ?int $rechargeId = null, ?string $action = null): void
    {
        if (!AdminMiddleware::require($request)) {
            return;
        }

        try {
            $repo = new WalletRechargeRepository();
            $reviewer = (string) ($request->authUser['sub'] ?? 'admin');

            if ($request->method === 'GET' && $rechargeId === null) {
                $status = (string) ($request->query['status'] ?? 'pending');
                Response::ok([
                    'items' => $repo->queue($status !== '' ? $status : 'pending'),
                    'pending_count' => $repo->pendingCount(),
                ]);
                return;
            }

            if ($request->method === 'POST' && $rechargeId !== null && $action === 'approve') {
                if (!$repo->approve($rechargeId, $reviewer)) {
                    Response::fail('Could not approve recharge', 409, 'invalid_state');
                    return;
                }
                Response::ok([
                    'recharge_id' => $rechargeId,
                    'status' => 'approved',
                    'pending_count' => $repo->pendingCount(),
                ]);
                return;
            }

            if ($request->method === 'POST' && $rechargeId !== null && $action === 'reject') {
                $reason = trim((string) $request->input('reason', ''));
                if ($reason === '') {
                    Response::fail('reason is required', 422, 'validation');
                    return;
                }
                if (!$repo->reject($rechargeId, $reason, $reviewer)) {
                    Response::fail('Could not reject recharge', 409, 'invalid_state');
                    return;
                }
                Response::ok([
                    'recharge_id' => $rechargeId,
                    'status' => 'rejected',
                    'reason' => $reason,
                    'pending_count' => $repo->pendingCount(),
                ]);
                return;
            }
        } catch (\Throwable $e) {
            Response::fail(
                'Wallet recharge request failed: ' . $e->getMessage(),
                500,
                'admin_wallet_recharges_failed',
            );
            return;
        }

        Response::fail('Method not allowed', 405);
    }
}
