<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Admin;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\AdminMiddleware;
use ProEnroll\Api\Services\ExperienceEditRequestRepository;

/**
 * GET  /v1/admin/experience-edit-requests?status=pending|approved|rejected|used|all
 * POST /v1/admin/experience-edit-requests/:id/approve
 * POST /v1/admin/experience-edit-requests/:id/reject  { "reason": "..." }
 */
final class AdminExperienceEditRequestsEndpoint
{
    public function handle(Request $request, ?int $requestId = null, ?string $action = null): void
    {
        if (!AdminMiddleware::require($request)) {
            return;
        }

        try {
            $repo = new ExperienceEditRequestRepository();
            $reviewer = (string) ($request->authUser['sub'] ?? 'admin');

            if ($request->method === 'GET' && $requestId === null) {
                $status = (string) ($request->query['status'] ?? 'pending');
                Response::ok([
                    'items' => $repo->queue($status !== '' ? $status : 'pending'),
                    'pending_count' => $repo->pendingCount(),
                ]);
                return;
            }

            if ($request->method === 'POST' && $requestId !== null && $action === 'approve') {
                if (!$repo->approve($requestId, $reviewer)) {
                    Response::fail('Could not approve request', 409, 'invalid_state');
                    return;
                }
                Response::ok([
                    'request_id' => $requestId,
                    'status' => 'approved',
                    'pending_count' => $repo->pendingCount(),
                ]);
                return;
            }

            if ($request->method === 'POST' && $requestId !== null && $action === 'reject') {
                $reason = trim((string) $request->input('reason', ''));
                if ($reason === '') {
                    Response::fail('reason is required', 422, 'validation');
                    return;
                }
                if (!$repo->reject($requestId, $reason, $reviewer)) {
                    Response::fail('Could not reject request', 409, 'invalid_state');
                    return;
                }
                Response::ok([
                    'request_id' => $requestId,
                    'status' => 'rejected',
                    'reason' => $reason,
                    'pending_count' => $repo->pendingCount(),
                ]);
                return;
            }
        } catch (\Throwable $e) {
            Response::fail(
                'Experience edit request failed: ' . $e->getMessage(),
                500,
                'admin_experience_edit_failed',
            );
            return;
        }

        Response::fail('Method not allowed', 405);
    }
}
