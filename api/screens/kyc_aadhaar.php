<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\KycUploadService;

/**
 * Flutter: AadhaarScreen
 * POST /v1/screens/kyc-aadhaar
 *   { "action": "upload_card", "aadhaar_last4": "1234", "image_base64": "...", "content_type": "image/jpeg" }
 *   { "action": "initiate", "aadhaar_last4": "1234" }
 *   { "action": "verify", "otp": "123456" }
 */
final class KycAadhaarScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        $this->ensurePro($request);
        $action = (string) $request->input('action', 'initiate');

        if ($request->method !== 'POST') {
            Response::fail('Method not allowed', 405);
            return;
        }

        if ($action === 'upload_card') {
            $last4 = preg_replace('/\D/', '', (string) $request->input('aadhaar_last4', ''));
            if (strlen($last4) !== 4) {
                // Accept full number and take last 4.
                $full = preg_replace('/\D/', '', (string) $request->input('aadhaar_number', ''));
                if (strlen($full) === 12) {
                    $last4 = substr($full, -4);
                }
            }
            if (strlen($last4) !== 4) {
                Response::fail('aadhaar_last4 must be 4 digits', 422, 'validation');
                return;
            }

            $pro = $this->proRow($request);
            if ($pro === null) {
                Response::fail('Professional profile not found', 404);
                return;
            }

            try {
                $uploaded = (new KycUploadService())->uploadBase64(
                    (int) $pro['id'],
                    'aadhaar',
                    (string) $request->input('image_base64', ''),
                    $request->input('content_type') !== null
                        ? (string) $request->input('content_type')
                        : null,
                    'Aadhaar card',
                );
            } catch (\InvalidArgumentException $e) {
                Response::fail($e->getMessage(), 422, 'validation');
                return;
            } catch (\Throwable $e) {
                Response::fail($e->getMessage(), 500, 's3_upload_failed');
                return;
            }

            $this->pros->updateProfile($this->uid($request), [
                'aadhaar_last4' => $last4,
                'kyc_status' => 'selfie_pending',
            ]);

            Response::ok([
                'screen' => 'kyc_aadhaar',
                'uploaded' => true,
                'file_url' => $uploaded['url'],
                'aadhaar_last4' => $last4,
                'next_route' => '/kyc/selfie',
            ]);
            return;
        }

        if ($action === 'initiate') {
            $last4 = preg_replace('/\D/', '', (string) $request->input('aadhaar_last4', ''));
            if (strlen($last4) !== 4) {
                Response::fail('aadhaar_last4 must be 4 digits', 422);
                return;
            }
            $this->pros->updateProfile($this->uid($request), [
                'aadhaar_last4' => $last4,
                'kyc_status' => 'aadhaar_pending',
            ]);
            Response::ok([
                'screen' => 'kyc_aadhaar',
                'kyc_ref_id' => 'kyc_' . bin2hex(random_bytes(8)),
                'message' => 'Aadhaar OTP sent (integrate UIDAI provider in production)',
            ]);
            return;
        }

        if ($action === 'verify') {
            $otp = (string) $request->input('otp', '');
            if (strlen($otp) < 4 || $otp === '0000') {
                Response::fail('Invalid Aadhaar OTP', 422);
                return;
            }
            $this->pros->updateProfile($this->uid($request), ['kyc_status' => 'selfie_pending']);
            Response::ok(['screen' => 'kyc_aadhaar', 'verified' => true, 'next_route' => '/kyc/selfie']);
            return;
        }

        Response::fail('Unknown action', 422);
    }
}
