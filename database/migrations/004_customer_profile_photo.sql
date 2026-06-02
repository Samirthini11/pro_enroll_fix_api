USE pro_enroll;

ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS profile_photo_url MEDIUMTEXT NULL AFTER full_name;
