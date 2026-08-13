<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\KycUploadService;

/**
 * Flutter: DocumentsScreen
 * GET  /v1/screens/kyc-docs — supporting document status (tools / cert / pan)
 * POST /v1/screens/kyc-docs
 *   { "action": "upload", "kind": "cert", "image_base64": "...", "content_type": "image/jpeg" }
 *   { "action": "submit", "documents": ["cert", "tools"] }  // finish KYC docs step (or save while editing)
 */
final class KycDocsScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        $this->ensurePro($request);
        $pro = $this->proRow($request);
        if ($pro === null) {
            Response::fail('Professional profile not found', 404);
            return;
        }

        if ($request->method === 'GET') {
            Response::ok([
                'screen' => 'kyc_docs',
                'kyc_status' => (string) ($pro['kyc_status'] ?? 'not_started'),
                'documents' => $this->supportingDocuments((int) $pro['id']),
            ]);
            return;
        }

        if ($request->method !== 'POST') {
            Response::fail('Method not allowed', 405);
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
                'kind' => $this->appKind((string) $uploaded['kind']),
                'file_url' => $uploaded['url'],
                'documents' => $this->supportingDocuments((int) $pro['id']),
            ]);
            return;
        }

        // Finish optional docs step — or save edits after KYC already submitted.
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

        $status = (string) ($pro['kyc_status'] ?? 'not_started');
        $alreadyPastDocs = in_array($status, ['in_review', 'verified'], true);

        if (!$alreadyPastDocs) {
            try {
                $this->pros->updateProfile($this->uid($request), ['kyc_status' => 'in_review']);
                $status = 'in_review';
            } catch (\Throwable) {
            }
        }

        Response::ok([
            'screen' => 'kyc_docs',
            'uploaded' => true,
            'documents' => is_array($documents) ? $documents : [],
            'supporting_documents' => $this->supportingDocuments((int) $pro['id']),
            'kyc_status' => $status,
            'next_route' => $alreadyPastDocs ? '/home' : '/kyc/pending',
        ]);
    }

    /**
     * App-facing supporting docs: tools, cert, pan.
     *
     * @return list<array<string, mixed>>
     */
    private function supportingDocuments(int $professionalId): array
    {
        $wanted = [
            'tools' => ['db' => 'shop_photo', 'label' => 'Tools / shop photo'],
            'cert' => ['db' => 'cert', 'label' => 'Skill / training certificate'],
            'pan' => ['db' => 'pan', 'label' => 'PAN card'],
        ];

        $byDb = [];
        try {
            $db = \ProEnroll\Api\Database::connection();
            $hasFileUrl = false;
            try {
                $col = $db->query("SHOW COLUMNS FROM pro_documents LIKE 'file_url'");
                $hasFileUrl = $col && $col->fetch() !== false;
            } catch (\Throwable) {
            }
            $select = $hasFileUrl
                ? 'SELECT kind, status, file_url, thumbnail_url, rejected_reason
                   FROM pro_documents
                   WHERE professional_id = ?
                     AND kind IN (\'shop_photo\', \'cert\', \'pan\')'
                : 'SELECT kind, status, thumbnail_url, rejected_reason
                   FROM pro_documents
                   WHERE professional_id = ?
                     AND kind IN (\'shop_photo\', \'cert\', \'pan\')';
            $stmt = $db->prepare($select);
            $stmt->execute([$professionalId]);
            foreach ($stmt->fetchAll() as $row) {
                $byDb[(string) $row['kind']] = $row;
            }
        } catch (\Throwable) {
            // Table may be missing on older DBs.
        }

        $out = [];
        foreach ($wanted as $appKind => $meta) {
            $row = $byDb[$meta['db']] ?? null;
            $hasFile = false;
            $status = 'missing';
            $rejected = null;
            if ($row !== null) {
                $file = trim((string) ($row['file_url'] ?? ''));
                $thumb = trim((string) ($row['thumbnail_url'] ?? ''));
                $hasFile = $file !== '' || $thumb !== '';
                $status = (string) ($row['status'] ?? 'pending');
                if (!$hasFile && $status === 'pending') {
                    $status = 'missing';
                }
                $rejected = $row['rejected_reason'] ?? null;
            }

            $out[] = [
                'kind' => $appKind,
                'label' => $meta['label'],
                'status' => $status,
                'has_file' => $hasFile,
                'can_edit' => $status === 'missing'
                    || $status === 'rejected'
                    || ($status === 'pending' && !$hasFile),
                'rejected_reason' => $rejected,
            ];
        }

        return $out;
    }

    private function appKind(string $dbKind): string
    {
        return match ($dbKind) {
            'shop_photo' => 'tools',
            default => $dbKind,
        };
    }
}
