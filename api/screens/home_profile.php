<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\BookingRepository;
use ProEnroll\Api\Services\ReferralService;
use ProEnroll\Api\Services\S3StorageService;

/**
 * Flutter: ProfileTab
 * GET  /v1/screens/home-profile
 * PUT  /v1/screens/home-profile
 * POST /v1/screens/home-profile  { "action": "upload_photo", "image_base64": "...", "content_type": "image/jpeg" }
 */
final class HomeProfileScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        $uid = $this->uid($request);
        $pro = $this->ensurePro($request);

        if ($request->method === 'GET') {
            (new BookingRepository())->syncListingHoldForWallet((int) $pro['id']);
            $this->pros->touchPresence($uid);
            $refer = [];
            try {
                $refer = (new ReferralService())->payloadForProfessional((int) $pro['id']);
            } catch (\Throwable) {
                $refer = [];
            }
            Response::ok([
                'screen' => 'home_profile',
                'profile' => $this->pros->profilePayload($uid),
                'refer' => $refer,
            ]);
            return;
        }

        if ($request->method === 'POST') {
            $action = strtolower(trim((string) $request->input('action', 'upload_photo')));
            if ($action === 'apply_referral' || $action === 'apply') {
                $code = (string) $request->input('referral_code', $request->input('code', ''));
                $result = (new ReferralService())->applyReferralCode((int) $pro['id'], $code);
                if (!$result['ok']) {
                    Response::fail($result['message'], 422, 'referral_apply_failed');
                    return;
                }
                Response::ok([
                    'screen' => 'home_profile',
                    'applied' => true,
                    'message' => $result['message'],
                    'refer' => (new ReferralService())->payloadForProfessional((int) $pro['id']),
                    'profile' => $this->pros->profilePayload($uid),
                ]);
                return;
            }
            if ($action !== 'upload_photo') {
                Response::fail('Unknown action', 422, 'validation');
                return;
            }

            try {
                $s3 = new S3StorageService();
                if (!$s3->isConfigured()) {
                    throw new \RuntimeException(
                        'S3 is not configured. Set AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_S3_BUCKET in .env'
                    );
                }
                [$binary, $contentType, $ext] = $this->decodeProfileImage(
                    (string) $request->input('image_base64', ''),
                    $request->input('content_type') !== null
                        ? (string) $request->input('content_type')
                        : null,
                );
                $uploaded = $s3->putKycImage(
                    (int) $pro['id'],
                    'profile',
                    $binary,
                    $contentType,
                    $ext,
                );
                $this->pros->setProfilePhotoUrl((int) $pro['id'], $uploaded['url']);
            } catch (\InvalidArgumentException $e) {
                Response::fail($e->getMessage(), 422, 'validation');
                return;
            } catch (\Throwable $e) {
                Response::fail($e->getMessage(), 500, 'profile_photo_upload_failed');
                return;
            }

            Response::ok([
                'screen' => 'home_profile',
                'uploaded' => true,
                'profile_photo_url' => $this->pros->resolveProfilePhotoUrl((int) $pro['id']),
                'profile' => $this->pros->profilePayload($uid),
            ]);
            return;
        }

        if ($request->method === 'PUT') {
            $fields = [];
            foreach (['full_name', 'upi_id', 'bank_account_no', 'bank_ifsc', 'language_code'] as $k) {
                if (array_key_exists($k, $request->body)) {
                    $fields[$k] = $request->body[$k];
                }
            }
            if (array_key_exists('is_available', $request->body)) {
                $wantOnline = (bool) $request->body['is_available'];
                $pro = $this->proRow($request);
                if ($wantOnline && $pro !== null) {
                    (new BookingRepository())->syncListingHoldForWallet((int) $pro['id']);
                    $pro = $this->proRow($request);
                }
                if ($wantOnline && $pro !== null && !empty($pro['listing_held'])) {
                    Response::fail(
                        'Wallet balance is below ₹50. Recharge your wallet and wait for admin approval to continue receiving jobs.',
                        403,
                        'listing_held',
                    );
                    return;
                }
                $fields['is_available'] = $wantOnline ? 1 : 0;
            }

            $heartbeat = !empty($request->body['heartbeat'])
                || !empty($request->body['presence']);
            if ($heartbeat && $fields === []) {
                $this->pros->touchPresence($uid);
                Response::ok([
                    'screen' => 'home_profile',
                    'profile' => $this->pros->profilePayload($uid),
                ]);
                return;
            }

            if ($fields !== []) {
                $this->pros->updateProfile($uid, $fields);
            }
            if ($heartbeat) {
                $this->pros->touchPresence($uid);
            }

            Response::ok([
                'screen' => 'home_profile',
                'profile' => $this->pros->profilePayload($uid),
            ]);
            return;
        }

        Response::fail('Method not allowed', 405);
    }

    /**
     * @return array{0: string, 1: string, 2: string} binary, contentType, ext
     */
    private function decodeProfileImage(string $raw, ?string $contentType): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new \InvalidArgumentException('image_base64 is required');
        }

        $type = $contentType;
        if (preg_match('#^data:([^;]+);base64,(.+)$#s', $raw, $m)) {
            $type = $type ?: $m[1];
            $raw = $m[2];
        }

        $binary = base64_decode($raw, true);
        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException('Invalid image_base64');
        }

        $type = strtolower(trim((string) ($type ?: 'image/jpeg')));
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$type])) {
            if (str_starts_with($binary, "\xFF\xD8\xFF")) {
                $type = 'image/jpeg';
            } elseif (str_starts_with($binary, "\x89PNG")) {
                $type = 'image/png';
            } else {
                throw new \InvalidArgumentException('Unsupported file type. Use JPEG, PNG, or WEBP.');
            }
        }

        $resolved = $type === 'image/jpg' ? 'image/jpeg' : $type;

        return [$binary, $resolved, $allowed[$resolved] ?? 'jpg'];
    }
}
