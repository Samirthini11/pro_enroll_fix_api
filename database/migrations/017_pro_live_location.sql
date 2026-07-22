-- Live GPS for pro tracking while en route to a customer.
ALTER TABLE professionals
  ADD COLUMN last_lat DECIMAL(9,6) NULL AFTER home_lng,
  ADD COLUMN last_lng DECIMAL(9,6) NULL AFTER last_lat,
  ADD COLUMN last_location_at DATETIME NULL AFTER last_lng;
