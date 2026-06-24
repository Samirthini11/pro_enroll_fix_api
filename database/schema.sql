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

    INDEX idx_auth_professional (professional_id)

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

    INDEX idx_auth_sessions_refresh (refresh_token_hash)

) ENGINE=InnoDB;



CREATE TABLE IF NOT EXISTS auth_login_attempts (

    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    phone_e164 VARCHAR(20) NOT NULL,

    attempt_type ENUM(
        'otp_send', 'otp_verify', 'refresh', 'logout',
        'role_switch', 'firebase_session'
    ) NOT NULL,

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
    address_lat DECIMAL(9,6) NULL,
    address_lng DECIMAL(9,6) NULL,
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
    INDEX idx_booking_geo (address_lat, address_lng)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS booking_ratings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL UNIQUE,
    stars TINYINT UNSIGNED NOT NULL,
    review_text VARCHAR(500) NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS service_categories (
    code VARCHAR(32) NOT NULL PRIMARY KEY,
    name_en VARCHAR(80) NOT NULL,
    name_ta VARCHAR(120) NOT NULL,
    icon_key VARCHAR(48) NOT NULL DEFAULT 'build',
    default_visit_fee_paise INT UNSIGNED NOT NULL DEFAULT 15000,
    base_price_paise INT UNSIGNED NOT NULL DEFAULT 15000,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_categories_active_sort (is_active, sort_order)
) ENGINE=InnoDB;

INSERT INTO service_categories (code, name_en, name_ta, icon_key, default_visit_fee_paise, base_price_paise, sort_order)
VALUES
    ('ac', 'AC Mechanic', 'AC மெக்கானிக்', 'ac_unit', 20000, 20000, 1),
    ('plumber', 'Plumber', 'பிளம்பர்', 'plumbing', 15000, 15000, 2),
    ('electrician', 'Electrician', 'மின்சார வேலை', 'electrical_services', 15000, 15000, 3),
    ('ro', 'RO Water Service', 'RO வாட்டர் சர்வீஸ்', 'water_drop', 15000, 15000, 4),
    ('fridge', 'Fridge Repair', 'குளிர்சாதனம் ரிப்பேர்', 'kitchen', 20000, 20000, 5),
    ('wash', 'Washing Machine', 'வாஷிங் மெஷின்', 'local_laundry_service', 20000, 20000, 6),
    ('car', 'Car Mechanic', 'கார் மெக்கானிக்', 'directions_car', 25000, 25000, 7),
    ('bike', 'Bike Mechanic', 'பைக் மெக்கானிக்', 'two_wheeler', 15000, 15000, 8)
ON DUPLICATE KEY UPDATE
    name_en = VALUES(name_en),
    name_ta = VALUES(name_ta),
    icon_key = VALUES(icon_key),
    default_visit_fee_paise = VALUES(default_visit_fee_paise),
    base_price_paise = VALUES(base_price_paise),
    sort_order = VALUES(sort_order),
    is_active = 1;

