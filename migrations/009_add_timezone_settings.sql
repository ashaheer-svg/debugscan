-- AI DebugScan v3 - Migration 009: Add Timezone and Prompt Character Settings
-- =========================================================================

-- 1. Add missing configuration columns to system_settings
ALTER TABLE system_settings ADD COLUMN IF NOT EXISTS timezone VARCHAR(50) NOT NULL DEFAULT 'UTC';
ALTER TABLE system_settings ADD COLUMN IF NOT EXISTS max_prompt_chars INTEGER NOT NULL DEFAULT 50000;

COMMENT ON COLUMN system_settings.timezone IS 'Global system timezone for forensic synchronization';
COMMENT ON COLUMN system_settings.max_prompt_chars IS 'Maximum characters of forensic data sent to AI per file';
