-- Reverse of 018_deepdive_telemetry.sql
BEGIN;
ALTER TABLE deepdive_jobs DROP COLUMN IF EXISTS narrator_tokens_used;
COMMIT;
