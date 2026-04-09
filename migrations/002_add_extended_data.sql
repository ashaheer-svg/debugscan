-- Add extended_data column to debug_files to cache forensic SQLite results
ALTER TABLE debug_files ADD COLUMN IF NOT EXISTS extended_data JSONB;
