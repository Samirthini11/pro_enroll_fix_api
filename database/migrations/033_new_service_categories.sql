-- Add Puncture/Tyre, Chimney/Hob, Sofa/Furniture, Battery jump-start.
INSERT INTO service_categories
    (code, name_en, name_ta, icon_key, default_visit_fee_paise, base_price_paise, sort_order, is_active)
VALUES
    ('puncture',  'Puncture / Tyre',           'பஞ்சர் / டயர்',              'tire_repair',            15000, 15000,  9, 1),
    ('chimney',   'Chimney / Hob',             'சிம்னி / ஹாப்',              'countertops',            20000, 20000, 10, 1),
    ('sofa',      'Sofa / Furniture Repair',   'சோபா / மரச்சாமான் பழுது',   'weekend',                20000, 20000, 11, 1),
    ('jumpstart', 'Battery Jump-start',        'பேட்டரி ஜம்ப் ஸ்டார்ட்',     'battery_charging_full',  15000, 15000, 12, 1)
ON DUPLICATE KEY UPDATE
    name_en = VALUES(name_en),
    name_ta = VALUES(name_ta),
    icon_key = VALUES(icon_key),
    default_visit_fee_paise = VALUES(default_visit_fee_paise),
    base_price_paise = VALUES(base_price_paise),
    sort_order = VALUES(sort_order),
    is_active = 1;
