-- AI DebugScan v3 - Migration 015: Customizable Reporting System
-- ============================================================

-- Ensure UUID extension is available
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- 1. Create Report Plans Table
CREATE TABLE IF NOT EXISTS report_plans (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL,
    description TEXT,
    token_charge INTEGER NOT NULL DEFAULT 100000,
    prompt_header TEXT,
    ai_model VARCHAR(100) NOT NULL DEFAULT 'llama-3.3-70b-versatile',
    max_input_tokens INTEGER DEFAULT 40000,
    max_output_tokens INTEGER DEFAULT 40000,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- 2. Seed Default Plans (Level 1 and Level 2)
-- Level 1: Standard Forensic Audit
INSERT INTO report_plans (id, name, description, token_charge, prompt_header, max_input_tokens, max_output_tokens)
VALUES (
    '11111111-1111-4111-a111-111111111111', 
    'Level 1: Standard Forensic Audit', 
    'Rapid hardware and system integrity check. Optimized for speed and essential diagnostic visibility.',
    100000,
    'You are the Lead Forensic Support Engineer for Synology. Your task is to analyze diagnostic data and provide a rapid health audit focusing on hardware identity and critical system alerts.',
    20000,
    4000
) ON CONFLICT (id) DO NOTHING;

-- Level 2: Deep Forensic Analysis
INSERT INTO report_plans (id, name, description, token_charge, prompt_header, max_input_tokens, max_output_tokens)
VALUES (
    '22222222-2222-4222-a222-222222222222', 
    'Level 2: Deep Forensic Analysis', 
    'Comprehensive multi-file analysis covering storage telemetry, Btrfs integrity, and historical log correlation.',
    250000,
    'You are the Lead Forensic Support Engineer for Synology. Your task is to perform an exhaustive deep-dive analysis of all provided diagnostic blocks. Correlate historical logs with physical disk telemetry to find root causes.',
    100000,
    40000
) ON CONFLICT (id) DO NOTHING;

-- 3. Create Tenant-Plan Mapping Table (Selection Grid support)
CREATE TABLE IF NOT EXISTS tenant_report_plans (
    tenant_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    report_plan_id UUID NOT NULL REFERENCES report_plans(id) ON DELETE CASCADE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    PRIMARY KEY (tenant_id, report_plan_id)
);

-- 4. Automatically give all existing tenants access to the seeded L1/L2 plans
INSERT INTO tenant_report_plans (tenant_id, report_plan_id)
SELECT id, '11111111-1111-4111-a111-111111111111' FROM users WHERE role = 'tenant'
ON CONFLICT DO NOTHING;

INSERT INTO tenant_report_plans (tenant_id, report_plan_id)
SELECT id, '22222222-2222-4222-a222-222222222222' FROM users WHERE role = 'tenant'
ON CONFLICT DO NOTHING;

-- 5. Refactor Extraction Config to use Plan IDs
ALTER TABLE extraction_config ADD COLUMN IF NOT EXISTS report_plan_id UUID REFERENCES report_plans(id) ON DELETE CASCADE;

-- Map legacy level strings to new UUIDs
UPDATE extraction_config SET report_plan_id = '11111111-1111-4111-a111-111111111111' WHERE scan_level = 'level1';
UPDATE extraction_config SET report_plan_id = '22222222-2222-4222-a222-222222222222' WHERE scan_level = 'level2';

-- We keep scan_level column for a moment but the new constraint will be on report_plan_id
-- We remove the old constraint and add a new one
ALTER TABLE extraction_config DROP CONSTRAINT IF EXISTS extraction_config_pkey;
ALTER TABLE extraction_config ADD PRIMARY KEY (section_key, report_plan_id);

-- 6. Update Scan Jobs to support Plan IDs
ALTER TABLE scan_jobs ADD COLUMN IF NOT EXISTS report_plan_id UUID REFERENCES report_plans(id);

-- Link existing jobs to the seeded plans based on their level string
UPDATE scan_jobs SET report_plan_id = '11111111-1111-4111-a111-111111111111' WHERE scan_level = 'level1';
UPDATE scan_jobs SET report_plan_id = '22222222-2222-4222-a222-222222222222' WHERE scan_level = 'level2';

COMMENT ON COLUMN report_plans.system_prompt IS 'Customizable AI instruction header for this specific report package.';
