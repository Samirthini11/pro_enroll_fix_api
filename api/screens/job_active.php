<?php



declare(strict_types=1);



namespace ProEnroll\Api\Endpoints\Screens;



use ProEnroll\Api\Endpoints\ScreenHandler;

use ProEnroll\Api\Http\Request;

use ProEnroll\Api\Http\Response;

use ProEnroll\Api\Services\BookingRepository;



/**

 * Flutter: ActiveJobScreen

 * GET /v1/screens/job-active

 * PUT /v1/screens/job-active

 * POST /v1/screens/job-active

 */

final class JobActiveScreen extends ScreenHandler

{

    public function handle(Request $request): void

    {

        if (!$this->requireAuth($request)) {

            return;

        }



        $pro = $this->proRow($request);

        if ($pro === null) {

            Response::fail('Professional profile not found', 404);

            return;

        }



        $proId = (int) $pro['id'];

        [$proLat, $proLng] = $this->proCoords($pro);

        $bookings = new BookingRepository();



        if ($request->method === 'GET') {

            $row = $bookings->findActiveForProfessional($proId);

            Response::ok([

                'screen' => 'job_active',

                'active_job' => $row === null

                    ? null

                    : $bookings->activeJobPayload($row, $proLat, $proLng),

            ]);

            return;

        }



        $active = $bookings->findActiveForProfessional($proId);

        if ($active === null) {

            Response::fail('No active job', 404);

            return;

        }



        $bookingId = (int) $active['id'];



        if ($request->method === 'PUT') {

            $status = (string) $request->input('status', 'on_the_way');

            if (!$bookings->updateActiveJobStatus($bookingId, $proId, $status)) {

                Response::fail('Could not update job status', 400);

                return;

            }



            $row = $bookings->findActiveForProfessional($proId, $bookingId);

            Response::ok([

                'screen' => 'job_active',

                'status' => $status,

                'active_job' => $row === null

                    ? null

                    : $bookings->activeJobPayload($row, $proLat, $proLng),

            ]);

            return;

        }



        if ($request->method === 'POST') {

            $amount = (int) $request->input('final_amount_paise', 0);

            if ($amount < 100) {

                Response::fail('final_amount_paise required', 422);

                return;

            }



            if (!$bookings->completeActiveJob($bookingId, $proId)) {

                Response::fail('Could not complete job', 400);

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

