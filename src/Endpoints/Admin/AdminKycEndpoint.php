<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Admin;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\AdminMiddleware;
use ProEnroll\Api\Services\AdminRepository;

/**
 * GET  /v1/admin/kyc?status=in_review
 * GET  /v1/admin/kyc/:pro_id
 * POST /v1/admin/kyc/:pro_id/approve
 * POST /v1/admin/kyc/:pro_id/reject
 */
final class AdminKycEndpoint
{
    public function handle(Request $request, ?int $proId = null, ?string $action = null): void
    {
        if (!AdminMiddleware::require($request)) {
            return;
        }

        try {
            $repo = new AdminRepository();

            if ($request->method === 'GET' && $proId === null) {
                $status = (string) ($request->query['status'] ?? '');
                $status = $status !== '' ? $status : null;
                Response::ok(['items' => $repo->kycQueue($status)]);
                return;
            }

            if ($request->method === 'GET' && $proId !== null) {
                $detail = $repo->kycDetail($proId);
                if ($detail === null) {
                    Response::fail('Professional not found', 404, 'not_found');
                    return;
                }
                Response::ok(['item' => $detail]);
                return;
            }

            if ($request->method === 'POST' && $proId !== null && $action === 'approve') {
                if (!$repo->approveKyc($proId)) {
                    Response::fail('Could not approve — not in review queue', 409, 'invalid_state');
                    return;
                }
                Response::ok(['pro_id' => $proId, 'status' => 'verified']);
                return;
            }

            if ($request->method === 'POST' && $proId !== null && $action === 'reject') {
                $reason = trim((string) $request->input('reason', ''));
                if ($reason === '') {
                    Response::fail('reason is required', 422, 'validation');
                    return;
                }
                if (!$repo->rejectKyc($proId, $reason)) {
                    Response::fail('Could not reject — not in review queue', 409, 'invalid_state');
                    return;
                }
                Response::ok(['pro_id' => $proId, 'status' => 'rejected', 'reason' => $reason]);
                return;
            }
        } catch (\Throwable $e) {
            Response::fail(
                'KYC request failed: ' . $e->getMessage(),
                500,
                'admin_kyc_failed',
            );
            return;
        }

        Response::fail('Method not allowed', 405);
    }
}
