-- AI DebugScan v3 - Migration 010: Advanced L1 Extraction Configuration
-- ===========================================================================
-- Stores per-section ON/OFF toggles and row limits for L1 scan extraction.
-- L2 (Deep Forensic) scans always use the full pipeline, ignoring this config.

CREATE TABLE IF NOT EXISTS extraction_config (
    section_key     VARCHAR(50)  PRIMARY KEY,
    is_enabled      BOOLEAN      NOT NULL DEFAULT TRUE,
    max_rows        INTEGER      NULL,  -- NULL means "no limit" / "N/A for this section"
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Seed all 16 sections with sensible L1 defaults
INSERT INTO extraction_config (section_key, is_enabled, max_rows) VALUES
    -- Group 1: Core Identity (always on, no limits)
    ('hardware',        TRUE,  NULL),
    ('version',         TRUE,  NULL),

    -- Group 2: Storage Subsystem
    ('disks',           TRUE,  NULL),
    ('raid',            TRUE,  NULL),
    ('volumes',         TRUE,  NULL),
    ('btrfs',           TRUE,  NULL),
    ('storage_util',    TRUE,  NULL),
    ('disk_io',         TRUE,  NULL),

    -- Group 3: System Health
    ('logs',            TRUE,  75),    -- max 75 log events (was hardcoded 100)
    ('dstate',          TRUE,  10),    -- max 10 D-state events (stack traces are large)
    ('system_load',     TRUE,  NULL),
    ('memory_util',     TRUE,  NULL),
    ('network',         TRUE,  NULL),

    -- Group 4: SQLite Forensic DB Sections
    ('db_system_events',    TRUE,  200),   -- max 200 rows from .SYNOSYSDB
    ('db_disk_health',      TRUE,  NULL),  -- all rows (usually small)
    ('db_connection_logs',  FALSE, 100),   -- OFF by default (security logs rarely needed for L1)
    ('db_disk_events',      TRUE,  150)    -- max 150 rows from .SYNODISKDB

ON CONFLICT (section_key) DO NOTHING;

COMMENT ON TABLE extraction_config IS 'Per-section extraction toggles and limits for L1 forensic scans only.';
