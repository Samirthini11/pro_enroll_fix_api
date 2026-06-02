-- Pro-Enroll API schema (MySQL 8+ / MariaDB 10.4+)

CREATE DATABASE IF NOT EXISTS pro_enroll CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE pro_enroll;



CREATE TABLE IF NOT EXISTS professionals (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    firebase_uid VARCHAR(128) NOT NULL UNIQUE,

    phone_e164 VARCHAR(20) NULL,

    full_name VARCHAR(120) NULL,

    city_id INT UNSIGNED NULL,

    home_lat DECIMAL(9,6) NULL,

    home_lng DECIMAL(9,6) NULL,

    work_radius_km TINYINT UNSIGNED NOT NULL DEFAULT 5,

    visit_fee_paise INT UNSIGNED NOT NULL DEFAULT 15000,

    is_available TINYINT(1) NOT NULL DEFAULT 0,

    kyc_status ENUM(

        'not_started', 'aadhaar_pending', 'selfie_pending',

        'in_review', 'verified', 'rejected'

    ) NOT NULL DEFAULT 'not_started',

    aadhaar_last4 CHAR(4) NULL,

    upi_id VARCHAR(100) NULL,

    bank_account_no VARCHAR(30) NULL,

    bank_ifsc VARCHAR(15) NULL,

    language_code VARCHAR(5) NOT NULL DEFAULT 'en',

    rating_avg DECIMAL(3,2) NOT NULL DEFAULT 0,

    rating_count INT UNSIGNED NOT NULL DEFAULT 0,

    jobs_completed INT UNSIGNED NOT NULL DEFAULT 0,

    pro_score TINYINT UNSIGNED NOT NULL DEFAULT 50,

    created_at DATETIME NOT NULL,

    updated_at DATETIME NOT NULL,

    INDEX idx_phone (phone_e164),

    INDEX idx_kyc (kyc_status)

) ENGINE=InnoDB;



CREATE TABLE IF NOT EXISTS auth_accounts (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    auth_uid VARCHAR(128) NOT NULL UNIQUE,

    phone_e164 VARCHAR(20) NOT NULL UNIQUE,

    professional_id BIGINT UNSIGNED NULL,

    status ENUM('active', 'suspended', 'deleted') NOT NULL DEFAULT 'active',

    phone_verified_at DATETIME NULL,

    last_login_at DATETIME NULL,

    created_at DATETIME NOT NULL,

    updated_at DATETIME NOT NULL,

    INDEX idx_auth_professional (professional_id),

    CONSTRAINT fk_auth_professional

        FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE SET NULL

) ENGINE=InnoDB;



CREATE TABLE IF NOT EXISTS otp_requests (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    request_id CHAR(32) NOT NULL UNIQUE,

    phone_e164 VARCHAR(20) NOT NULL,

    purpose ENUM('sign_in', 'sign_up') NOT NULL DEFAULT 'sign_up',

    otp_code CHAR(6) NOT NULL,

    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,

    expires_at DATETIME NOT NULL,

    verified_at DATETIME NULL,

    ip_address VARCHAR(45) NULL,

    user_agent VARCHAR(255) NULL,

    created_at DATETIME NOT NULL,

    INDEX idx_phone (phone_e164),

    INDEX idx_expires (expires_at)

) ENGINE=InnoDB;



CREATE TABLE IF NOT EXISTS auth_sessions (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    session_id CHAR(36) NOT NULL UNIQUE,

    auth_account_id BIGINT UNSIGNED NOT NULL,

    refresh_token_hash CHAR(64) NOT NULL,

    access_expires_at DATETIME NOT NULL,

    refresh_expires_at DATETIME NOT NULL,

    revoked_at DATETIME NULL,

    device_label VARCHAR(120) NULL,

    ip_address VARCHAR(45) NULL,

    user_agent VARCHAR(255) NULL,

    created_at DATETIME NOT NULL,

    INDEX idx_auth_sessions_account (auth_account_id),

    INDEX idx_auth_sessions_refresh (refresh_token_hash),

    CONSTRAINT fk_auth_sessions_account

        FOREIGN KEY (auth_account_id) REFERENCES auth_accounts(id) ON DELETE CASCADE

) ENGINE=InnoDB;



CREATE TABLE IF NOT EXISTS auth_login_attempts (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    phone_e164 VARCHAR(20) NOT NULL,

    attempt_type ENUM('otp_send', 'otp_verify', 'refresh', 'logout') NOT NULL,

    success TINYINT(1) NOT NULL DEFAULT 0,

    ip_address VARCHAR(45) NULL,

    user_agent VARCHAR(255) NULL,

    created_at DATETIME NOT NULL,

    INDEX idx_login_phone_time (phone_e164, created_at)

) ENGINE=InnoDB;



CREATE TABLE IF NOT EXISTS professional_skills (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    professional_id BIGINT UNSIGNED NOT NULL,

    category_code VARCHAR(32) NOT NULL,

    experience_years TINYINT UNSIGNED NOT NULL DEFAULT 0,

    is_primary TINYINT(1) NOT NULL DEFAULT 0,

    FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE CASCADE,

    UNIQUE KEY uk_pro_cat (professional_id, category_code)

) ENGINE=InnoDB;



-- Pro Fix customer app

CREATE TABLE IF NOT EXISTS customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    auth_uid VARCHAR(128) NOT NULL UNIQUE,
    phone_e164 VARCHAR(20) NOT NULL UNIQUE,
    full_name VARCHAR(120) NULL,
    city_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_customer_phone (phone_e164)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_bookings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_code VARCHAR(20) NOT NULL UNIQUE,
    customer_id BIGINT UNSIGNED NOT NULL,
    professional_id BIGINT UNSIGNED NOT NULL,
    category_code VARCHAR(32) NOT NULL,
    problem_description TEXT NOT NULL,
    address_text VARCHAR(255) NOT NULL,
    city_id INT UNSIGNED NOT NULL,
    status ENUM(
        'pending', 'confirmed', 'en_route', 'arrived',
        'in_progress', 'awaiting_payment', 'completed', 'cancelled'
    ) NOT NULL DEFAULT 'confirmed',
    visit_fee_paise INT UNSIGNED NOT NULL DEFAULT 15000,
    scheduled_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_booking_customer (customer_id),
    INDEX idx_booking_pro (professional_id),
    INDEX idx_booking_status (status),
    CONSTRAINT fk_booking_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_booking_professional
        FOREIGN KEY (professional_id) REFERENCES professionals(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS booking_ratings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL UNIQUE,
    stars TINYINT UNSIGNED NOT NULL,
    review_text VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_rating_booking
        FOREIGN KEY (booking_id) REFERENCES service_bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

