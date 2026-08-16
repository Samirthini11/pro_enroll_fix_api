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
            'faq' => $this->faq(),
            'academy' => $this->academyCourses(),
            'support_phone' => '+914132000000',
            'support_whatsapp' => '+919876543210',
        ]);
    }

    /** @return list<array{q: string, a: string}> */
    private function faq(): array
    {
        return [
            [
                'q' => 'How is Pro Score calculated?',
                'a' => "Pro Score is a 0–100 quality score updated automatically from your activity:\n\n"
                    . "• KYC (max 30): verified 30, in review 18, Aadhaar/selfie pending 10, rejected 5\n"
                    . "• Documents (max 20): Aadhaar, selfie, shop photo, certificate — +5 each when approved, +2 when uploaded and pending\n"
                    . "• Jobs (max 25): 2 points per completed job (caps at 13 jobs)\n"
                    . "• Ratings (max 25): based on your average stars, with full weight after 3 ratings\n\n"
                    . "Tip: finish KYC, upload supporting docs, complete jobs, and collect good ratings to reach 70+ (strong profile).",
            ],
            [
                'q' => 'What is Refer & Earn?',
                'a' => 'Share your referral code from Profile → Refer & Earn. When a new pro installs, applies your code, and completes 1 customer job, you get +1 free job (no platform fee on that job).',
            ],
            [
                'q' => 'When do I get paid?',
                'a' => 'Visit fees are paid out daily by 7 PM to your UPI / bank details on Profile.',
            ],
            [
                'q' => 'How is KYC verified?',
                'a' => 'Aadhaar OTP + live selfie. Optional shop/tools photo, skill certificate, and PAN boost Pro Score. Review is usually within 24 hours.',
            ],
            [
                'q' => 'Can I reject a job?',
                'a' => 'Yes — reject open offers anytime, and you can still reject an accepted job while you are on the way. Once you mark arrived, the job must be completed.',
            ],
            [
                'q' => 'How do I change experience years?',
                'a' => 'After enrollment, experience years are locked. Raise a request from Help; admin must approve before you can edit on Profile.',
            ],
            [
                'q' => 'What is Pro Academy?',
                'a' => 'Free short video lessons (Tamil / English) for AC, RO, plumbing, electrical, and customer service. Open Pro Academy from Help and tap a course to watch.',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function academyCourses(): array
    {
        return [
            [
                'id' => 'ac-basics',
                'title' => 'AC servicing basics',
                'category_code' => 'ac',
                'language' => 'ta',
                'duration_label' => '15–20 min',
                'description' => 'Filter clean, indoor unit wash, and safe outdoor checks in Tamil.',
                'video_url' => 'https://www.youtube.com/results?search_query=AC+service+basics+Tamil+tutorial',
            ],
            [
                'id' => 'ac-gas',
                'title' => 'AC gas charging & leak check',
                'category_code' => 'ac',
                'language' => 'ta',
                'duration_label' => '12–18 min',
                'description' => 'Pressure check, leak detection, and charging safety tips.',
                'video_url' => 'https://www.youtube.com/results?search_query=AC+gas+charging+leak+test+Tamil',
            ],
            [
                'id' => 'ro-service',
                'title' => 'RO water purifier service',
                'category_code' => 'ro',
                'language' => 'ta',
                'duration_label' => '10–15 min',
                'description' => 'Filter stages, membrane flush, and TDS check walkthrough.',
                'video_url' => 'https://www.youtube.com/results?search_query=RO+water+purifier+service+Tamil',
            ],
            [
                'id' => 'plumbing-tap',
                'title' => 'Plumbing — tap & mixer repair',
                'category_code' => 'plumber',
                'language' => 'ta',
                'duration_label' => '10–15 min',
                'description' => 'Common leak points, washer change, and mixer cartridge basics.',
                'video_url' => 'https://www.youtube.com/results?search_query=plumbing+tap+mixer+repair+Tamil',
            ],
            [
                'id' => 'electrical-safety',
                'title' => 'Electrical safety at customer home',
                'category_code' => 'electrician',
                'language' => 'en',
                'duration_label' => '8–12 min',
                'description' => 'Isolation, PPE, and safe testing before opening panels.',
                'video_url' => 'https://www.youtube.com/results?search_query=electrical+safety+home+service+technician',
            ],
            [
                'id' => 'customer-service',
                'title' => 'Customer service & ratings',
                'category_code' => 'soft_skills',
                'language' => 'ta',
                'duration_label' => '8–10 min',
                'description' => 'Arrive on time, explain the job, and earn 5★ reviews that raise Pro Score.',
                'video_url' => 'https://www.youtube.com/results?search_query=customer+service+skills+for+technicians+Tamil',
            ],
        ];
    }
}
