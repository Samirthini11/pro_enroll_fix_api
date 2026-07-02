<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Admin;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\AdminMiddleware;
use ProEnroll\Api\Services\AdminRepository;

/**
 * GET  /v1/admin/documents?kind=shop_photo&status=pending
 * POST /v1/admin/documents/:id/approve
 * POST /v1/admin/documents/:id/reject
 */
final class AdminDocumentsEndpoint
{
    public function handle(Request $request, ?int $documentId = null, ?string $action = null): void
    {
        if (!AdminMiddleware::require($request)) {
            return;
        }

        try {
            $repo = new AdminRepository();

            if ($request->method === 'GET' && $documentId === null) {
                $kind = (string) ($request->query['kind'] ?? '');
                $status = (string) ($request->query['status'] ?? '');
                Response::ok([
                    'items' => $repo->documentQueue(
                        $kind !== '' ? $kind : null,
                        $status !== '' ? $status : null,
                    ),
                ]);
                return;
            }

            if ($request->method === 'POST' && $documentId !== null && $action === 'approve') {
                if (!$repo->approveDocument($documentId)) {
                    Response::fail('Could not approve document', 409, 'invalid_state');
                    return;
                }
                Response::ok(['document_id' => $documentId, 'status' => 'approved']);
                return;
            }

            if ($request->method === 'POST' && $documentId !== null && $action === 'reject') {
                $reason = trim((string) $request->input('reason', ''));
                if ($reason === '') {
                    Response::fail('reason is required', 422, 'validation');
                    return;
                }
                if (!$repo->rejectDocument($documentId, $reason)) {
                    Response::fail('Could not reject document', 409, 'invalid_state');
                    return;
                }
                Response::ok([
                    'document_id' => $documentId,
                    'status' => 'rejected',
                    'reason' => $reason,
                ]);
                return;
            }
        } catch (\Throwable $e) {
            Response::fail(
                'Document request failed: ' . $e->getMessage(),
                500,
                'admin_documents_failed',
            );
            return;
        }

        Response::fail('Method not allowed', 405);
    }
}
