-- Temporary professional ↔ customer chat for an accepted job.
-- History is deleted when the booking is completed or cancelled.

CREATE TABLE IF NOT EXISTS job_chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    sender_role ENUM('professional', 'customer') NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    body VARCHAR(1000) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_job_chat_booking_created (booking_id, created_at),
    KEY idx_job_chat_booking_id (booking_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
