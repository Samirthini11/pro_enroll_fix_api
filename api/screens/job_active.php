<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Screens;

use ProEnroll\Api\Endpoints\ScreenHandler;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;

/**
 * Flutter: ActiveJobScreen
 * GET /v1/screens/job-active
 * PUT /v1/screens/job-active/status
 * POST /v1/screens/job-active/complete
 */
final class JobActiveScreen extends ScreenHandler
{
    public function handle(Request $request): void
    {
        if (!$this->requireAuth($request)) {
            return;
        }

        if ($request->method === 'GET') {
            Response::ok([
                'screen' => 'job_active',
                'active_job' => $request->input('job') ?? null,
                'hint' => 'Pass accepted job from client state or persist server-side in production',
            ]);
            return;
        }

        if ($request->method === 'PUT') {
            $status = (string) $request->input('status', 'on_the_way');
            Response::ok(['screen' => 'job_active', 'status' => $status]);
            return;
        }

        if ($request->method === 'POST') {
            $amount = (int) $request->input('final_amount_paise', 0);
            if ($amount < 100) {
                Response::fail('final_amount_paise required', 422);
                return;
            }
            Response::ok([
                'screen' => 'job_active',
                'status' => 'completed',
                'final_amount_paise' => $amount,
            ]);
            return;
        }

        Response::fail('Method not allowed', 405);
    }
}
