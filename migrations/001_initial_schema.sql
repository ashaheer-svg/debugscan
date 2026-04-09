-- AI DebugScan v3 - Initial Schema
-- ============================================================
-- EXTENSIONS
-- ============================================================
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ============================================================
-- ENUMS
-- ============================================================
CREATE TYPE user_role AS ENUM ('admin', 'tenant');
CREATE TYPE user_status AS ENUM ('active', 'inactive', 'suspended');
CREATE TYPE scan_level AS ENUM ('level1', 'level2');
CREATE TYPE scan_status AS ENUM ('queued', 'running', 'completed', 'failed', 'cancelled');
CREATE TYPE severity_level AS ENUM ('critical', 'warning', 'info', 'ok');
CREATE TYPE audit_action AS ENUM (
    'login', 'logout', 'login_failed',
    'user_created', 'user_updated', 'user_deactivated', 'user_deleted',
    'project_created', 'project_updated', 'project_deleted',
    'file_uploaded', 'file_deleted',
    'scan_queued', 'scan_started', 'scan_completed', 'scan_failed',
    'tokens_allocated', 'tokens_deducted',
    'settings_updated', 'password_changed'
);

-- ============================================================
-- TABLES
-- ============================================================

-- System settings (singleton table)
CREATE TABLE system_settings (
    id INTEGER PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    groq_api_key_encrypted TEXT NOT NULL,
    level1_model VARCHAR(100) NOT NULL DEFAULT 'llama-3.3-70b-versatile',
    level2_model VARCHAR(100) NOT NULL DEFAULT 'llama-3.3-70b-versatile',
    level1_max_input_tokens INTEGER NOT NULL DEFAULT 8000,
    level1_max_output_tokens INTEGER NOT NULL DEFAULT 4000,
    level2_max_input_tokens INTEGER NOT NULL DEFAULT 32000,
    level2_max_output_tokens INTEGER NOT NULL DEFAULT 8000,
    max_concurrent_scans INTEGER NOT NULL DEFAULT 2,
    retention_days INTEGER NOT NULL DEFAULT 90,
    max_upload_size_mb INTEGER NOT NULL DEFAULT 200,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Users (both admin and tenant)
CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    role user_role NOT NULL DEFAULT 'tenant',
    status user_status NOT NULL DEFAULT 'active',
    tenant_id UUID,  -- NULL for admin users
    tokens_available INTEGER NOT NULL DEFAULT 0,
    tokens_used INTEGER NOT NULL DEFAULT 0,
    scans_level1_count INTEGER NOT NULL DEFAULT 0,
    scans_level2_count INTEGER NOT NULL DEFAULT 0,
    last_login_at TIMESTAMPTZ,
    last_login_ip INET,
    failed_login_count INTEGER NOT NULL DEFAULT 0,
    locked_until TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Projects
CREATE TABLE projects (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name VARCHAR(200) NOT NULL,
    serial_number VARCHAR(50),
    model VARCHAR(100),
    dsm_version VARCHAR(20),
    drive_bays INTEGER,
    ram_gb NUMERIC(6,2),
    cpu_model VARCHAR(200),
    notes TEXT,
    last_scan_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_projects_tenant ON projects(tenant_id);
CREATE INDEX idx_projects_serial ON projects(serial_number);

-- Debug files
CREATE TABLE debug_files (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    project_id UUID NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    tenant_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    file_size_bytes BIGINT NOT NULL,
    file_hash_sha256 VARCHAR(64) NOT NULL,
    storage_path TEXT NOT NULL,
    extraction_data JSONB,
    extraction_status VARCHAR(20) DEFAULT 'pending',
    extraction_error TEXT,
    dsm_version VARCHAR(20),
    nas_model VARCHAR(100),
    nas_serial VARCHAR(50),
    upload_duration_ms INTEGER,
    extraction_duration_ms INTEGER,
    expires_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_debug_files_project ON debug_files(project_id);
CREATE INDEX idx_debug_files_tenant ON debug_files(tenant_id);
CREATE INDEX idx_debug_files_hash ON debug_files(file_hash_sha256);
CREATE INDEX idx_debug_files_expires ON debug_files(expires_at);

-- Scan jobs
CREATE TABLE scan_jobs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    project_id UUID NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    scan_level scan_level NOT NULL,
    status scan_status NOT NULL DEFAULT 'queued',
    priority INTEGER NOT NULL DEFAULT 0,
    queue_position INTEGER,
    debug_file_ids UUID[] NOT NULL,
    ai_model VARCHAR(100) NOT NULL,
    max_input_tokens INTEGER NOT NULL,
    max_output_tokens INTEGER NOT NULL,
    queued_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    started_at TIMESTAMPTZ,
    completed_at TIMESTAMPTZ,
    queue_wait_ms INTEGER,
    extraction_duration_ms INTEGER,
    prompt_assembly_duration_ms INTEGER,
    ai_request_duration_ms INTEGER,
    post_processing_duration_ms INTEGER,
    total_duration_ms INTEGER,
    ai_input_tokens_used INTEGER,
    ai_output_tokens_used INTEGER,
    ai_prompt_version VARCHAR(20),
    result_summary JSONB,
    result_input_payload JSONB,
    result_raw_response TEXT,

    health_score VARCHAR(20),
    findings_count INTEGER DEFAULT 0,
    error_message TEXT,
    error_code VARCHAR(50),
    retry_count INTEGER NOT NULL DEFAULT 0,
    tokens_charged INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_scan_jobs_tenant ON scan_jobs(tenant_id);
CREATE INDEX idx_scan_jobs_project ON scan_jobs(project_id);
CREATE INDEX idx_scan_jobs_status ON scan_jobs(status);
CREATE INDEX idx_scan_jobs_queue ON scan_jobs(status, priority DESC, queued_at ASC) WHERE status = 'queued';

-- Scan findings
CREATE TABLE scan_findings (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    scan_job_id UUID NOT NULL REFERENCES scan_jobs(id) ON DELETE CASCADE,
    tenant_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    category VARCHAR(50) NOT NULL,
    severity severity_level NOT NULL,
    title VARCHAR(300) NOT NULL,
    description TEXT NOT NULL,
    root_cause TEXT,
    recommendation TEXT NOT NULL,
    evidence JSONB NOT NULL,
    confidence_score NUMERIC(3,2),
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_scan_findings_job ON scan_findings(scan_job_id);
CREATE INDEX idx_scan_findings_tenant ON scan_findings(tenant_id);

-- Audit log
CREATE TABLE audit_log (
    id BIGSERIAL PRIMARY KEY,
    user_id UUID REFERENCES users(id) ON DELETE SET NULL,
    tenant_id UUID,
    action audit_action NOT NULL,
    resource_type VARCHAR(50),
    resource_id UUID,
    details JSONB,
    ip_address INET,
    user_agent TEXT,
    duration_ms INTEGER,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_audit_log_user ON audit_log(user_id);
CREATE INDEX idx_audit_log_tenant ON audit_log(tenant_id);

-- Sessions
CREATE TABLE sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id UUID REFERENCES users(id) ON DELETE CASCADE,
    data TEXT NOT NULL,
    last_activity TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    ip_address INET,
    user_agent TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_sessions_user ON sessions(user_id);

-- Extraction errors
CREATE TABLE extraction_errors (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    debug_file_id UUID NOT NULL REFERENCES debug_files(id) ON DELETE CASCADE,
    tenant_id UUID NOT NULL,
    error_type VARCHAR(50) NOT NULL,
    error_message TEXT NOT NULL,
    file_path_in_zip TEXT,
    severity VARCHAR(20) NOT NULL DEFAULT 'warning',
    resolved BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_extraction_errors_file ON extraction_errors(debug_file_id);
CREATE INDEX idx_extraction_errors_unresolved ON extraction_errors(resolved) WHERE resolved = FALSE;
