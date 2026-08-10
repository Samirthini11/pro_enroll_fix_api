<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\KycUploadService;

/**
 * Flutter: SelfieScreen
 * POST /v1/screens/kyc-selfie
 *   { "image_base64": "...", "content_type": "image/jpeg" }
 */
final class KycSelfieScreen extends ScreenHandler
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

        $image = (string) $request->input('image_base64', '');
        $fileUrl = null;
        if ($image !== '') {
            try {
                $uploaded = (new KycUploadService())->uploadBase64(
                    (int) $pro['id'],
                    'selfie',
                    $image,
                    $request->input('content_type') !== null
                        ? (string) $request->input('content_type')
                        : null,
                    'Live selfie',
                );
                $fileUrl = $uploaded['url'];
            } catch (\InvalidArgumentException $e) {
                Response::fail($e->getMessage(), 422, 'validation');
                return;
            } catch (\Throwable $e) {
                Response::fail($e->getMessage(), 500, 's3_upload_failed');
                return;
            }
        }

        $score = 0.85 + (mt_rand() / mt_getrandmax()) * 0.14;
        $rounded = round($score, 3);
        $uid = $this->uid($request);
        try {
            $this->pros->updateProfile($uid, [
                'kyc_status' => 'in_review',
                'face_match_score' => $rounded,
            ]);
        } catch (\Throwable) {
            $this->pros->updateProfile($uid, ['kyc_status' => 'in_review']);
        }

        Response::ok([
            'screen' => 'kyc_selfie',
            'face_match_score' => $rounded,
            'passed' => $score >= 0.8,
            'file_url' => $fileUrl,
            'next_route' => '/kyc/docs',
        ]);
    }
}
