-- Track technician movement while en_route so customers can cancel
-- if the pro stays at the same spot for 10+ minutes after accepting.
ALTER TABLE service_bookings
  ADD COLUMN pro_track_lat DECIMAL(9,6) NULL AFTER accepted_at,
  ADD COLUMN pro_track_lng DECIMAL(9,6) NULL AFTER pro_track_lat,
  ADD COLUMN pro_last_moved_at DATETIME NULL AFTER pro_track_lng;
