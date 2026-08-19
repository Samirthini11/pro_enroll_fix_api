<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints\Job;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Http\Response;
use ProEnroll\Api\Middleware\JwtTokenMiddleware;
use ProEnroll\Api\Services\AuthService;
use ProEnroll\Api\Services\BookingPushNotifier;
use ProEnroll\Api\Services\BookingRepository;
use ProEnroll\Api\Services\JobChatService;
use ProEnroll\Api\Services\ProRepository;

/**
 * Temporary job chat (professional ↔ customer).
 * GET  /v1/bookings/{id}/chat
 * POST /v1/bookings/{id}/chat  { "body": "..." }
 */
final class JobChatEndpoint
{
    public function handle(Request $request, int $bookingId): void
    {
        if (!JwtTokenMiddleware::require($request)) {
            return;
        }

        if ($bookingId < 1) {
            Response::fail('Booking not found', 404, 'not_found');
            return;
        }

        $auth = new AuthService();
        $role = $this->jwtRole($request);
        $actorId = 0;
        if ($role === 'customer') {
            $actorId = $auth->resolveCustomerId($request) ?? 0;
        } else {
            $uid = (string) ($request->authUser['sub'] ?? '');
            $pro = $uid !== '' ? (new ProRepository())->findByFirebaseUid($uid) : null;
            $actorId = $pro !== null ? (int) $pro['id'] : 0;
            $role = 'professional';
        }

        if ($actorId < 1) {
            Response::fail('Account required', 403, 'forbidden');
            return;
        }

        $bookings = new BookingRepository();
        $booking = $bookings->findById($bookingId);
        if ($booking === null) {
            Response::fail('Booking not found', 404, 'not_found');
            return;
        }

        $chat = new JobChatService();
        if (!$chat->authorize($booking, $role, $actorId)) {
            Response::fail('Not part of this job', 403, 'forbidden');
            return;
        }

        $status = (string) ($booking['status'] ?? '');
        $open = JobChatService::isOpenStatus($status);
        $peerName = $this->peerName($booking, $role);

        if ($request->method === 'GET') {
            $afterId = (int) ($request->query['after_id'] ?? 0);
            $messages = $open ? $chat->listMessages($bookingId, $afterId > 0 ? $afterId : null) : [];
            Response::ok([
                'booking_id' => $bookingId,
                'chat_open' => $open,
                'status' => $status,
                'peer_name' => $peerName,
                'me_role' => $role,
                'messages' => $messages,
            ]);
            return;
        }

        if ($request->method === 'POST') {
            if (!$open) {
                Response::fail(
                    'Chat ended when this job was completed',
                    409,
                    'chat_closed',
                );
                return;
            }

            $body = (string) $request->input('body', $request->input('message', ''));
            try {
                $message = $chat->sendMessage($bookingId, $role, $actorId, $body);
            } catch (\InvalidArgumentException $e) {
                Response::fail($e->getMessage(), 422, 'validation');
                return;
            }

            if ($message === null) {
                Response::fail('Could not send message', 500, 'chat_send_failed');
                return;
            }

            BookingPushNotifier::jobChat(
                $booking,
                $role,
                (string) $message['body'],
                $this->senderName($booking, $role),
            );

            Response::ok([
                'booking_id' => $bookingId,
                'chat_open' => true,
                'status' => $status,
                'peer_name' => $peerName,
                'me_role' => $role,
                'message' => $message,
            ]);
            return;
        }

        Response::fail('Method not allowed', 405, 'method_not_allowed');
    }

    private function jwtRole(Request $request): string
    {
        $role = strtolower(trim((string) ($request->authUser['role'] ?? '')));
        return $role === 'customer' ? 'customer' : 'professional';
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function peerName(array $booking, string $meRole): string
    {
        if ($meRole === 'customer') {
            $name = trim((string) ($booking['pro_name'] ?? $booking['professional_name'] ?? ''));
            if ($name === '') {
                $proId = (int) ($booking['professional_id'] ?? 0);
                if ($proId >= 1) {
                    $pro = (new ProRepository())->findById($proId);
                    $name = trim((string) ($pro['full_name'] ?? ''));
                }
            }
            return $name !== '' ? $name : 'Technician';
        }

        $name = trim((string) ($booking['customer_name'] ?? ''));
        return $name !== '' ? $name : 'Customer';
    }

    /**
     * @param array<string, mixed> $booking
     */
    private function senderName(array $booking, string $meRole): string
    {
        if ($meRole === 'professional') {
            $name = trim((string) ($booking['pro_name'] ?? ''));
            return $name !== '' ? $name : 'Technician';
        }
        $name = trim((string) ($booking['customer_name'] ?? ''));
        return $name !== '' ? $name : 'Customer';
    }
}
