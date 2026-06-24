USE pro_enroll;

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
    INDEX idx_booking_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS booking_ratings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id BIGINT UNSIGNED NOT NULL UNIQUE,
    stars TINYINT UNSIGNED NOT NULL,
    review_text VARCHAR(500) NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB;
