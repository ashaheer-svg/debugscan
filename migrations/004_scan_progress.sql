-- AI DebugScan v3 - Migration 004: Scan Progress
-- ============================================================

ALTER TABLE scan_jobs ADD COLUMN IF NOT EXISTS progress_stage VARCHAR(100);
ALTER TABLE scan_jobs ADD COLUMN IF NOT EXISTS progress_percent INTEGER DEFAULT 0;

COMMENT ON COLUMN scan_jobs.progress_stage IS 'Current granular step (e.g., Extraction, AI Analysis)';
COMMENT ON COLUMN scan_jobs.progress_percent IS 'Progress percentage (0-100)';
