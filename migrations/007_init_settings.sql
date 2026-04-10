-- Initialize system settings if they don't exist
INSERT INTO system_settings (id, level1_model, level2_model, retention_days, max_concurrent_scans, stuck_alert_mins, auto_cancel_mins, created_at, updated_at)
SELECT 1, 'llama-3.3-70b-versatile', 'llama-3.3-70b-versatile', 90, 5, 30, 60, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM system_settings WHERE id = 1);

COMMENT ON TABLE system_settings IS 'Emergency initialization of system defaults to prevent Admin Dashboard 500 errors';
