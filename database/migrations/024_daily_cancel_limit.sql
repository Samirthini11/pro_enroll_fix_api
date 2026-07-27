-- Track who cancelled a booking so each side can be limited to 5 cancels per IST day.
ALTER TABLE service_bookings
  ADD COLUMN cancelled_by ENUM('customer', 'professional') NULL AFTER status,
  ADD COLUMN cancelled_at DATETIME NULL AFTER cancelled_by;
