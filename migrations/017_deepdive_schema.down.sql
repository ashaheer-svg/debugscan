-- Reverse of 017_deepdive_schema.sql
-- Leaves the existing system byte-identical to pre-DeepDive state.

BEGIN;

DROP TABLE IF EXISTS deepdive_findings  CASCADE;
DROP TABLE IF EXISTS deepdive_incidents CASCADE;
DROP TABLE IF EXISTS deepdive_jobs      CASCADE;

ALTER TABLE users DROP COLUMN IF EXISTS deepdive_enabled;

COMMIT;
