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

            $bookings->autoCompleteStaleAwaitingPayments();

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
                // Strict: cannot advance a queued accept while another job is still open.
                if ($bookings->findBlockingJobForAdvance($proId, $bookingId) !== null) {
                    Response::fail(
                        'Finish or close your current job before starting the next one.',
                        409,
                        'job_in_progress',
                    );
                    return;
                }

                if ((string) $status === 'in_progress'
                    && !$bookings->isStartWorkVerified($bookingId)
                ) {
                    Response::fail(
                        'Ask the customer for the OTP, then verify to start work.',
                        403,
                        'start_otp_required',
                    );
                    return;
                }

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
            $action = strtolower(trim((string) $request->input('action', 'complete')));

            if ($action === 'cancel' || $action === 'reject') {
                $rowBefore = $bookings->findActiveForProfessional($proId, $bookingId)
                    ?? $bookings->findById($bookingId);
                $reason = trim((string) $request->input('reason', ''));

                // Rejecting while on the way requires a reason.
                if ($rowBefore !== null
                    && BookingRepository::rejectRequiresReason((string) ($rowBefore['status'] ?? ''))
                    && $reason === ''
                ) {
                    Response::fail(
                        'Please choose a reason for rejecting this job.',
                        422,
                        'reason_required',
                    );
                    return;
                }

                if (!$bookings->cancelByProfessional($bookingId, $proId, $reason !== '' ? $reason : null)) {
                    if ($bookings->professionalDailyCancelsRemaining($proId) <= 0) {
                        Response::fail(
                            $bookings->dailyCancelLimitMessage('professional'),
                            400,
                            'daily_cancel_limit',
                        );
                        return;
                    }
                    Response::fail(
                        'You can reject only before you mark arrived at the customer location.',
                        400,
                        'cannot_cancel',
                    );
                    return;
                }
                if ($rowBefore !== null) {
                    BookingPushNotifier::rejectedForCustomer($rowBefore, $pro);
                }
                Response::ok([
                    'screen' => 'job_active',
                    'cancelled' => true,
                    'active_job' => null,
                ]);
                return;
            }

            if ($action === 'confirm_payment' || $action === 'payment_received') {
                $method = strtolower(trim((string) $request->input('payment_method', 'cash')));
                $row = $bookings->confirmPaymentReceivedForProfessional($bookingId, $proId, $method);
                if ($row === null) {
                    Response::fail(
                        'Confirm payment only after work is done and fee is still due',
                        400,
                        'invalid_state',
                    );
                    return;
                }

                BookingPushNotifier::completedForCustomer($row, $pro);

                $proLat = isset($pro['last_lat']) ? (float) $pro['last_lat'] : null;
                $proLng = isset($pro['last_lng']) ? (float) $pro['last_lng'] : null;

                Response::ok([
                    'screen' => 'job_active',
                    'status' => 'completed',
                    'payment_due' => false,
                    'active_job' => $bookings->activeJobPayload($row, $proLat, $proLng),
                ]);
                return;
            }

            if ($action === 'send_start_otp') {
                try {
                    $sent = $bookings->sendStartWorkOtp($bookingId, $proId);
                } catch (\InvalidArgumentException $e) {
                    Response::fail($e->getMessage(), 422, 'start_otp_send_failed');
                    return;
                } catch (\Throwable $e) {
                    Response::fail(
                        \ProEnroll\Api\Config::bool('APP_DEBUG') ? $e->getMessage() : 'Could not send OTP',
                        500,
                        'start_otp_send_failed',
                    );
                    return;
                }

                $row = $bookings->findActiveForProfessional($proId, $bookingId);
                if ($row !== null) {
                    BookingPushNotifier::startWorkOtpForCustomer(
                        $row,
                        $pro,
                        (string) ($sent['notify_otp'] ?? ''),
                        (int) ($sent['expires_in'] ?? 600),
                    );
                }
                unset($sent['notify_otp']);

                Response::ok(array_merge([
                    'screen' => 'job_active',
                    'action' => 'send_start_otp',
                ], $sent));
                return;
            }

            if ($action === 'verify_start_otp') {
                $requestId = trim((string) $request->input('request_id', ''));
                $otp = trim((string) $request->input('otp', ''));
                if ($requestId === '' || strlen($otp) < 4) {
                    Response::fail('Enter the 6-digit OTP from the customer', 422, 'otp_required');
                    return;
                }

                try {
                    $row = $bookings->verifyStartWorkOtpAndBegin(
                        $bookingId,
                        $proId,
                        $requestId,
                        $otp,
                    );
                } catch (\InvalidArgumentException $e) {
                    Response::fail($e->getMessage(), 422, 'start_otp_invalid');
                    return;
                } catch (\RuntimeException $e) {
                    Response::fail($e->getMessage(), 402, 'wallet_low');
                    return;
                } catch (\Throwable $e) {
                    Response::fail(
                        \ProEnroll\Api\Config::bool('APP_DEBUG') ? $e->getMessage() : 'Could not verify OTP',
                        500,
                        'start_otp_verify_failed',
                    );
                    return;
                }

                BookingPushNotifier::statusForCustomer($row, 'in_progress', $pro);

                $pro = $this->pros->findById($proId) ?? $pro;
                [$proLat, $proLng] = $this->proCoords($pro);

                Response::ok([
                    'screen' => 'job_active',
                    'action' => 'verify_start_otp',
                    'status' => 'in_progress',
                    'wallet_charged' => true,
                    'active_job' => $bookings->activeJobPayload($row, $proLat, $proLng),
                ]);
                return;
            }

            // Work done → awaiting customer pay (or pro cash confirm).
            if (!$bookings->completeActiveJob($bookingId, $proId, null)) {
                Response::fail('Could not complete job', 400);
                return;
            }

            $completed = $bookings->findById($bookingId);
            if ($completed !== null) {
                BookingPushNotifier::statusForCustomer($completed, 'awaiting_payment', $pro);
            }

            $proLat = isset($pro['last_lat']) ? (float) $pro['last_lat'] : null;
            $proLng = isset($pro['last_lng']) ? (float) $pro['last_lng'] : null;
            $row = $completed ?? $bookings->findById($bookingId);

            Response::ok([
                'screen' => 'job_active',
                'status' => 'awaiting_payment',
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

