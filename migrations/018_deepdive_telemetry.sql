-- Migration: DeepDive telemetry column
-- Date: 2026-04-21
-- Safe: only ADDs one nullable column with a default. No data backfill needed.
-- Reverse with 018_deepdive_telemetry.down.sql.

BEGIN;

-- Narrator token usage, summed across all incidents in the job.
-- NULL on pre-existing rows (no narrator was run); new rows default to 0.
ALTER TABLE deepdive_jobs
    ADD COLUMN IF NOT EXISTS narrator_tokens_used INTEGER DEFAULT 0;

COMMIT;
