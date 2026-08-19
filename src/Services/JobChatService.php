<?php

declare(strict_types=1);

namespace ProEnroll\Api\Services;

use PDO;
use ProEnroll\Api\Database;

/**
 * Temporary in-job chat between the assigned professional and customer.
 * Messages exist only while the booking is in progress and are deleted
 * when the job is completed or cancelled.
 */
final class JobChatService
{
    public const MAX_BODY = 1000;

    /** @var list<string> */
    public const OPEN_STATUSES = [
        'en_route',
        'arrived',
        'in_progress',
        'awaiting_payment',
    ];

    private PDO $db;

    private static ?bool $schemaReady = null;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->ensureSchema();
    }

    public function ensureSchema(): bool
    {
        if (self::$schemaReady === true) {
            return true;
        }
        if (self::$schemaReady === false) {
            return false;
        }

        try {
            $this->db->exec(
                "CREATE TABLE IF NOT EXISTS job_chat_messages (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    booking_id BIGINT UNSIGNED NOT NULL,
                    sender_role ENUM('professional', 'customer') NOT NULL,
                    sender_id BIGINT UNSIGNED NOT NULL,
                    body VARCHAR(1000) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_job_chat_booking_created (booking_id, created_at),
                    KEY idx_job_chat_booking_id (booking_id, id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            self::$schemaReady = true;
            return true;
        } catch (\Throwable $e) {
            error_log('job_chat schema: ' . $e->getMessage());
            self::$schemaReady = false;
            return false;
        }
    }

    public static function isOpenStatus(string $status): bool
    {
        return in_array($status, self::OPEN_STATUSES, true);
    }

    /**
     * @param array<string, mixed> $booking
     */
    public function authorize(
        array $booking,
        string $role,
        int $actorId,
    ): bool {
        if ($role === 'customer') {
            return (int) ($booking['customer_id'] ?? 0) === $actorId;
        }
        if ($role === 'professional') {
            return (int) ($booking['professional_id'] ?? 0) === $actorId;
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listMessages(int $bookingId, ?int $afterId = null): array
    {
        if (!$this->ensureSchema()) {
            return [];
        }

        if ($afterId !== null && $afterId > 0) {
            $stmt = $this->db->prepare(
                'SELECT id, booking_id, sender_role, sender_id, body, created_at
                 FROM job_chat_messages
                 WHERE booking_id = ? AND id > ?
                 ORDER BY id ASC
                 LIMIT 200'
            );
            $stmt->execute([$bookingId, $afterId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT id, booking_id, sender_role, sender_id, body, created_at
                 FROM job_chat_messages
                 WHERE booking_id = ?
                 ORDER BY id ASC
                 LIMIT 200'
            );
            $stmt->execute([$bookingId]);
        }

        $rows = $stmt->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->messagePayload($row);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function sendMessage(
        int $bookingId,
        string $senderRole,
        int $senderId,
        string $body,
    ): ?array {
        if (!$this->ensureSchema()) {
            return null;
        }

        $text = trim($body);
        if ($text === '') {
            throw new \InvalidArgumentException('Message cannot be empty');
        }
        if (mb_strlen($text) > self::MAX_BODY) {
            $text = mb_substr($text, 0, self::MAX_BODY);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO job_chat_messages (booking_id, sender_role, sender_id, body)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$bookingId, $senderRole, $senderId, $text]);
        $id = (int) $this->db->lastInsertId();

        $row = $this->db->prepare(
            'SELECT id, booking_id, sender_role, sender_id, body, created_at
             FROM job_chat_messages WHERE id = ?'
        );
        $row->execute([$id]);
        $found = $row->fetch();

        return $found ? $this->messagePayload($found) : null;
    }

    public function clearForBooking(int $bookingId): void
    {
        if ($bookingId < 1 || !$this->ensureSchema()) {
            return;
        }
        try {
            $stmt = $this->db->prepare('DELETE FROM job_chat_messages WHERE booking_id = ?');
            $stmt->execute([$bookingId]);
        } catch (\Throwable $e) {
            error_log('job_chat clear failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function messagePayload(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'booking_id' => (int) $row['booking_id'],
            'sender_role' => (string) $row['sender_role'],
            'sender_id' => (int) $row['sender_id'],
            'body' => (string) $row['body'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
