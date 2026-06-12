-- Run in phpMyAdmin → SQL tab on the VPS (98.93.105.128)
-- Creates database + proadmin user for the API

CREATE DATABASE IF NOT EXISTS pro_enroll
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'proadmin'@'localhost' IDENTIFIED BY 'Krishna@135';
GRANT ALL PRIVILEGES ON pro_enroll.* TO 'proadmin'@'localhost';
FLUSH PRIVILEGES;

-- If user exists but password is wrong, run only this:
-- ALTER USER 'proadmin'@'localhost' IDENTIFIED BY 'Krishna@135';
-- FLUSH PRIVILEGES;
