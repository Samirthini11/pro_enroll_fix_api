USE pro_enroll;

-- Company UPI for professionals to pay platform fee.
INSERT INTO platform_settings (setting_key, setting_value, updated_at) VALUES
    ('company_upi_id', 'sami050699@okaxis', NOW()),
    ('company_upi_name', 'Pro Enroll', NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Track when pro paid platform fee to company UPI.
ALTER TABLE service_bookings
    ADD COLUMN commission_upi_paid_at DATETIME NULL AFTER commission_waived;
