<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\KycUploadService;

/**
 * Flutter: DocumentsScreen
 * POST /v1/screens/kyc-docs
 *   { "action": "upload", "kind": "cert", "image_base64": "...", "content_type": "image/jpeg" }
 *   { "action": "submit", "documents": ["cert", "tools"] }  // finish KYC docs step
 */
final class KycDocsScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        if ($request->method !== 'POST') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $this->ensurePro($request);
        $pro = $this->proRow($request);
        if ($pro === null) {
            Response::fail('Professional profile not found', 404);
            return;
        }

        $action = strtolower(trim((string) $request->input('action', 'submit')));

        if ($action === 'upload') {
            $kind = (string) $request->input('kind', 'cert');
            try {
                $uploaded = (new KycUploadService())->uploadBase64(
                    (int) $pro['id'],
                    $kind,
                    (string) $request->input('image_base64', ''),
                    $request->input('content_type') !== null
                        ? (string) $request->input('content_type')
                        : null,
                );
            } catch (\InvalidArgumentException $e) {
                Response::fail($e->getMessage(), 422, 'validation');
                return;
            } catch (\Throwable $e) {
                Response::fail($e->getMessage(), 500, 's3_upload_failed');
                return;
            }

            Response::ok([
                'screen' => 'kyc_docs',
                'uploaded' => true,
                'kind' => $uploaded['kind'],
                'file_url' => $uploaded['url'],
            ]);
            return;
        }

        // Finish optional docs step → pending review.
        $documents = $request->input('documents', []);
        if (is_array($documents) && $documents !== []) {
            try {
                (new \ProEnroll\Api\Services\AdminRepository())->seedDocumentsFromKycUpload(
                    (int) $pro['id'],
                    array_map('strval', $documents),
                );
            } catch (\Throwable) {
            }
        }

        try {
            $this->pros->updateProfile($this->uid($request), ['kyc_status' => 'in_review']);
        } catch (\Throwable) {
        }

        Response::ok([
            'screen' => 'kyc_docs',
            'uploaded' => true,
            'documents' => is_array($documents) ? $documents : [],
            'next_route' => '/kyc/pending',
        ]);
    }
}
