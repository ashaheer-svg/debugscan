-- AI DebugScan v3 - Migration 003: Forensic Storage
-- ============================================================

-- Add extended_data column to debug_files to store SQLite extractions
ALTER TABLE debug_files ADD COLUMN IF NOT EXISTS extended_data JSONB;

-- Add a comment to track what this is for
COMMENT ON COLUMN debug_files.extended_data IS 'Stores structured data extracted from binary SQLite logs (Level 1+)';

-- Index for better performance when calculating tenant usage
CREATE INDEX IF NOT EXISTS idx_debug_files_tenant_size ON debug_files(tenant_id, file_size_bytes);
