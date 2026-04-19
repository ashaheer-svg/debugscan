-- Migration: Split retention into Log and Analysis periods
-- Date: 2026-04-19

ALTER TABLE system_settings ADD COLUMN log_retention_days INT DEFAULT 7;
ALTER TABLE system_settings ADD COLUMN analysis_retention_days INT DEFAULT 365;

-- Update with current value before dropping
UPDATE system_settings SET analysis_retention_days = retention_days;

ALTER TABLE system_settings DROP COLUMN retention_days;
