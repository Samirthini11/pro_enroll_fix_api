<?php



declare(strict_types=1);



namespace ProEnroll\Api\Endpoints\Screens;



use ProEnroll\Api\Endpoints\ScreenHandler;

use ProEnroll\Api\Http\Request;

use ProEnroll\Api\Http\Response;

use ProEnroll\Api\Services\BookingRepository;



/**

 * Flutter: OfferDetailScreen

 * GET /v1/screens/job-offer/{id}

 * POST /v1/screens/job-offer/{id}/accept

 * POST /v1/screens/job-offer/{id}/reject

 */

final class JobOfferScreen extends ScreenHandler

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



        $offerId = null;

        if (preg_match('#/job-offer/([^/]+)#', $request->path, $m)) {

            $offerId = $m[1];

        }



        if ($offerId === null || !ctype_digit($offerId)) {

            Response::fail('Offer not found', 404);

            return;

        }



        $bookingId = (int) $offerId;

        $proId = (int) $pro['id'];

        [$proLat, $proLng] = $this->proCoords($pro);

        $bookings = new BookingRepository();



        if ($request->method === 'GET') {

            $row = $bookings->findOfferForProfessional($bookingId, $proId);

            if ($row === null) {

                Response::fail('Offer not found', 404);

                return;

            }



            Response::ok([

                'screen' => 'job_offer',

                'offer' => $bookings->offerPayload($row, $proLat, $proLng),

            ]);

            return;

        }



        if ($request->method === 'POST' && str_ends_with($request->path, '/accept')) {

            $activeRow = $bookings->acceptOffer($bookingId, $proId);

            if ($activeRow === null) {

                Response::fail('Offer not found or already handled', 404);

                return;

            }



            $active = $bookings->activeJobPayload($activeRow, $proLat, $proLng);

            $active['status'] = 'accepted';



            Response::ok([

                'screen' => 'job_offer',

                'accepted' => true,

                'active_job' => $active,

                'next_route' => '/job/active',

            ]);

            return;

        }



        if ($request->method === 'POST' && str_ends_with($request->path, '/reject')) {

            if (!$bookings->rejectOffer($bookingId, $proId)) {

                Response::fail('Offer not found or already handled', 404);

                return;

            }



            Response::ok(['screen' => 'job_offer', 'rejected' => true]);

            return;

        }



        Response::fail('Method not allowed', 405);

    }

}

