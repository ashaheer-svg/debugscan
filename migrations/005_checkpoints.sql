-- AI DebugScan v3 - Migration 005: Forensic Checkpoints
-- ============================================================

ALTER TABLE scan_jobs ADD COLUMN IF NOT EXISTS checkpoints JSONB DEFAULT '[]';

COMMENT ON COLUMN scan_jobs.checkpoints IS 'Detailed success/metadata log for internal workflow checkpoints (Extraction, SQL, Packaging, AI, Report)';
