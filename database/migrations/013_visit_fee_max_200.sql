USE pro_enroll;

-- Visit fee max ₹200 so 5% platform fee = ₹10 to company at the cap.
INSERT INTO platform_settings (setting_key, setting_value, updated_at) VALUES
    ('visit_fee_min_paise', '5000', NOW()),
    ('visit_fee_max_paise', '20000', NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Cap any existing pro fees above ₹200.
UPDATE professionals
SET visit_fee_paise = 20000, updated_at = NOW()
WHERE visit_fee_paise > 20000;
