-- Service categories (work types pros can enroll in)
USE pro_enroll;

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
    ('ac', 'AC Mechanic', 'ஏசி மெக்கானிக்', 'ac_unit', 20000, 20000, 1),
    ('plumber', 'Plumber', 'பிளம்பர்', 'plumbing', 15000, 15000, 2),
    ('electrician', 'Electrician', 'மின்சார வேலை', 'electrical_services', 15000, 15000, 3),
    ('ro', 'RO Water Service', 'RO குடிநீர் சேவை', 'water_drop', 15000, 15000, 4),
    ('fridge', 'Fridge Repair', 'குளிர்சாதனப் பழுதுபார்ப்பு', 'kitchen', 20000, 20000, 5),
    ('wash', 'Washing Machine', 'சலவை இயந்திரம்', 'local_laundry_service', 20000, 20000, 6),
    ('car', 'Car Mechanic', 'கார் மெக்கானிக்', 'directions_car', 25000, 25000, 7),
    ('bike', 'Bike Mechanic', 'பைக் மெக்கானிக்', 'two_wheeler', 15000, 15000, 8),
    ('puncture', 'Puncture / Tyre', 'பஞ்சர் / டயர்', 'tire_repair', 15000, 15000, 9),
    ('chimney', 'Chimney / Hob', 'சிம்னி / ஹாப்', 'countertops', 20000, 20000, 10),
    ('sofa', 'Sofa / Furniture Repair', 'சோபா / மரச்சாமான் பழுது', 'weekend', 20000, 20000, 11),
    ('jumpstart', 'Battery Jump-start', 'பேட்டரி ஜம்ப் ஸ்டார்ட்', 'battery_charging_full', 15000, 15000, 12)
ON DUPLICATE KEY UPDATE
    name_en = VALUES(name_en),
    name_ta = VALUES(name_ta),
    icon_key = VALUES(icon_key),
    default_visit_fee_paise = VALUES(default_visit_fee_paise),
    base_price_paise = VALUES(base_price_paise),
    sort_order = VALUES(sort_order),
    is_active = 1;
