<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Customer;

use ProEnroll\Api\Config;
use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\JwtTokenMiddleware;
use ProEnroll\Api\Services\AuthService;
use ProEnroll\Api\Services\BookingPushNotifier;
use ProEnroll\Api\Services\BookingRepository;
use ProEnroll\Api\Services\ProRepository;

/**
 * GET  /v1/customer/bookings
 * POST /v1/customer/bookings
 */
final class BookingsEndpoint
{
    public function handle(Request $request): void
    {
        if (!JwtTokenMiddleware::require($request)) {
            return;
        }

        $auth = new AuthService();
        $customerId = $auth->resolveCustomerId($request);
        if ($customerId === null) {
            Response::fail('Customer account required. Sign in with OTP first.', 403, 'forbidden');
            return;
        }

        $bookings = new BookingRepository();

        if ($request->method === 'GET') {
            $rows = $bookings->listForCustomer($customerId);
            $list = array_map(static fn ($r) => $bookings->bookingPayload($r), $rows);
            Response::ok(['bookings' => $list]);
            return;
        }

        if ($request->method !== 'POST') {
            Response::fail('Method not allowed', 405);
            return;
        }

        try {
            $proId = (int) $request->input('professional_id', 0);
            $category = (string) $request->input('category_code', '');
            $problem = trim((string) $request->input('problem_description', ''));
            $address = trim((string) $request->input('address_text', ''));
            $cityId = (int) $request->input('city_id', 1);
            $scheduled = (string) $request->input('scheduled_at', '');

            if ($proId < 1 || $category === '' || $problem === '' || $address === '') {
                Response::fail('Missing required booking fields', 422, 'validation');
                return;
            }

            if ($scheduled === '') {
                $scheduledTs = time() + 3600;
            } else {
                $scheduledTs = strtotime($scheduled);
                if ($scheduledTs === false) {
                    Response::fail('Invalid scheduled_at datetime', 422, 'validation');
                    return;
                }
            }

            $pros = new ProRepository();
            $pro = $pros->findById($proId);
            if ($pro === null) {
                Response::fail('Technician not found', 404, 'not_found');
                return;
            }

            try {
                [$addressLat, $addressLng] = BookingRepository::parseGeoInput(
                    $request->input('address_lat'),
                    $request->input('address_lng'),
                );
            } catch (\InvalidArgumentException $e) {
                Response::fail($e->getMessage(), 422, 'validation');
                return;
            }

            $visitFeePaise = (int) ($request->input('visit_fee_paise') ?: $pro['visit_fee_paise']);
            if ($visitFeePaise < 100) {
                Response::fail('visit_fee_paise required', 422, 'validation');
                return;
            }

            $paymentMethod = strtolower(trim((string) $request->input('visit_fee_payment_method', 'upi')));
            if (!in_array($paymentMethod, ['upi', 'card', 'netbanking'], true)) {
                Response::fail('visit_fee_payment_method must be upi, card, or netbanking', 422, 'validation');
                return;
            }

            // Visit fee must be collected via the app at booking time.
            $visitFeePaid = $request->input('visit_fee_paid', true);
            if (is_string($visitFeePaid)) {
                $visitFeePaid = !in_array(strtolower($visitFeePaid), ['0', 'false', 'no'], true);
            }
            if (!$visitFeePaid) {
                Response::fail('Visit fee must be paid in the app to confirm booking', 422, 'visit_fee_required');
                return;
            }

            $row = $bookings->create([
                'customer_id' => $customerId,
                'professional_id' => $proId,
                'category_code' => $category,
                'problem_description' => $problem,
                'address_text' => $address,
                'address_lat' => $addressLat,
                'address_lng' => $addressLng,
                'city_id' => $cityId,
                'visit_fee_paise' => $visitFeePaise,
                'visit_fee_paid' => true,
                'visit_fee_payment_method' => $paymentMethod,
                'scheduled_at' => date('Y-m-d H:i:s', $scheduledTs),
            ]);

            BookingPushNotifier::newBookingForPro($pro, $row);
            BookingPushNotifier::confirmedForCustomer($row, $pro);

            Response::ok(['booking' => $bookings->bookingPayload($row)], 201);
        } catch (\Throwable $e) {
            Response::fail(
                Config::bool('APP_DEBUG') ? $e->getMessage() : 'Could not create booking',
                500,
                'booking_create_failed',
            );
        }
    }
}
