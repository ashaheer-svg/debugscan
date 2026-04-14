-- AI DebugScan v3 - Migration 011: Add L2 Scan Level Support to Extraction Config
-- ====================================================================================
-- Alters the existing extraction_config table to support per-level toggles.
-- Adds 'scan_level' column and changes the primary key to a composite (section_key, scan_level).
-- Seeds L2 rows with all sections ON and no row limits (preserving existing full-pipeline behaviour).

-- Step 1: Add scan_level column (default 'level1' to match existing rows)
ALTER TABLE extraction_config ADD COLUMN IF NOT EXISTS scan_level VARCHAR(10) NOT NULL DEFAULT 'level1';

-- Step 2: Drop existing single-column primary key
ALTER TABLE extraction_config DROP CONSTRAINT IF EXISTS extraction_config_pkey;

-- Step 3: Set composite primary key
ALTER TABLE extraction_config ADD PRIMARY KEY (section_key, scan_level);

-- Step 4: Seed Level 2 rows (all ON, no limits — preserves existing full-pipeline behaviour)
INSERT INTO extraction_config (section_key, scan_level, is_enabled, max_rows) VALUES
    -- Group 1: Core Identity
    ('hardware',            'level2', TRUE, NULL),
    ('version',             'level2', TRUE, NULL),

    -- Group 2: Storage Subsystem
    ('disks',               'level2', TRUE, NULL),
    ('raid',                'level2', TRUE, NULL),
    ('volumes',             'level2', TRUE, NULL),
    ('btrfs',               'level2', TRUE, NULL),
    ('storage_util',        'level2', TRUE, NULL),
    ('disk_io',             'level2', TRUE, NULL),

    -- Group 3: System Health
    ('logs',                'level2', TRUE, NULL),  -- No limit for L2 (full log context)
    ('dstate',              'level2', TRUE, NULL),  -- No limit for L2 (full stack traces)
    ('system_load',         'level2', TRUE, NULL),
    ('memory_util',         'level2', TRUE, NULL),
    ('network',             'level2', TRUE, NULL),

    -- Group 4: SQLite Forensic DB Sections
    ('db_system_events',    'level2', TRUE, NULL),  -- Unlimited for L2
    ('db_disk_health',      'level2', TRUE, NULL),
    ('db_connection_logs',  'level2', TRUE, NULL),  -- ON for L2 (security context matters)
    ('db_disk_events',      'level2', TRUE, NULL)   -- Unlimited for L2

ON CONFLICT (section_key, scan_level) DO NOTHING;

-- Step 5: Update table comment
COMMENT ON TABLE extraction_config IS 'Per-section extraction toggles and row limits for L1 and L2 forensic scans.';
