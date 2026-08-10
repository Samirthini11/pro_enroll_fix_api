-- Start-work OTP: customer confirms before pro begins; wallet fee charged on verify.
-- 029_booking_start_otp.sql
-- MySQL does NOT support: ADD COLUMN IF NOT EXISTS (MariaDB only)

USE pro_enroll;

-- Run this once. If column already exists, skip this ALTER and run CREATE TABLE only.
ALTER TABLE service_bookings
    ADD COLUMN start_work_verified_at DATETIME NULL DEFAULT NULL
        AFTER updated_at;

CREATE TABLE IF NOT EXISTS booking_start_otps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL,
    request_id CHAR(32) NOT NULL,
    phone_e164 VARCHAR(20) NOT NULL,
    otp_code CHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME NULL DEFAULT NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_booking_start_otp_request (request_id),
    KEY idx_booking_start_otp_booking (booking_id),
    KEY idx_booking_start_otp_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
