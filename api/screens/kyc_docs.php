<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;

/**
 * Flutter: DocumentsScreen
 * POST /v1/screens/kyc-docs
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
        $documents = $request->input('documents', []);
        if (is_array($documents) && $pro !== null) {
            try {
                (new \ProEnroll\Api\Services\AdminRepository())->seedDocumentsFromKycUpload(
                    (int) $pro['id'],
                    array_map('strval', $documents),
                );
            } catch (\Throwable) {
                // Optional admin tables — pro_enroll_v1 flow still succeeds.
            }
        }

        Response::ok([
            'screen' => 'kyc_docs',
            'uploaded' => true,
            'documents' => $request->input('documents', []),
            'next_route' => '/kyc/pending',
        ]);
    }
}
