-- Migration: Update default retention policy to 365 days
-- Date: 2026-04-17

-- Update the existing record (singleton)
UPDATE system_settings SET retention_days = 365 WHERE id = 1;

-- Update the column default for future safety (though it's a singleton)
ALTER TABLE system_settings ALTER COLUMN retention_days SET DEFAULT 365;
