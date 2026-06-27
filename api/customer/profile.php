<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Customer;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\JwtTokenMiddleware;
use ProEnroll\Api\Services\AuthService;
use ProEnroll\Api\Services\CustomerRepository;

/**
 * GET  /v1/customer/profile
 * PUT  /v1/customer/profile
 * POST /v1/customer/profile/photo
 */
final class ProfileEndpoint
{
    public function handle(Request $request): void
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

        $customers = new CustomerRepository();
        $customer = $customers->findById($customerId);
        if ($customer === null) {
            Response::fail('Customer not found', 404, 'not_found');
            return;
        }

        $path = $request->path;

        if ($request->method === 'POST' && str_ends_with($path, '/photo')) {
            $photo = (string) $request->input('profile_photo_url', '');
            if ($photo === '' || strlen($photo) > 2_000_000) {
                Response::fail('Invalid profile photo', 422, 'validation');
                return;
            }
            $updated = $customers->updateProfile($customerId, ['profile_photo_url' => $photo]);
            Response::ok(['profile' => $customers->profilePayload((string) $updated['auth_uid'])]);
            return;
        }

        if ($request->method === 'PUT') {
            $name = trim((string) $request->input('full_name', ''));
            if ($name === '') {
                Response::fail('full_name is required', 422, 'validation');
                return;
            }
            $fields = ['full_name' => $name];
            $cityRaw = $request->input('city_id');
            if ($cityRaw !== null && $cityRaw !== '') {
                $fields['city_id'] = (int) $cityRaw;
            }
            $updated = $customers->updateProfile($customerId, $fields);
            Response::ok(['profile' => $customers->profilePayload((string) $updated['auth_uid'])]);
            return;
        }

        if ($request->method !== 'GET') {
            Response::fail('Method not allowed', 405);
            return;
        }

        Response::ok(['profile' => $customers->profilePayload((string) $customer['auth_uid'])]);
    }
}
