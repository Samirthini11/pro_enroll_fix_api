<?php



declare(strict_types=1);



namespace ProEnroll\Api\Endpoints\Screens;



use ProEnroll\Api\Endpoints\ScreenHandler;

use ProEnroll\Api\Http\Request;

use ProEnroll\Api\Http\Response;

use ProEnroll\Api\Services\BookingPushNotifier;
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

            $status = $request->input('status');
            $latRaw = $request->input('lat');
            $lngRaw = $request->input('lng');

            $locationUpdated = false;
            if ($latRaw !== null && $lngRaw !== null && $latRaw !== '' && $lngRaw !== '') {
                try {
                    [$lat, $lng] = BookingRepository::parseGeoInput($latRaw, $lngRaw);
                } catch (\InvalidArgumentException $e) {
                    Response::fail($e->getMessage(), 422, 'validation');
                    return;
                }
                if ($lat !== null && $lng !== null) {
                    $locationUpdated = $bookings->updateProLocationForActiveJob(
                        $bookingId,
                        $proId,
                        $lat,
                        $lng,
                    );
                }
            }

            $statusUpdated = false;
            if ($status !== null && $status !== '') {
                $statusUpdated = $bookings->updateActiveJobStatus(
                    $bookingId,
                    $proId,
                    (string) $status,
                );
                if (!$statusUpdated && !$locationUpdated) {
                    Response::fail('Could not update job status', 400);
                    return;
                }

                $row = $bookings->findActiveForProfessional($proId, $bookingId);
                if ($row !== null) {
                    BookingPushNotifier::statusForCustomer($row, (string) $status, $pro);
                }
            } elseif (!$locationUpdated) {
                Response::fail('No valid update (status or lat/lng required)', 422, 'validation');
                return;
            }

            $pro = $this->pros->findById($proId) ?? $pro;
            [$proLat, $proLng] = $this->proCoords($pro);
            $row = $bookings->findActiveForProfessional($proId, $bookingId);

            Response::ok([
                'screen' => 'job_active',
                'status' => $status !== null && $status !== ''
                    ? (string) $status
                    : ($row !== null ? $bookings->activeJobPayload($row, $proLat, $proLng)['status'] ?? 'on_the_way' : 'on_the_way'),
                'active_job' => $row === null
                    ? null
                    : $bookings->activeJobPayload($row, $proLat, $proLng),
            ]);

            return;
        }



        if ($request->method === 'POST') {
            // Temporary: visit fee only — final amount entry disabled for professionals.
            if (!$bookings->completeActiveJob($bookingId, $proId, null)) {
                Response::fail('Could not complete job', 400);
                return;
            }

            $completed = $bookings->findById($bookingId);
            if ($completed !== null) {
                // Work finished — ask customer to pay visit fee in app.
                BookingPushNotifier::statusForCustomer($completed, 'awaiting_payment', $pro);
            }

            $proLat = isset($pro['last_lat']) ? (float) $pro['last_lat'] : null;
            $proLng = isset($pro['last_lng']) ? (float) $pro['last_lng'] : null;
            $row = $completed ?? $bookings->findById($bookingId);

            Response::ok([
                'screen' => 'job_active',
                'status' => 'completed',
                'final_amount_paise' => null,
                'payment_due' => true,
                'active_job' => $row !== null
                    ? $bookings->activeJobPayload($row, $proLat, $proLng)
                    : null,
            ]);
            return;
        }



        Response::fail('Method not allowed', 405);

    }

}

