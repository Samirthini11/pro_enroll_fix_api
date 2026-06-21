-- Add base_price_paise to service_categories (platform starting price per category)
USE pro_enroll;

ALTER TABLE service_categories
    ADD COLUMN base_price_paise INT UNSIGNED NOT NULL DEFAULT 15000
        AFTER default_visit_fee_paise;

UPDATE service_categories SET base_price_paise = default_visit_fee_paise;

UPDATE service_categories SET base_price_paise = CASE code
    WHEN 'ac' THEN 20000
    WHEN 'plumber' THEN 15000
    WHEN 'electrician' THEN 15000
    WHEN 'ro' THEN 15000
    WHEN 'fridge' THEN 20000
    WHEN 'wash' THEN 20000
    WHEN 'car' THEN 25000
    WHEN 'bike' THEN 15000
    ELSE base_price_paise
END;
