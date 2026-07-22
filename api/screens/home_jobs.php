<?php



declare(strict_types=1);



namespace ProEnroll\Api\Endpoints\Screens;



use ProEnroll\Api\Endpoints\ScreenHandler;

use ProEnroll\Api\Http\Request;

use ProEnroll\Api\Http\Response;

use ProEnroll\Api\Services\BookingRepository;



/**

 * Flutter: JobsTab (home shell)

 * GET /v1/screens/home-jobs

 */

final class HomeJobsScreen extends ScreenHandler

{

    public function handle(Request $request): void

    {

        if (!$this->requireAuth($request)) {

            return;

        }



        if ($request->method !== 'GET') {

            Response::fail('Method not allowed', 405);

            return;

        }



        $pro = $this->proRow($request);

        if ($pro === null) {

            Response::fail('Professional profile not found', 404);

            return;

        }



        $profile = $this->pros->profilePayload($this->uid($request));

        $codes = array_column($profile['skills'] ?? [], 'category_code');

        [$proLat, $proLng] = $this->proCoords($pro);



        $bookings = new BookingRepository();

        $rows = $bookings->listOffersForProfessional((int) $pro['id'], $codes);

        $offers = array_map(

            static fn (array $row) => $bookings->offerPayload($row, $proLat, $proLng),

            $rows,

        );



        $active = null;

        $activeRow = $bookings->findActiveForProfessional((int) $pro['id']);

        if ($activeRow !== null) {

            $active = $bookings->activeJobPayload($activeRow, $proLat, $proLng);

        }



        $historyRows = $bookings->listHistoryForProfessional((int) $pro['id']);

        $history = array_map(

            static fn (array $row) => $bookings->historyPayload($row),

            $historyRows,

        );



        Response::ok([

            'screen' => 'home_jobs',

            'is_available' => $profile['is_available'] ?? false,

            'offers' => $offers,

            'active_job' => $active,

            'job_history' => $history,

        ]);

    }

}

