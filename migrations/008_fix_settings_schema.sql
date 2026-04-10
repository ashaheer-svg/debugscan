-- AI DebugScan v3 - Migration 008: Fix Settings Schema
-- ============================================================

-- 1. Add missing configuration columns to system_settings
ALTER TABLE system_settings ADD COLUMN IF NOT EXISTS stuck_alert_mins INTEGER NOT NULL DEFAULT 30;
ALTER TABLE system_settings ADD COLUMN IF NOT EXISTS auto_cancel_mins INTEGER NOT NULL DEFAULT 60;

-- 2. Initialize or Update settings row to ensure dashboard resilience
INSERT INTO system_settings (
    id, 
    groq_api_key_encrypted, 
    level1_model, 
    level2_model, 
    retention_days, 
    max_concurrent_scans, 
    stuck_alert_mins, 
    auto_cancel_mins, 
    updated_at
)
VALUES (
    1, 
    'initial_setup', 
    'llama-3.3-70b-versatile', 
    'llama-3.3-70b-versatile', 
    90, 
    5, 
    30, 
    60, 
    NOW()
)
ON CONFLICT (id) DO UPDATE SET 
    stuck_alert_mins = COALESCE(system_settings.stuck_alert_mins, EXCLUDED.stuck_alert_mins),
    auto_cancel_mins = COALESCE(system_settings.auto_cancel_mins, EXCLUDED.auto_cancel_mins),
    updated_at = NOW();

COMMENT ON TABLE system_settings IS 'Core system settings with alert and cancellation timings';
