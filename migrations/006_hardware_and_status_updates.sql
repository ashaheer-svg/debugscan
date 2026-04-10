-- AI DebugScan v3 - Migration 006: Hardware & Status Updates
-- ============================================================

-- 1. Add physical_location to projects
ALTER TABLE projects ADD COLUMN IF NOT EXISTS physical_location VARCHAR(255);
COMMENT ON COLUMN projects.physical_location IS 'Physical installation location extracted from hardware diagnostics (e.g., SNMP sysLocation)';

-- 2. Expand scan_status enum
-- Note: In PG, ALTER TYPE ... ADD VALUE cannot be executed inside a transaction block in some versions.
-- We will attempt it directly.
ALTER TYPE scan_status ADD VALUE IF NOT EXISTS 'error';
ALTER TYPE scan_status ADD VALUE IF NOT EXISTS 'retry';

-- 3. Update comments for clarity
COMMENT ON COLUMN scan_jobs.status IS 'Current status: queued, running, completed, failed, cancelled, error (admin aborted), retry (auto timeout)';
