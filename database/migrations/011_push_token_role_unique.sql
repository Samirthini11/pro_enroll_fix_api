-- Allow same device token for pro + customer roles on one phone.

ALTER TABLE push_device_tokens DROP INDEX uq_fcm_token;
ALTER TABLE push_device_tokens ADD UNIQUE KEY uq_fcm_role (fcm_token, role);
