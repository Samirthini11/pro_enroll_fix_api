<?php



declare(strict_types=1);



namespace ProEnroll\Api\Endpoints\Screens;



use ProEnroll\Api\Endpoints\ScreenHandler;

use ProEnroll\Api\Http\Request;

use ProEnroll\Api\Http\Response;

use ProEnroll\Api\Services\BookingPushNotifier;
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

            // Block accept until the current job is completed / cancelled.
            $current = $bookings->findActiveForProfessional($proId);
            if ($current !== null) {
                Response::fail(
                    'Finish your current job before accepting a new one.',
                    409,
                    'job_in_progress',
                );
                return;
            }

            $offer = $bookings->findOfferForProfessional($bookingId, $proId);
            if ($offer === null) {
                Response::fail('Offer expired or already handled', 410, 'offer_expired');
                return;
            }

            $visitFee = (int) ($offer['visit_fee_paise'] ?? 0);
            $gate = $bookings->acceptWalletGate($proId, $visitFee);
            if (!$gate['ok']) {
                Response::fail($gate['message'], 403, 'wallet_limit');
                return;
            }

            $acceptedRow = $bookings->acceptOffer($bookingId, $proId);

            if ($acceptedRow === null) {

                Response::fail('Offer expired or already handled', 410, 'offer_expired');

                return;

            }

            $active = $bookings->activeJobPayload($acceptedRow, $proLat, $proLng);
            $active['status'] = 'accepted';

            BookingPushNotifier::acceptedForCustomer($acceptedRow, $pro);

            Response::ok([

                'screen' => 'job_offer',

                'accepted' => true,

                'active_job' => $active,

                'next_route' => '/job/active',

            ]);

            return;

        }



        if ($request->method === 'POST' && str_ends_with($request->path, '/reject')) {

            if ($bookings->professionalDailyCancelsRemaining($proId) <= 0) {
                Response::fail(
                    $bookings->dailyCancelLimitMessage('professional'),
                    400,
                    'daily_cancel_limit',
                );
                return;
            }

            $offerRow = $bookings->findOfferForProfessional($bookingId, $proId);
            if (!$bookings->rejectOffer($bookingId, $proId)) {

                Response::fail('Offer not found or already handled', 404);

                return;

            }

            if ($offerRow !== null) {
                BookingPushNotifier::rejectedForCustomer($offerRow, $pro);
            }

            Response::ok(['screen' => 'job_offer', 'rejected' => true]);

            return;

        }



        Response::fail('Method not allowed', 405);

    }

}

