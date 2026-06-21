USE pro_enroll;

ALTER TABLE service_bookings
    ADD COLUMN address_lat DECIMAL(9,6) NULL AFTER address_text,
    ADD COLUMN address_lng DECIMAL(9,6) NULL AFTER address_lat,
    ADD INDEX idx_booking_geo (address_lat, address_lng);
