<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Customer;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\JwtTokenMiddleware;
use ProEnroll\Api\Services\AuthService;
use ProEnroll\Api\Services\BookingRepository;

/**
 * GET  /v1/customer/bookings/{id}
 * POST /v1/customer/bookings/{id}/complete
 * POST /v1/customer/bookings/{id}/rating
 */
final class BookingDetailEndpoint
{
    public function handle(Request $request, int $bookingId): void
    {
        if (!JwtTokenMiddleware::require($request)) {
            return;
        }

        $auth = new AuthService();
        $customerId = $auth->resolveCustomerId($request);
        if ($customerId === null) {
            Response::fail('Customer account required', 403, 'forbidden');
            return;
        }

        $bookings = new BookingRepository();
        $path = $request->path;

        if (str_ends_with($path, '/complete') && $request->method === 'POST') {
            if (!$bookings->markCompleted($bookingId, $customerId)) {
                Response::fail('Cannot mark completed', 400, 'invalid_state');
                return;
            }
            $row = $bookings->findByIdForCustomer($bookingId, $customerId);
            Response::ok(['booking' => $bookings->bookingPayload($row ?? [])]);
            return;
        }

        if (str_ends_with($path, '/rating') && $request->method === 'POST') {
            $stars = (int) $request->input('stars', 0);
            $review = (string) $request->input('review_text', '');
            if ($stars < 1 || $stars > 5) {
                Response::fail('stars must be 1-5', 422, 'validation');
                return;
            }
            if (!$bookings->addRating($bookingId, $customerId, $stars, $review !== '' ? $review : null)) {
                Response::fail('Cannot rate this booking', 400, 'invalid_state');
                return;
            }
            $row = $bookings->findByIdForCustomer($bookingId, $customerId);
            Response::ok(['booking' => $bookings->bookingPayload($row ?? [])]);
            return;
        }

        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        $row = $bookings->findByIdForCustomer($bookingId, $customerId);
        if ($row === null) {
            Response::fail('Booking not found', 404, 'not_found');
            return;
        }

        Response::ok(['booking' => $bookings->bookingPayload($row)]);
    }
}
