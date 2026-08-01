<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Services\ExperienceEditRequestRepository;

/**
 * Flutter: HelpTab
 * GET  /v1/screens/home-help
 * POST /v1/screens/home-help/experience-edit-request  { "reason"?: "..." }
 */
final class HomeHelpScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if ($request->method === 'POST' && str_ends_with($request->path, '/experience-edit-request')) {
            if (!$this->requireAuth($request)) {
                return;
            }
            $this->ensurePro($request);
            $pro = $this->pros->findByFirebaseUid($this->uid($request));
            if ($pro === null) {
                Response::fail('Professional not found', 404);
                return;
            }

            if ($this->pros->isExperienceEditAllowed($pro)) {
                Response::ok([
                    'already_allowed' => true,
                    'can_edit_experience' => true,
                    'profile' => $this->pros->profilePayload($this->uid($request)),
                ]);
                return;
            }

            try {
                $repo = new ExperienceEditRequestRepository();
                $item = $repo->submit(
                    (int) $pro['id'],
                    trim((string) $request->input('reason', '')) ?: null,
                );
                Response::ok([
                    'request' => $item,
                    'can_edit_experience' => false,
                    'profile' => $this->pros->profilePayload($this->uid($request)),
                ]);
            } catch (\Throwable $e) {
                Response::fail(
                    $e->getMessage() !== '' ? $e->getMessage() : 'Could not submit request',
                    500,
                    'experience_edit_request_failed',
                );
            }
            return;
        }

        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        Response::ok([
            'screen' => 'home_help',
            'faq' => [
                ['q' => 'When do I get paid?', 'a' => 'Visit fees are paid out daily by 7 PM to your UPI.'],
                ['q' => 'How is KYC verified?', 'a' => 'Aadhaar OTP + live selfie; review within 24 hours.'],
                ['q' => 'Can I reject a job?', 'a' => 'Yes — reject open offers anytime, and you can still reject an accepted job while you are on the way. Once you mark arrived, the job must be completed.'],
                ['q' => 'How do I change experience years?', 'a' => 'After enrollment, experience years are locked. Raise a request from Help; admin must approve before you can edit.'],
            ],
            'support_phone' => '+914132000000',
            'support_whatsapp' => '+919876543210',
        ]);
    }
}
