# AI DebugScan v3 — Complete Development Specification

**Version:** 1.0
**Date:** 2026-04-07
**Status:** Final — Ready for AI-assisted development
**Scope:** Full-stack multi-tenant Synology NAS debug file analysis platform

---

## TABLE OF CONTENTS

1. [Project Overview](#1-project-overview)
2. [System Constraints & Assumptions](#2-system-constraints--assumptions)
3. [Technology Stack](#3-technology-stack)
4. [Folder Structure](#4-folder-structure)
5. [Database Schema](#5-database-schema)
6. [Authentication & Authorization](#6-authentication--authorization)
7. [Scan Queue System](#7-scan-queue-system)
8. [File Processing Pipeline](#8-file-processing-pipeline)
9. [AI Integration Layer](#9-ai-integration-layer)
10. [Admin Module](#10-admin-module)
11. [Tenant Module](#11-tenant-module)
12. [Audit & Performance Logging](#12-audit--performance-logging)
13. [API Endpoint Reference](#13-api-endpoint-reference)
14. [UI Page Map & Routing](#14-ui-page-map--routing)
15. [Security Specifications](#15-security-specifications)
16. [Error Handling Strategy](#16-error-handling-strategy)
17. [Retention & Cleanup](#17-retention--cleanup)
18. [Deployment Procedure](#18-deployment-procedure)
19. [Configuration Reference](#19-configuration-reference)
20. [Development Workflow & keydata.md](#20-development-workflow--keydatamd)

---

## 1. PROJECT OVERVIEW

### 1.1 Purpose

AI DebugScan v3 is a multi-tenant web application that analyzes Synology NAS debug.dat files to identify hardware failures, RAID degradation, network issues, performance bottlenecks, and security concerns. It uses AI-powered diagnostics with evidence-based findings cited from actual log entries.

### 1.2 Business Model

- **Free tier:** Hardware extraction — model, serial, drives, RAID topology, volume details. Provided to all tenants on subscription.
- **Paid tier:** AI-powered deep analysis using scan tokens.
  - Level 1 scan: Basic AI analysis. 1 scan token. Smaller context window, lighter AI model.
  - Level 2 scan: Deep multi-file analysis. 2 scan tokens. Larger context window, stronger AI model.
- Scan tokens purchased online (payment integration out of scope for v1; admin manually allocates tokens).

### 1.3 Target Scale (v1 — Trial/MVP)

- Maximum 2 concurrent AI scans at any time.
- Additional scans queued with FIFO ordering.
- Single VPS deployment. Resources will be increased based on observed usage patterns.
- Target: ~10-20 active tenants for initial trial phase.

---

## 2. SYSTEM CONSTRAINTS & ASSUMPTIONS

| Constraint | Value | Notes |
|---|---|---|
| Max concurrent scans | 2 | Enforced by scan queue table with `running` status count |
| Max upload file size | 200 MB | Enforced at nginx and PHP level |
| Max files extracted per debug.dat | ~50 targeted files | Selective extraction per Hardwarev2.md |
| Scan timeout (Level 1) | 120 seconds | Kill scan and mark failed if exceeded |
| Scan timeout (Level 2) | 300 seconds | Larger context = longer AI response |
| File retention default | 90 days | Admin-configurable. Applies to uploaded .dat files and generated reports |
| Session lifetime | 8 hours | Sliding expiry |
| Password hash algorithm | bcrypt (cost 12) | Via PHP `password_hash(PASSWORD_BCRYPT)` |
| Minimum PHP version | 8.2 | Required for fibers, enums, readonly properties |
| PostgreSQL version | 15+ | Required for JSONB, RLS, generated columns |
| Development credentials | For dev only | Will be rotated for production. SSH key auth for production. |

---

## 3. TECHNOLOGY STACK

### 3.1 Server Stack

| Component | Technology | Purpose |
|---|---|---|
| Web server | nginx 1.24+ | Reverse proxy, TLS termination, upload buffering, static files |
| Application | PHP 8.2+ with PHP-FPM | All application logic, API, UI rendering |
| Database | PostgreSQL 15+ | Persistent storage with JSONB, RLS |
| Session store | PostgreSQL | `sessions` table (avoids Redis dependency for MVP) |
| Queue | PostgreSQL | `scan_queue` table with advisory locks (avoids Redis dependency) |
| Cron | System crontab | Queue worker, retention cleanup, stale scan recovery |
| TLS | Let's Encrypt / Certbot | HTTPS (mandatory for production; skip for local dev) |

### 3.2 PHP Libraries (Composer)

| Package | Purpose |
|---|---|
| `slim/slim` ^4.x | Lightweight HTTP framework (routing, middleware, DI) |
| `slim/psr7` | PSR-7 HTTP message implementation |
| `php-di/php-di` | Dependency injection container |
| `twig/twig` ^3.x | Template engine for server-rendered HTML |
| `vlucas/phpdotenv` | Environment variable loading (.env) |
| `monolog/monolog` | Structured logging (JSON format) |
| `guzzlehttp/guzzle` ^7.x | HTTP client for Groq API calls |
| `ramsey/uuid` | UUID generation for IDs |
| `respect/validation` | Input validation |

### 3.3 Frontend

| Component | Technology |
|---|---|
| CSS Framework | Custom CSS following ActiveDesign.md (no Bootstrap) |
| Icons | Lucide icons (SVG, outline style) |
| JS Interactivity | Alpine.js 3.x (lightweight reactivity for toggles, modals, dropdowns) |
| Charts | Chart.js 4.x (line charts, donut charts, bar charts per ActiveDesign.md chart selection guide) |
| Progress updates | Server-Sent Events (SSE) for scan progress |
| File upload | Fetch API with progress events |

### 3.4 AI Provider

| Setting | Value |
|---|---|
| Primary provider | Groq |
| API base URL | `https://api.groq.com/openai/v1/chat/completions` |
| Auth | Bearer token from `GROQ_API_KEY` env var |
| Level 1 default model | Admin-selectable from Groq model list |
| Level 2 default model | Admin-selectable from Groq model list |
| Fallback on failure | Retry 2x with exponential backoff (2s, 4s). After 3rd failure, mark scan as `failed`. |
| Response format | Request `response_format: { type: "json_object" }` where supported |

---

## 4. FOLDER STRUCTURE

### 4.1 Local Development Structure

```
project-root/
├── guide/                          # READ-ONLY instruction files
│   ├── requirment.md
│   ├── ActiveDesign.md
│   ├── Hardwarev2.md
│   └── keydata.md                  # READ-WRITE project state file
├── tmpdev/                         # Temporary dev files (deleted after deployment)
├── sample/                         # Sample debug.dat files for testing
├── local/
│   └── devtmp/                     # Local temporary files
└── upload/                         # Full upload directory structure
    └── (mirrors remote webroot)
```

### 4.2 Remote Server Structure (webroot)

```
/var/www/debugscan/                 # Document root
├── public/                         # nginx document root (public-facing)
│   ├── index.php                   # Front controller (Slim app entry)
│   ├── assets/
│   │   ├── css/
│   │   │   └── app.css             # Compiled from ActiveDesign.md specs
│   │   ├── js/
│   │   │   ├── alpine.min.js
│   │   │   ├── chart.min.js
│   │   │   └── app.js              # Custom JS (SSE handler, upload, modals)
│   │   ├── icons/                  # Lucide SVG icons
│   │   └── img/                    # Logo, favicons
│   └── .htaccess                   # Fallback for Apache (cPanel compat)
├── src/
│   ├── App.php                     # Slim app bootstrap
│   ├── Middleware/
│   │   ├── AuthMiddleware.php      # Session auth check
│   │   ├── AdminMiddleware.php     # Admin role check
│   │   ├── TenantMiddleware.php    # Tenant role + data scope
│   │   ├── CsrfMiddleware.php      # CSRF token validation
│   │   └── RateLimitMiddleware.php # Brute-force protection
│   ├── Controllers/
│   │   ├── AuthController.php      # Login, logout, password change
│   │   ├── AdminController.php     # Admin dashboard, tenant CRUD, system settings
│   │   ├── TenantController.php    # Tenant dashboard
│   │   ├── ProjectController.php   # Project CRUD, debug file upload
│   │   ├── ScanController.php      # Initiate scan, SSE progress, view results
│   │   ├── ReportController.php    # View/export scan reports
│   │   └── ApiController.php       # Internal API endpoints (JSON)
│   ├── Services/
│   │   ├── AuthService.php         # Password hashing, session management
│   │   ├── FileService.php         # Upload handling, ZIP validation, selective extraction
│   │   ├── ParseService.php        # Debug file parsing (Hardwarev2.md logic)
│   │   ├── ScanQueueService.php    # Queue management, concurrency control
│   │   ├── ScanWorkerService.php   # Execute scans (called by cron worker)
│   │   ├── AiService.php           # Groq API communication, prompt assembly
│   │   ├── TokenService.php        # Scan token accounting
│   │   ├── RetentionService.php    # File/report cleanup
│   │   └── AuditService.php        # Audit log writing
│   ├── Models/
│   │   ├── User.php
│   │   ├── Tenant.php
│   │   ├── Project.php
│   │   ├── DebugFile.php
│   │   ├── ScanJob.php
│   │   ├── ScanResult.php
│   │   └── AuditLog.php
│   ├── Parsers/                    # One parser per diagnostic category
│   │   ├── VersionParser.php       # DSM version detection (Section 1.2 of Hardwarev2.md)
│   │   ├── HardwareParser.php      # Model, serial, RAM, CPU (Sections 2.1, 3.1)
│   │   ├── DriveParser.php         # Drive inventory, health, bay topology (Sections 2.2, 3.2, 9)
│   │   ├── RaidParser.php          # mdstat, md_examine, LVM (Sections 2.3-2.4, 3.3-3.4)
│   │   ├── NetworkParser.php       # Interfaces, routes, SMB, NFS, firewall (Section 7)
│   │   ├── PerformanceParser.php   # CPU, memory, disk I/O, processes (Section 8)
│   │   ├── SystemParser.php        # Integrity checks, logs, packages (Section 4.7-4.8)
│   │   └── HealthScoreParser.php   # Composite health score (Section 4.9)
│   └── Helpers/
│       ├── ZipHelper.php           # Safe ZIP extraction with path traversal protection
│       ├── LogTailer.php           # Extract last N lines from log files
│       ├── DiskLogParser.php       # HTML and CSV disk log dual parser
│       └── TimeHelper.php          # Timestamp normalization
├── templates/                      # Twig templates
│   ├── layout/
│   │   ├── base.twig               # HTML skeleton, sidebar, topbar
│   │   ├── admin_base.twig         # Admin layout extending base
│   │   └── tenant_base.twig        # Tenant layout extending base
│   ├── auth/
│   │   └── login.twig
│   ├── admin/
│   │   ├── dashboard.twig          # System health, token utilization, tenant list
│   │   ├── tenants.twig            # Tenant list view
│   │   ├── tenant_form.twig        # Create/edit tenant
│   │   ├── settings.twig           # AI model selection, token limits, retention
│   │   └── error_log.twig          # Flagged debug files
│   ├── tenant/
│   │   ├── dashboard.twig          # Tenant home: projects, token balance
│   │   ├── projects.twig           # Project list view
│   │   ├── project_detail.twig     # Single project: NAS info, uploads, scans
│   │   ├── upload.twig             # Debug file upload with progress
│   │   ├── scan_progress.twig      # SSE-powered scan progress page
│   │   └── report.twig             # Scan report with findings
│   └── components/
│       ├── sidebar.twig
│       ├── topbar.twig
│       ├── toast.twig
│       ├── modal_confirm.twig
│       ├── empty_state.twig
│       ├── pagination.twig
│       └── kpi_card.twig
├── config/
│   ├── database.php                # PDO connection factory with RLS setup
│   ├── routes.php                  # All route definitions
│   ├── middleware.php              # Middleware stack
│   └── ai_prompts/
│       ├── level1_system.txt       # Level 1 system prompt template
│       ├── level1_user.txt         # Level 1 user prompt template (with placeholders)
│       ├── level2_system.txt       # Level 2 system prompt template
│       └── level2_user.txt         # Level 2 user prompt template
├── migrations/
│   ├── 001_initial_schema.sql
│   ├── 002_rls_policies.sql
│   ├── 003_seed_admin.sql
│   └── migrate.php                 # Simple migration runner
├── workers/
│   ├── scan_worker.php             # Cron-called: picks queued scans, executes (max 2 concurrent)
│   └── retention_worker.php        # Cron-called: deletes expired files and reports
├── storage/
│   ├── uploads/                    # Tenant debug.dat files (tenant_id/project_id/filename)
│   ├── extracted/                  # Cached extracted data (hash-keyed)
│   ├── reports/                    # Generated report JSONs
│   └── logs/                       # Application logs (JSON structured)
├── .env                            # Environment variables (not in git)
├── .env.example                    # Template
├── composer.json
└── README.md
```

---

## 5. DATABASE SCHEMA

### 5.1 Complete Schema

```sql
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
    tenant_id UUID,  -- NULL for admin users; self-referencing for tenant grouping
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

-- Projects (one NAS per project, identified by serial number)
CREATE TABLE projects (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name VARCHAR(200) NOT NULL,
    serial_number VARCHAR(50),           -- Extracted from debug file; NULL if unidentified
    model VARCHAR(100),                  -- Extracted from debug file
    dsm_version VARCHAR(20),             -- Latest known DSM version
    drive_bays INTEGER,                  -- Total bay count
    ram_gb NUMERIC(6,2),                 -- Total RAM in GB
    cpu_model VARCHAR(200),
    notes TEXT,
    last_scan_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_projects_tenant ON projects(tenant_id);
CREATE INDEX idx_projects_serial ON projects(serial_number);

-- Debug files (uploaded .dat files)
CREATE TABLE debug_files (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    project_id UUID NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    tenant_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,      -- UUID-based name on disk
    file_size_bytes BIGINT NOT NULL,
    file_hash_sha256 VARCHAR(64) NOT NULL,      -- For dedup/cache
    storage_path TEXT NOT NULL,                  -- Relative path under storage/uploads/
    extraction_data JSONB,                       -- Cached parsed hardware/system data
    extraction_status VARCHAR(20) DEFAULT 'pending',  -- pending, completed, failed
    extraction_error TEXT,                       -- Error message if extraction failed
    dsm_version VARCHAR(20),                    -- Extracted DSM version
    nas_model VARCHAR(100),                     -- Extracted model
    nas_serial VARCHAR(50),                     -- Extracted serial
    upload_duration_ms INTEGER,                 -- Time to upload
    extraction_duration_ms INTEGER,             -- Time to extract and parse
    expires_at TIMESTAMPTZ,                     -- Based on retention_days
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_debug_files_project ON debug_files(project_id);
CREATE INDEX idx_debug_files_tenant ON debug_files(tenant_id);
CREATE INDEX idx_debug_files_hash ON debug_files(file_hash_sha256);
CREATE INDEX idx_debug_files_expires ON debug_files(expires_at);

-- Scan queue and results
CREATE TABLE scan_jobs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    tenant_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    project_id UUID NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    scan_level scan_level NOT NULL,
    status scan_status NOT NULL DEFAULT 'queued',
    priority INTEGER NOT NULL DEFAULT 0,         -- Higher = processed first
    queue_position INTEGER,                      -- Calculated; display only

    -- Input: which debug files to analyze
    debug_file_ids UUID[] NOT NULL,              -- Array of debug_file IDs

    -- AI configuration snapshot (captured at queue time from system_settings)
    ai_model VARCHAR(100) NOT NULL,
    max_input_tokens INTEGER NOT NULL,
    max_output_tokens INTEGER NOT NULL,

    -- Timing metrics
    queued_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    started_at TIMESTAMPTZ,
    completed_at TIMESTAMPTZ,
    queue_wait_ms INTEGER,                       -- started_at - queued_at
    extraction_duration_ms INTEGER,              -- Time spent parsing files
    prompt_assembly_duration_ms INTEGER,          -- Time assembling AI prompt
    ai_request_duration_ms INTEGER,              -- Time waiting for AI response
    post_processing_duration_ms INTEGER,          -- Time validating/storing result
    total_duration_ms INTEGER,                   -- Total end-to-end

    -- AI usage
    ai_input_tokens_used INTEGER,
    ai_output_tokens_used INTEGER,
    ai_prompt_version VARCHAR(20),               -- Prompt template version

    -- Result
    result_summary JSONB,                        -- Structured findings
    result_raw_response TEXT,                     -- Raw AI response for debugging
    health_score VARCHAR(20),                    -- CRITICAL / WARNING / NORMAL
    findings_count INTEGER DEFAULT 0,

    -- Error handling
    error_message TEXT,
    error_code VARCHAR(50),
    retry_count INTEGER NOT NULL DEFAULT 0,

    -- Token cost
    tokens_charged INTEGER NOT NULL DEFAULT 0,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_scan_jobs_tenant ON scan_jobs(tenant_id);
CREATE INDEX idx_scan_jobs_project ON scan_jobs(project_id);
CREATE INDEX idx_scan_jobs_status ON scan_jobs(status);
CREATE INDEX idx_scan_jobs_queue ON scan_jobs(status, priority DESC, queued_at ASC)
    WHERE status = 'queued';

-- Individual findings from a scan
CREATE TABLE scan_findings (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    scan_job_id UUID NOT NULL REFERENCES scan_jobs(id) ON DELETE CASCADE,
    tenant_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    category VARCHAR(50) NOT NULL,               -- hardware, network, raid, performance, security, other
    severity severity_level NOT NULL,
    title VARCHAR(300) NOT NULL,
    description TEXT NOT NULL,
    root_cause TEXT,
    recommendation TEXT NOT NULL,
    evidence JSONB NOT NULL,                     -- Array of { log_line, source_file, line_number }
    confidence_score NUMERIC(3,2),               -- 0.00 to 1.00
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_scan_findings_job ON scan_findings(scan_job_id);
CREATE INDEX idx_scan_findings_tenant ON scan_findings(tenant_id);
CREATE INDEX idx_scan_findings_severity ON scan_findings(severity);

-- Audit log (append-only)
CREATE TABLE audit_log (
    id BIGSERIAL PRIMARY KEY,
    user_id UUID REFERENCES users(id) ON DELETE SET NULL,
    tenant_id UUID,
    action audit_action NOT NULL,
    resource_type VARCHAR(50),                   -- user, project, debug_file, scan_job, settings
    resource_id UUID,
    details JSONB,                               -- Action-specific metadata
    ip_address INET,
    user_agent TEXT,
    duration_ms INTEGER,                         -- How long the action took
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_audit_log_user ON audit_log(user_id);
CREATE INDEX idx_audit_log_tenant ON audit_log(tenant_id);
CREATE INDEX idx_audit_log_action ON audit_log(action);
CREATE INDEX idx_audit_log_created ON audit_log(created_at);

-- Performance metrics log
CREATE TABLE performance_log (
    id BIGSERIAL PRIMARY KEY,
    scan_job_id UUID REFERENCES scan_jobs(id) ON DELETE CASCADE,
    metric_name VARCHAR(100) NOT NULL,           -- e.g., 'extraction_time', 'ai_latency', 'prompt_tokens'
    metric_value NUMERIC NOT NULL,
    unit VARCHAR(20) NOT NULL,                   -- 'ms', 'tokens', 'bytes', 'count'
    context JSONB,                               -- Additional context (model, file_size, etc.)
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_perf_log_scan ON performance_log(scan_job_id);
CREATE INDEX idx_perf_log_metric ON performance_log(metric_name, created_at);

-- Session storage (PHP sessions in DB)
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
CREATE INDEX idx_sessions_activity ON sessions(last_activity);

-- Extraction error log (flagged debug files)
CREATE TABLE extraction_errors (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    debug_file_id UUID NOT NULL REFERENCES debug_files(id) ON DELETE CASCADE,
    tenant_id UUID NOT NULL,
    error_type VARCHAR(50) NOT NULL,             -- missing_file, parse_error, corrupt_zip, timeout
    error_message TEXT NOT NULL,
    file_path_in_zip TEXT,                       -- Which file inside the ZIP caused the error
    severity VARCHAR(20) NOT NULL DEFAULT 'warning',  -- warning, error
    resolved BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_extraction_errors_file ON extraction_errors(debug_file_id);
CREATE INDEX idx_extraction_errors_unresolved ON extraction_errors(resolved) WHERE resolved = FALSE;
```

### 5.2 Row-Level Security Policies

```sql
-- Enable RLS on tenant-scoped tables
ALTER TABLE projects ENABLE ROW LEVEL SECURITY;
ALTER TABLE debug_files ENABLE ROW LEVEL SECURITY;
ALTER TABLE scan_jobs ENABLE ROW LEVEL SECURITY;
ALTER TABLE scan_findings ENABLE ROW LEVEL SECURITY;

-- Application sets this at connection start:
-- SET app.current_tenant_id = '<tenant_uuid>';
-- SET app.current_user_role = 'admin' | 'tenant';

-- Admin can see everything; tenant sees only own data
CREATE POLICY tenant_isolation_projects ON projects
    USING (
        current_setting('app.current_user_role', true) = 'admin'
        OR tenant_id = current_setting('app.current_tenant_id', true)::uuid
    );

CREATE POLICY tenant_isolation_debug_files ON debug_files
    USING (
        current_setting('app.current_user_role', true) = 'admin'
        OR tenant_id = current_setting('app.current_tenant_id', true)::uuid
    );

CREATE POLICY tenant_isolation_scan_jobs ON scan_jobs
    USING (
        current_setting('app.current_user_role', true) = 'admin'
        OR tenant_id = current_setting('app.current_tenant_id', true)::uuid
    );

CREATE POLICY tenant_isolation_scan_findings ON scan_findings
    USING (
        current_setting('app.current_user_role', true) = 'admin'
        OR tenant_id = current_setting('app.current_tenant_id', true)::uuid
    );
```

### 5.3 Database Connection Setup

Every PHP request must set the RLS context after connecting:

```php
// In database.php or middleware
function setTenantContext(PDO $pdo, ?string $tenantId, string $role): void {
    if ($role === 'admin') {
        $pdo->exec("SET app.current_user_role = 'admin'");
        $pdo->exec("SET app.current_tenant_id = '00000000-0000-0000-0000-000000000000'");
    } else {
        $pdo->exec("SET app.current_user_role = 'tenant'");
        $pdo->exec(sprintf(
            "SET app.current_tenant_id = %s",
            $pdo->quote($tenantId)
        ));
    }
}
```

### 5.4 Seed Data

```sql
-- Default admin user (password: change-me-immediately)
INSERT INTO users (id, email, password_hash, display_name, role, status)
VALUES (
    uuid_generate_v4(),
    'admin@debugscan.local',
    -- bcrypt hash of 'change-me-immediately'
    '$2y$12$placeholder_hash_here',
    'System Administrator',
    'admin',
    'active'
);

-- Default system settings
INSERT INTO system_settings (groq_api_key_encrypted, level1_model, level2_model)
VALUES ('encrypted_key_here', 'llama-3.3-70b-versatile', 'llama-3.3-70b-versatile');
```

---

## 6. AUTHENTICATION & AUTHORIZATION

### 6.1 Login Flow

1. User submits email + password to `POST /auth/login`.
2. Check `failed_login_count` — if >= 5, check `locked_until`. If still locked, return error with remaining lockout time.
3. Verify password with `password_verify()` against `password_hash`.
4. On failure: increment `failed_login_count`. If now >= 5, set `locked_until = NOW() + 15 minutes`. Log `login_failed` audit event.
5. On success: reset `failed_login_count` to 0. Update `last_login_at`, `last_login_ip`. Create PHP session. Set RLS context. Log `login` audit event.
6. Redirect to role-appropriate dashboard.

### 6.2 Session Management

- PHP native sessions stored in PostgreSQL `sessions` table via custom `SessionHandler`.
- Session cookie: `HttpOnly`, `Secure` (production), `SameSite=Strict`.
- Session lifetime: 8 hours sliding. Absolute max: 24 hours.
- On every request: check `sessions.last_activity` — if older than 8 hours, destroy session and redirect to login.

### 6.3 Password Requirements

- Minimum 8 characters.
- Must contain at least 1 uppercase, 1 lowercase, 1 digit.
- Hashed with `password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12])`.
- Password change requires current password confirmation.

### 6.4 CSRF Protection

- Every form includes a hidden `csrf_token` field.
- Token generated per-session: `$_SESSION['csrf_token'] = bin2hex(random_bytes(32))`.
- Validated on every POST/PUT/DELETE request by `CsrfMiddleware`.
- AJAX requests send token in `X-CSRF-Token` header.

### 6.5 Role Permissions Matrix

| Action | Admin | Tenant |
|---|---|---|
| View admin dashboard | Yes | No |
| Create/edit/delete tenants | Yes | No |
| Configure AI models & token limits | Yes | No |
| Set retention period | Yes | No |
| View all tenants' data | Yes | No |
| View own dashboard | Yes | Yes |
| Create/edit/delete own projects | No | Yes |
| Upload debug files to own projects | No | Yes |
| Initiate scans on own projects | No | Yes |
| View own scan results | No | Yes |
| View own token balance | No | Yes |

---

## 7. SCAN QUEUE SYSTEM

### 7.1 Queue Design (PostgreSQL-based)

The queue uses the `scan_jobs` table with `status = 'queued'` as the queue. Concurrency is controlled by counting rows with `status = 'running'` and comparing against `system_settings.max_concurrent_scans`.

### 7.2 Queue Worker (`workers/scan_worker.php`)

Runs via cron every 10 seconds:

```
* * * * * php /var/www/debugscan/workers/scan_worker.php >> /var/log/debugscan/worker.log 2>&1
* * * * * sleep 10 && php /var/www/debugscan/workers/scan_worker.php >> /var/log/debugscan/worker.log 2>&1
* * * * * sleep 20 && php /var/www/debugscan/workers/scan_worker.php >> /var/log/debugscan/worker.log 2>&1
* * * * * sleep 30 && php /var/www/debugscan/workers/scan_worker.php >> /var/log/debugscan/worker.log 2>&1
* * * * * sleep 40 && php /var/www/debugscan/workers/scan_worker.php >> /var/log/debugscan/worker.log 2>&1
* * * * * sleep 50 && php /var/www/debugscan/workers/scan_worker.php >> /var/log/debugscan/worker.log 2>&1
```

### 7.3 Worker Logic (Pseudocode)

```
BEGIN TRANSACTION;

-- Check how many scans are currently running
SELECT COUNT(*) AS running FROM scan_jobs WHERE status = 'running';
SELECT max_concurrent_scans FROM system_settings;

IF running >= max_concurrent_scans THEN
    COMMIT; EXIT;
END IF;

-- Claim the next queued job using SELECT FOR UPDATE SKIP LOCKED
SELECT * FROM scan_jobs
    WHERE status = 'queued'
    ORDER BY priority DESC, queued_at ASC
    LIMIT 1
    FOR UPDATE SKIP LOCKED;

IF no row found THEN
    COMMIT; EXIT;
END IF;

-- Claim it
UPDATE scan_jobs SET status = 'running', started_at = NOW() WHERE id = ?;
COMMIT;

-- Execute scan (outside transaction — long-running)
TRY:
    1. Load debug file(s) extraction_data from debug_files table
    2. If extraction_data is NULL, run extraction now and cache it
    3. Assemble AI prompt from extracted data + prompt template
    4. Record timing: prompt_assembly_duration_ms
    5. Call Groq API with retry logic (max 3 attempts, backoff 2s/4s)
    6. Record timing: ai_request_duration_ms
    7. Parse AI JSON response
    8. Validate findings: each finding must have evidence
    9. Store findings in scan_findings table
    10. Record timing: post_processing_duration_ms
    11. Deduct tokens from user
    12. UPDATE scan_jobs SET status = 'completed', all timing fields, result_summary
    13. Log audit: scan_completed
CATCH:
    UPDATE scan_jobs SET status = 'failed', error_message = ?
    Refund tokens if already deducted
    Log audit: scan_failed
```

### 7.4 Stale Scan Recovery

A separate cron job runs every 5 minutes:

```sql
-- Reset scans that have been 'running' for longer than timeout
UPDATE scan_jobs
SET status = 'failed',
    error_message = 'Scan timed out after ' ||
        CASE WHEN scan_level = 'level1' THEN '120' ELSE '300' END || ' seconds',
    completed_at = NOW()
WHERE status = 'running'
  AND started_at < NOW() - INTERVAL '1 second' *
    CASE WHEN scan_level = 'level1' THEN 120 ELSE 300 END;
```

### 7.5 SSE Progress Endpoint

`GET /scan/{id}/progress` — Server-Sent Events stream:

```php
// ScanController::progress()
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');

while (true) {
    $job = ScanJob::find($id);
    $data = json_encode([
        'status' => $job->status,
        'stage' => $job->current_stage,     // 'queued', 'extracting', 'analyzing', 'processing', 'complete'
        'queue_position' => $job->getQueuePosition(),
        'elapsed_ms' => $job->getElapsedMs(),
    ]);
    echo "data: {$data}\n\n";
    ob_flush(); flush();

    if (in_array($job->status, ['completed', 'failed', 'cancelled'])) break;
    sleep(2);
}
```

---

## 8. FILE PROCESSING PIPELINE

### 8.1 Upload Flow

1. **Client-side:** Validate file extension (`.dat`), file size (< max_upload_size_mb). Show progress bar via Fetch API `upload` event.
2. **Server-side (`POST /project/{id}/upload`):**
   - Verify CSRF token.
   - Verify tenant owns the project.
   - Verify file size within limit.
   - Generate UUID filename: `{uuid}.dat`.
   - Move to `storage/uploads/{tenant_id}/{project_id}/{uuid}.dat`.
   - Compute SHA256 hash.
   - Check if hash exists in `debug_files` — if yes, offer to reuse cached extraction.
   - Create `debug_files` row with `extraction_status = 'pending'`.
   - Trigger extraction immediately (synchronous for quick feedback on hardware data).
   - Log audit: `file_uploaded`.

### 8.2 ZIP Validation (ZipHelper.php)

Before extracting any file from the ZIP:

```php
function validateZip(string $path): ValidationResult {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ValidationResult::fail('Invalid or corrupt ZIP file');
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        // Path traversal protection
        if (str_contains($name, '..') || str_starts_with($name, '/')) {
            return ValidationResult::fail("Dangerous path detected: {$name}");
        }

        // Symlink protection
        $stat = $zip->statIndex($i);
        if (($stat['external_attr'] >> 16) & 0120000) {
            return ValidationResult::fail("Symlink detected: {$name}");
        }
    }

    // Check for dsm/ prefix (valid Synology debug file structure)
    $hasDsmPrefix = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if (str_starts_with($zip->getNameIndex($i), 'dsm/')) {
            $hasDsmPrefix = true;
            break;
        }
    }

    if (!$hasDsmPrefix) {
        return ValidationResult::fail('Not a valid Synology debug file: missing dsm/ structure');
    }

    $zip->close();
    return ValidationResult::ok();
}
```

### 8.3 Selective Extraction

Extract ONLY the files listed in Hardwarev2.md quick reference tables (Sections 2.6 and 3.6). The target file list:

```php
const EXTRACTION_TARGETS = [
    // Always present (minimum viable set)
    'dsm/etc/VERSION',
    'dsm/etc.defaults/VERSION',
    'dsm/proc/partitions',
    'dsm/proc/mdstat',
    'dsm/proc/meminfo',

    // Hardware identity
    'dsm/proc/sys/kernel/syno_hw_version',
    'dsm/etc/synoinfo.conf',
    'dsm/etc.defaults/synoinfo.conf',
    'dsm/proc/cpuinfo',
    'dsm/proc/uptime',
    'dsm/proc/loadavg',

    // Drive info
    'dsm/result/load_info.result',
    'dsm/var/log/disk_log.csv',
    'dsm/var/log/disk_log.html',
    'dsm/var/log/disk_testlog.csv',
    'dsm/var/log/disk_testlog.html',
    'dsm/result/synoblock_enum.result',
    'dsm/result/diskmaps_curr.result',
    'dsm/result/diskmaps_boot.result',

    // RAID
    'dsm/result/md_examine/',              // Directory — extract all .log files within
    'dsm/result/lv.result',
    'dsm/result/dm.result',
    'dsm/result/dmsetup-table.result',
    'dsm/result/dmsetup-status.result',
    'dsm/run/space/volume_status.cache',
    'dsm/run/space/space_meta.status',
    'dsm/run/space/datascrubbing.status.tmp',

    // Filesystem
    'dsm/var/log/btrfs/',                  // Directory — all .result files
    'dsm/var/log/tune2fs/',                // Directory — all .result files

    // Per-disk runtime health
    'dsm/run/synostorage/disks/',          // Directory — all files for all disks

    // Network
    'dsm/result/ifconfig.result',
    'dsm/result/route.result',
    'dsm/result/ethtool.',                 // Prefix — match all ethtool.*.result
    'dsm/proc/net/dev',
    'dsm/etc/resolv.conf',
    'dsm/etc/sysconfig/network-scripts/',  // Directory — all ifcfg-* files
    'dsm/result/showmount_exports.result',
    'dsm/result/netstat.result',
    'dsm/usr/syno/etc/firewall.d/',        // Directory
    'dsm/result/upsc.result',

    // SMB/NFS
    'SMBService/etc/samba/smb.conf',
    'dsm/etc/samba/smb.conf',

    // Performance
    'dsm/result/top.result',
    'dsm/proc/vmstat',
    'dsm/proc/diskstats',
    'dsm/result/free.result',
    'dsm/result/ps.result',

    // System integrity
    'dsm/result/synoselfcheck_dsm_full.result',
    'dsm/var/log/messages',                // Last 500 lines only
    'dsm/var/log/dmesg',                   // Last 500 lines only
    'dsm/var/log/synolog/synosys.log',     // Last 200 lines only
    'dsm/var/log/lastimproper.log',
    'dsm/var/log/rsync_signal.error',
    'dsm/var/log/bash_err.log',
    'dsm/var/log/synoinfo.conf.bad',

    // Packages & services
    'dsm/package_status.list',
    'HighAvailability/ha_not_running',

    // Active Insight
    'ActiveInsight/usr/local/packages/@appdata/ActiveInsight/pkg_status.json',

    // Superblock cache (present on some models)
    'dsm/run/synostorage/raid_superblock_cache/',  // Directory

    // Disk damage thresholds
    'dsm/etc.defaults/disk_adv_status.conf',
];
```

For large log files (`messages`, `dmesg`, `synosys.log`), extract the file but only retain the last N lines (configurable, default 500 for messages/dmesg, 200 for synosys.log) and entries from the last 12 months.

### 8.4 Parse Output Structure

Each parser produces a standardized JSON structure stored in `debug_files.extraction_data`:

```json
{
  "meta": {
    "extraction_version": "1.0",
    "extracted_at": "2026-04-07T10:30:00Z",
    "extraction_duration_ms": 1250,
    "dsm_major_version": 7,
    "drive_naming_style": "sata",
    "disk_log_format": "csv",
    "files_found": 47,
    "files_missing": ["dsm/var/log/lastimproper.log"],
    "warnings": ["disk_log.html also present — merged pre-upgrade entries"]
  },
  "identity": {
    "model": "RS1221rp+",
    "serial": "2550RXR77XJT0",
    "dsm_version": "7.3",
    "dsm_build": "81180",
    "dsm_build_date": "2025/10/03",
    "ram_gb": 16,
    "cpu_model": "Intel Xeon D-1541 @ 2.10GHz",
    "cpu_cores": 8,
    "uptime_days": 45.3,
    "capture_timestamp": "2026-03-15T14:22:00Z"
  },
  "drives": [
    {
      "id": "sata1",
      "bay": 1,
      "unit": "RS1221RP+",
      "unit_type": "internal",
      "model": "HAT5300-4T",
      "serial": "2420U6RA0A06QFW1H",
      "vendor": "Synology",
      "firmware": "1403",
      "capacity_tb": 3.63,
      "temperature_c": 22,
      "status": "normal",
      "smart_status": "normal",
      "unc_count": 0,
      "bad_sector_count": 0,
      "reset_fail_status": "normal",
      "timeout_events": 0,
      "predict_status": "normal",
      "low_perf_in_raid": null
    }
  ],
  "raid": {
    "arrays": [
      {
        "name": "md2",
        "level": "raid5",
        "configured_members": 5,
        "active_members": 5,
        "state_string": "UUUUU",
        "is_degraded": false,
        "is_rebuilding": false,
        "total_size_tib": 14.51,
        "superblock_version": "1.2",
        "members": ["sata1p3", "sata2p3", "sata3p3", "sata4p3", "sata5p3"]
      }
    ],
    "md_examine_issues": [],
    "superblock_state": "clean"
  },
  "volumes": [
    {
      "id": "volume_1",
      "status": "normal",
      "filesystem": "btrfs",
      "total_size_gb": 14508.2,
      "used_size_gb": 4875.1,
      "usage_percent": 33.6,
      "scrub_status": "idle",
      "btrfs_errors": {}
    }
  ],
  "network": {
    "interfaces": [
      {
        "name": "eth0",
        "ip": "192.168.0.100",
        "is_apipa": false,
        "is_link_up": true,
        "speed_mbps": 1000,
        "duplex": "Full",
        "errors": { "rx_errors": 0, "tx_errors": 0, "collisions": 0 }
      }
    ],
    "default_gateway": "192.168.0.1",
    "dns_servers": ["192.168.0.1"],
    "smb_min_protocol": "SMB2",
    "firewall_default_policy": "allow",
    "ups_status": null
  },
  "performance": {
    "load_average": [1.2, 1.5, 1.3],
    "io_load": [0.8, 1.1, 0.9],
    "cpu_load": [0.4, 0.4, 0.4],
    "io_wait_percent": 5.2,
    "memory_usage_percent": 14,
    "swap_usage_percent": 0,
    "zombie_processes": 0
  },
  "system_integrity": {
    "improper_shutdown": false,
    "self_check_result": "Check Success",
    "kernel_errors": [],
    "oom_events": [],
    "ha_configured": false,
    "active_insight_status": null
  },
  "packages": [
    { "name": "ContainerManager", "version": "24.0.2-1726", "status": "running" }
  ],
  "health_score": {
    "overall": "NORMAL",
    "critical_count": 0,
    "warning_count": 0,
    "categories": {
      "hardware": "NORMAL",
      "raid": "NORMAL",
      "network": "NORMAL",
      "performance": "NORMAL",
      "security": "NORMAL"
    }
  },
  "critical_log_entries": [
    {
      "source": "dsm/var/log/messages",
      "line": "Mar 15 02:33:40 NAS kernel: [12345.678] ata4: COMRESET failed",
      "timestamp": "2026-03-15T02:33:40",
      "severity": "error"
    }
  ]
}
```

---

## 9. AI INTEGRATION LAYER

### 9.1 Prompt Architecture

Two prompt template files per scan level:

**System prompt** — defines the AI's role, output schema, and rules.
**User prompt** — contains the actual parsed data with placeholders filled at runtime.

### 9.2 Level 1 System Prompt (`config/ai_prompts/level1_system.txt`)

```
You are a Synology NAS diagnostic specialist. You analyze debug file data to identify current and potential issues.

RULES:
1. Every finding MUST include evidence — specific log entries or data values that prove the issue exists.
2. Identify root causes. A filesystem error may be caused by a failing drive; a slow response may be caused by high I/O wait from a degraded RAID. Trace symptoms to their origin.
3. Do not flag normal conditions as issues. For example:
   - Low RAM usage is normal even if total RAM is small, unless I/O wait or swap usage proves it is causing problems.
   - [8/5] [UUUUU___] in mdstat is normal for an 8-bay NAS with 5 drives — this is NOT degradation.
   - A short uptime simply means a recent reboot — only flag if there is evidence of an unclean shutdown.
   - SATA PHYRdyChg errors on expansion unit drives during bootup/plugin events are normal.
4. Classify severity accurately:
   - CRITICAL: Imminent data loss risk, drive failure, degraded RAID, kernel panic.
   - WARNING: Performance degradation, approaching thresholds, conditions that may worsen.
   - INFO: Notable observations that are not harmful but worth knowing.
   - OK: Explicitly verified healthy state (include at least one OK finding per category).
5. For each finding provide: category, severity, title, description, root_cause, recommendation, evidence (array of log lines with source file), confidence_score (0.0-1.0).

OUTPUT FORMAT: Valid JSON object with this exact structure:
{
  "findings": [
    {
      "category": "hardware|raid|network|performance|security|system",
      "severity": "critical|warning|info|ok",
      "title": "Short descriptive title",
      "description": "Detailed explanation of the finding",
      "root_cause": "Root cause analysis (null if severity is ok)",
      "recommendation": "Actionable fix or monitoring advice",
      "evidence": [
        {"log_line": "exact log text", "source_file": "file/path", "context": "why this line matters"}
      ],
      "confidence_score": 0.95
    }
  ],
  "overall_health": "CRITICAL|WARNING|NORMAL",
  "summary": "2-3 sentence executive summary"
}
```

### 9.3 Level 1 User Prompt Template (`config/ai_prompts/level1_user.txt`)

```
Analyze this Synology NAS debug file data. The NAS is a {{model}} running DSM {{dsm_version}} (build {{dsm_build}}).

=== DEVICE IDENTITY ===
{{identity_json}}

=== DRIVE INVENTORY AND HEALTH ===
{{drives_json}}

=== RAID CONFIGURATION ===
{{raid_json}}

=== VOLUME STATUS ===
{{volumes_json}}

=== NETWORK STATUS ===
{{network_json}}

=== PERFORMANCE SNAPSHOT ===
{{performance_json}}

=== SYSTEM INTEGRITY ===
{{system_integrity_json}}

=== CRITICAL LOG ENTRIES (last 500 lines, last 12 months) ===
{{critical_logs_text}}

=== HEALTH SCORE (pre-computed) ===
{{health_score_json}}

Analyze the above data. Provide findings for ALL categories (hardware, raid, network, performance, security, system). Include at least one OK finding per healthy category. Trace any symptoms to root causes. Cite exact log entries as evidence.
```

### 9.4 Level 2 Prompt Extension

Level 2 scans analyze multiple debug files from the same project. The user prompt includes data from all selected files with timestamps, and an additional instruction block:

```
=== MULTI-FILE ANALYSIS MODE ===
You are analyzing {{file_count}} debug files from the same NAS ({{model}}, serial {{serial}}).
Files are ordered by capture date:

{{#each files}}
--- FILE {{index}}: captured {{capture_date}} ---
{{parsed_data_json}}
{{/each}}

ADDITIONAL INSTRUCTIONS FOR MULTI-FILE ANALYSIS:
1. Compare the state across files. Identify what has CHANGED between snapshots.
2. For each finding, note whether it is NEW (appeared in a later file), PERSISTENT (present in all files), RESOLVED (was present earlier but not in the latest), or WORSENING (getting worse over time).
3. Track drive health trends: are timeout counts increasing? Are new drives showing errors?
4. Track performance trends: is I/O wait increasing? Is memory pressure growing?
5. If a problem was identified in an earlier scan and is now resolved, state that clearly.
```

### 9.5 Groq API Call

```php
class AiService {
    public function analyze(array $promptData, string $model, int $maxInputTokens, int $maxOutputTokens): AiResponse
    {
        $systemPrompt = $this->loadTemplate($promptData['level'] . '_system.txt');
        $userPrompt = $this->renderTemplate(
            $promptData['level'] . '_user.txt',
            $promptData
        );

        // Truncate if exceeds max input tokens (rough estimate: 1 token ≈ 4 chars)
        $maxChars = $maxInputTokens * 4;
        if (strlen($userPrompt) > $maxChars) {
            $userPrompt = $this->truncatePrompt($userPrompt, $maxChars);
        }

        $startTime = hrtime(true);

        $response = $this->httpClient->post('https://api.groq.com/openai/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'max_tokens' => $maxOutputTokens,
                'temperature' => 0.1,  // Low temperature for factual analysis
                'response_format' => ['type' => 'json_object'],
            ],
            'timeout' => 90,  // HTTP timeout
        ]);

        $durationMs = (hrtime(true) - $startTime) / 1_000_000;

        $body = json_decode($response->getBody(), true);
        $content = $body['choices'][0]['message']['content'];
        $usage = $body['usage'];

        return new AiResponse(
            content: json_decode($content, true),
            rawResponse: $content,
            inputTokens: $usage['prompt_tokens'],
            outputTokens: $usage['completion_tokens'],
            durationMs: (int) $durationMs,
            model: $model,
        );
    }
}
```

### 9.6 Response Validation

After receiving the AI response, validate that:

1. Response is valid JSON.
2. Contains `findings` array.
3. Each finding has all required fields (`category`, `severity`, `title`, `description`, `recommendation`, `evidence`).
4. Each evidence entry has a `log_line` field.
5. Each `severity` is one of: `critical`, `warning`, `info`, `ok`.
6. Each `category` is one of: `hardware`, `raid`, `network`, `performance`, `security`, `system`.

If validation fails, log the raw response and mark the scan as `failed` with error code `ai_response_invalid`.

---

## 10. ADMIN MODULE

### 10.1 Admin Dashboard (`/admin`)

Display using ActiveDesign.md KPI card pattern:

**KPI Cards Row:**
- Total Tenants (active/inactive counts)
- Total Scans (today / this week / all time)
- Tokens Distributed vs. Tokens Used
- System Queue (queued / running counts)
- Storage Used (total uploaded file size)

**Charts Row:**
- Scan Activity (line chart, last 30 days, scans per day)
- Token Consumption (bar chart, per tenant, last 30 days)

**Tenant List Table:**
- Columns: Name, Email, Status (active/inactive badge), Projects, Tokens Available, Tokens Used, Scans (L1/L2), Last Login, Actions (edit/deactivate)
- Sortable by any column. Paginated (20 per page).
- Search/filter by name, email, status.

### 10.2 Tenant Management (`/admin/tenants`)

**Create Tenant Form:**
- Display Name (required)
- Email (required, unique)
- Initial Password (required, shown once)
- Initial Token Allocation (required, integer >= 0)
- Status (active/inactive toggle)

**Edit Tenant:**
- All fields editable except email.
- Token allocation: add/subtract tokens (show current balance).
- Reset password option (generates new password, shown once).
- Deactivate/Activate toggle.
- Usage summary: tokens available, tokens used, scans L1/L2, projects count, last login.

### 10.3 System Settings (`/admin/settings`)

**AI Configuration:**
- Groq API Key (password field, encrypted at rest)
- Level 1 Model (dropdown — fetched from Groq API `/models` endpoint, or manual entry)
- Level 1 Max Input Tokens (number input)
- Level 1 Max Output Tokens (number input)
- Level 2 Model (same dropdown)
- Level 2 Max Input Tokens
- Level 2 Max Output Tokens
- Max Concurrent Scans (number input, default 2)

**Retention:**
- Global Retention Days (number input, default 90)

**Upload:**
- Max Upload Size MB (number input, default 200)

### 10.4 Error Log (`/admin/errors`)

Table of `extraction_errors` joined with `debug_files`:
- Columns: Date, Tenant, File, Error Type, Error Message, Severity, Resolved, Actions
- Filter by: resolved/unresolved, error_type, tenant
- Action: Mark as resolved, Download original debug file

---

## 11. TENANT MODULE

### 11.1 Tenant Dashboard (`/dashboard`)

**KPI Cards:**
- Tokens Available
- Tokens Used
- Total Projects
- Scans This Month (L1 / L2)

**Recent Activity List:**
- Last 10 scan results (project name, scan level, health score badge, date)
- Click to view full report.

**Quick Actions:**
- "New Project" button
- "Upload Debug File" button (requires project selection)

### 11.2 Projects List (`/projects`)

Table view per ActiveDesign.md list pattern:
- Columns: Project Name, Device Model, Serial Number, Drive Bays, Last Scan, Health Status Badge, Actions
- Click project name → project detail page.
- "More Info" expandable row: shows NAS specs (RAM, CPU, DSM version, uptime).
- Actions: Edit, Delete (confirmation modal), Scan L1 button, Scan L2 button.

### 11.3 Project Detail (`/project/{id}`)

**Header Section:**
- Project name, NAS model, serial number, DSM version.
- NAS specs card (RAM, CPU, drive bays, uptime from latest file).

**Debug Files Table:**
- Columns: Filename, Upload Date, File Size, DSM Version, Extraction Status, Actions
- Actions: Download, Delete (confirmation), Use for Scan

**Scan History Table:**
- Columns: Date, Level, Status (badge), Health Score (color-coded badge), Duration, Findings Count, Actions
- Actions: View Report
- Status badges: queued (blue), running (orange pulse), completed (green), failed (red)

**Action Buttons:**
- Upload New Debug File
- Run Level 1 Scan (select 1 file)
- Run Level 2 Scan (select 2+ files)

### 11.4 File Upload Flow

1. User clicks "Upload Debug File" on project page.
2. File picker (accept=`.dat`). Client validates extension and size.
3. Upload with progress bar (Fetch API + SSE).
4. Server validates ZIP, extracts hardware data.
5. **Serial number check:**
   - If serial extracted and matches current project → proceed.
   - If serial extracted and matches a DIFFERENT project → modal: "This debug file is from [Model] (SN: [serial]) which is linked to project [name]. Add to that project instead?"
   - If serial extracted and matches NO project → modal: "This is a new device [Model] (SN: [serial]). Create a new project or add to this project?"
   - If serial not found → modal: "Could not identify the device serial number. Add to this project or select another?"
6. On success → show extracted hardware summary, update project metadata.

### 11.5 Scan Report Page (`/scan/{id}/report`)

**Report Header:**
- NAS Model, Serial, DSM Version.
- Scan Level badge, Date, Duration breakdown (queue wait, extraction, AI analysis, total).
- Overall Health Score (large badge: CRITICAL red, WARNING amber, NORMAL green).
- AI Model used, prompt version, token usage.

**Executive Summary:**
- 2-3 sentence AI-generated summary.

**Findings by Category:**
- Tabs or accordion sections: Hardware, RAID, Network, Performance, Security, System.
- Each finding is a card:
  - Severity badge (color-coded)
  - Title (bold)
  - Description
  - Root Cause (if applicable)
  - Recommendation
  - Evidence (expandable section with log lines in monospace, source file noted)
  - Confidence score (small badge)
- Sorted by severity (Critical first, then Warning, Info, OK).

**For Level 2 multi-file reports:**
- Add "Change Status" badge per finding: NEW, PERSISTENT, RESOLVED, WORSENING.
- Timeline view showing health score progression across files.

---

## 12. AUDIT & PERFORMANCE LOGGING

### 12.1 Audit Events

Every significant action writes to `audit_log`:

```php
class AuditService {
    public function log(
        audit_action $action,
        ?UUID $userId,
        ?UUID $tenantId,
        ?string $resourceType,
        ?UUID $resourceId,
        ?array $details,
        ?int $durationMs
    ): void {
        // Insert into audit_log with IP from $_SERVER['REMOTE_ADDR']
        // and User-Agent from $_SERVER['HTTP_USER_AGENT']
    }
}
```

### 12.2 Performance Timing

Every scan records granular timing in both `scan_jobs` columns and `performance_log`:

| Metric | Unit | What It Measures |
|---|---|---|
| `queue_wait_ms` | ms | Time from queued to started |
| `extraction_duration_ms` | ms | ZIP extraction + parsing time |
| `prompt_assembly_duration_ms` | ms | Template rendering + token counting |
| `ai_request_duration_ms` | ms | Groq API round-trip |
| `post_processing_duration_ms` | ms | Response validation + DB writes |
| `total_duration_ms` | ms | End-to-end scan time |
| `ai_input_tokens_used` | tokens | Prompt tokens consumed |
| `ai_output_tokens_used` | tokens | Response tokens consumed |

Admin dashboard shows aggregated performance metrics:
- Average scan duration by level (last 7 days).
- Average AI latency by model.
- P95 queue wait time.
- Token consumption trends.

---

## 13. API ENDPOINT REFERENCE

### 13.1 Authentication

| Method | Path | Description | Auth |
|---|---|---|---|
| GET | `/login` | Login form | None |
| POST | `/auth/login` | Process login | None |
| POST | `/auth/logout` | Destroy session | Session |
| GET | `/auth/change-password` | Password change form | Session |
| POST | `/auth/change-password` | Process password change | Session |

### 13.2 Admin Routes (AdminMiddleware)

| Method | Path | Description |
|---|---|---|
| GET | `/admin` | Admin dashboard |
| GET | `/admin/tenants` | Tenant list |
| GET | `/admin/tenants/create` | Create tenant form |
| POST | `/admin/tenants` | Create tenant |
| GET | `/admin/tenants/{id}/edit` | Edit tenant form |
| PUT | `/admin/tenants/{id}` | Update tenant |
| DELETE | `/admin/tenants/{id}` | Delete tenant (soft) |
| POST | `/admin/tenants/{id}/tokens` | Add/subtract tokens |
| POST | `/admin/tenants/{id}/reset-password` | Reset tenant password |
| GET | `/admin/settings` | System settings form |
| PUT | `/admin/settings` | Update settings |
| GET | `/admin/errors` | Extraction error log |
| POST | `/admin/errors/{id}/resolve` | Mark error resolved |
| GET | `/admin/errors/{id}/download` | Download flagged debug file |

### 13.3 Tenant Routes (TenantMiddleware)

| Method | Path | Description |
|---|---|---|
| GET | `/dashboard` | Tenant dashboard |
| GET | `/projects` | Project list |
| GET | `/projects/create` | Create project form |
| POST | `/projects` | Create project |
| GET | `/project/{id}` | Project detail |
| PUT | `/project/{id}` | Update project |
| DELETE | `/project/{id}` | Delete project (confirmation required) |
| POST | `/project/{id}/upload` | Upload debug file |
| DELETE | `/file/{id}` | Delete debug file |
| GET | `/file/{id}/download` | Download debug file |
| POST | `/scan/start` | Queue a scan (JSON: project_id, level, debug_file_ids[]) |
| GET | `/scan/{id}/progress` | SSE progress stream |
| GET | `/scan/{id}/report` | View scan report |
| POST | `/scan/{id}/cancel` | Cancel queued scan |

### 13.4 Internal API (JSON responses, session auth)

| Method | Path | Description |
|---|---|---|
| GET | `/api/project/{id}/serial-check` | Check if serial exists in other projects |
| GET | `/api/queue/status` | Current queue status (for SSE client) |
| GET | `/api/models` | Available Groq models (admin only) |

---

## 14. UI PAGE MAP & ROUTING

### 14.1 Sidebar Navigation

**Admin Sidebar:**
```
[Logo] AI DebugScan
─────────────────
📊 Dashboard          → /admin
👥 Tenants            → /admin/tenants
⚙️ Settings           → /admin/settings
⚠️ Error Log          → /admin/errors
─────────────────
🚪 Logout
```

**Tenant Sidebar:**
```
[Logo] AI DebugScan
─────────────────
📊 Dashboard          → /dashboard
📁 Projects           → /projects
─────────────────
🎟️ Tokens: 15         (read-only display)
─────────────────
🚪 Logout
```

### 14.2 Design Implementation Notes

Follow ActiveDesign.md V4.0 specifications exactly for:
- Color palette (Primary #0066FF, status colors, neutrals)
- Typography scale (system font stack, heading hierarchy)
- Spacing system (4/8/12/16/20/24/32/40px tokens)
- Shadow system (Elevation 0-4)
- Component states (8-state matrix for all interactive elements)
- Empty states (never render blank containers)
- Skeleton loading (shimmer placeholders matching content dimensions)
- Toast notifications (success auto-dismiss 4-5s, error persistent with retry)
- Confirmation modals (destructive actions only, name the specific item)
- Table patterns (sortable headers, pagination, hover rows)
- Form patterns (inline validation, error placement below field)

---

## 15. SECURITY SPECIFICATIONS

### 15.1 Input Validation

- All user input validated server-side (never trust client validation alone).
- File uploads: validate magic bytes, ZIP integrity, path traversal, symlinks, size.
- All database queries use parameterized PDO prepared statements (never string interpolation).
- All template output auto-escaped by Twig (`{{ var }}` is escaped by default).
- JSON API responses: `Content-Type: application/json`, no HTML rendering.

### 15.2 AI Prompt Sanitization

Before including any log file content in AI prompts:
- Strip any content that looks like injection attempts (e.g., "Ignore previous instructions").
- Limit individual log entries to 500 characters.
- Escape or remove control characters.
- Truncate to configured token limits.

### 15.3 File Access Controls

- Uploaded files stored OUTSIDE the webroot (`storage/uploads/` is not under `public/`).
- File downloads served through PHP with tenant ownership verification (never direct file URLs).
- Extracted temporary files cleaned up immediately after parsing.

### 15.4 HTTP Security Headers

Set via nginx:

```nginx
add_header X-Content-Type-Options nosniff;
add_header X-Frame-Options DENY;
add_header X-XSS-Protection "1; mode=block";
add_header Referrer-Policy strict-origin-when-cross-origin;
add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'";
```

### 15.5 Rate Limiting

| Endpoint | Limit | Window | Action on exceed |
|---|---|---|---|
| POST `/auth/login` | 5 requests | 15 minutes | Block IP for 15 min |
| POST `/project/{id}/upload` | 10 uploads | 1 hour | 429 response |
| POST `/scan/start` | 20 scans | 1 hour | 429 response |
| All other POST | 60 requests | 1 minute | 429 response |

Implement via `RateLimitMiddleware` using a `rate_limits` table or in-memory counting per session.

---

## 16. ERROR HANDLING STRATEGY

### 16.1 Upload Errors

| Error | User Message | Action |
|---|---|---|
| File too large | "File exceeds {max}MB limit" | Reject before upload completes (nginx `client_max_body_size`) |
| Not a valid ZIP | "The file is not a valid Synology debug file" | Reject, log |
| Path traversal detected | "The file contains unsafe paths and cannot be processed" | Reject, log, flag |
| Missing dsm/ structure | "This doesn't appear to be a Synology debug file" | Reject |
| Extraction timeout | "File processing timed out. The file may be too large or corrupt." | Mark failed, flag for admin review |

### 16.2 Scan Errors

| Error | User Message | Action |
|---|---|---|
| Insufficient tokens | "You need {n} tokens for this scan. Current balance: {balance}" | Block scan initiation |
| AI API timeout | "The AI service is currently slow. Your scan has been re-queued." | Retry up to 3x, then fail |
| AI API error (4xx) | "The AI service returned an error. This scan has been flagged for review." | Log full error, mark failed, no token charge |
| AI response invalid | "The AI response could not be processed. This scan has been flagged." | Log raw response, mark failed, no token charge |
| All retries exhausted | "Scan failed after multiple attempts. No tokens were charged." | Refund tokens, log, mark failed |

### 16.3 Token Charging Rules

- Tokens deducted AFTER successful scan completion only.
- If scan fails at any stage → no charge.
- If scan is cancelled while queued → no charge.
- If scan is cancelled while running → no charge (partial results discarded).
- Token transactions logged in `audit_log` with before/after balance in `details` JSONB.

---

## 17. RETENTION & CLEANUP

### 17.1 Retention Worker (`workers/retention_worker.php`)

Runs daily at 2:00 AM via cron:

```
0 2 * * * php /var/www/debugscan/workers/retention_worker.php >> /var/log/debugscan/retention.log 2>&1
```

Logic:
1. Query `debug_files WHERE expires_at < NOW()`.
2. For each expired file:
   - Delete the physical file from `storage/uploads/`.
   - Delete the `debug_files` row (cascades to `scan_jobs` and `scan_findings`).
   - Log audit event: `file_deleted` with `details: { reason: 'retention_expired' }`.
3. Clean up orphaned extracted data in `storage/extracted/`.
4. Clean up sessions older than 24 hours.
5. Vacuum analyze affected tables.

### 17.2 Retention Calculation

On file upload:
```php
$retentionDays = SystemSettings::get('retention_days');
$expiresAt = new DateTime('+' . $retentionDays . ' days');
```

---

## 18. DEPLOYMENT PROCEDURE

### 18.1 Server Setup (Debian 12)

```bash
# 1. System update
apt update && apt upgrade -y

# 2. Install packages
apt install -y nginx php8.2-fpm php8.2-pgsql php8.2-mbstring php8.2-xml \
    php8.2-zip php8.2-curl php8.2-gd php8.2-intl php8.2-bcmath \
    postgresql-15 certbot python3-certbot-nginx unzip curl git

# 3. Install Composer
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# 4. PostgreSQL setup
sudo -u postgres createuser debugscan_app
sudo -u postgres createdb debugscan -O debugscan_app
sudo -u postgres psql -c "ALTER USER debugscan_app WITH PASSWORD 'STRONG_RANDOM_PASSWORD';"

# 5. Create application directory
mkdir -p /var/www/debugscan
mkdir -p /var/www/debugscan/storage/{uploads,extracted,reports,logs}
chown -R www-data:www-data /var/www/debugscan/storage

# 6. Deploy application files
# (upload from local/upload directory to /var/www/debugscan/)

# 7. Install PHP dependencies
cd /var/www/debugscan && composer install --no-dev --optimize-autoloader

# 8. Run migrations
php /var/www/debugscan/migrations/migrate.php

# 9. Configure .env
cp .env.example .env
# Edit .env with database credentials, Groq API key, app URL

# 10. Set permissions
chown -R www-data:www-data /var/www/debugscan
chmod -R 750 /var/www/debugscan
chmod -R 770 /var/www/debugscan/storage
```

### 18.2 nginx Configuration

```nginx
server {
    listen 80;
    server_name debugscan.yourdomain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name debugscan.yourdomain.com;

    ssl_certificate /etc/letsencrypt/live/debugscan.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/debugscan.yourdomain.com/privkey.pem;

    root /var/www/debugscan/public;
    index index.php;

    client_max_body_size 200M;
    client_body_timeout 300s;

    # Security headers
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";
    add_header Referrer-Policy strict-origin-when-cross-origin;

    # Static assets with cache
    location /assets/ {
        expires 7d;
        add_header Cache-Control "public, immutable";
    }

    # Block access to sensitive directories
    location ~ /\. { deny all; }
    location ~ ^/(src|config|migrations|workers|storage|templates|vendor)/ { deny all; }

    # PHP routing
    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }
}
```

### 18.3 PHP-FPM Configuration

```ini
; /etc/php/8.2/fpm/pool.d/debugscan.conf
[debugscan]
user = www-data
group = www-data
listen = /var/run/php/php8.2-fpm.sock

pm = dynamic
pm.max_children = 15
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500

php_admin_value[upload_max_filesize] = 200M
php_admin_value[post_max_size] = 210M
php_admin_value[max_execution_time] = 300
php_admin_value[memory_limit] = 512M
php_admin_value[max_input_time] = 300
```

### 18.4 Cron Jobs

```cron
# Scan queue worker — runs every 10 seconds
* * * * * www-data php /var/www/debugscan/workers/scan_worker.php >> /var/www/debugscan/storage/logs/worker.log 2>&1
* * * * * www-data sleep 10 && php /var/www/debugscan/workers/scan_worker.php >> /var/www/debugscan/storage/logs/worker.log 2>&1
* * * * * www-data sleep 20 && php /var/www/debugscan/workers/scan_worker.php >> /var/www/debugscan/storage/logs/worker.log 2>&1
* * * * * www-data sleep 30 && php /var/www/debugscan/workers/scan_worker.php >> /var/www/debugscan/storage/logs/worker.log 2>&1
* * * * * www-data sleep 40 && php /var/www/debugscan/workers/scan_worker.php >> /var/www/debugscan/storage/logs/worker.log 2>&1
* * * * * www-data sleep 50 && php /var/www/debugscan/workers/scan_worker.php >> /var/www/debugscan/storage/logs/worker.log 2>&1

# Stale scan recovery — every 5 minutes
*/5 * * * * www-data php /var/www/debugscan/workers/stale_recovery.php >> /var/www/debugscan/storage/logs/recovery.log 2>&1

# Retention cleanup — daily at 2 AM
0 2 * * * www-data php /var/www/debugscan/workers/retention_worker.php >> /var/www/debugscan/storage/logs/retention.log 2>&1

# Session cleanup — hourly
0 * * * * www-data php /var/www/debugscan/workers/session_cleanup.php >> /var/www/debugscan/storage/logs/sessions.log 2>&1
```

---

## 19. CONFIGURATION REFERENCE

### 19.1 Environment Variables (`.env`)

```env
# Application
APP_NAME="AI DebugScan"
APP_ENV=production          # production | development
APP_URL=https://debugscan.yourdomain.com
APP_DEBUG=false

# Database
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=debugscan
DB_USER=debugscan_app
DB_PASSWORD=STRONG_RANDOM_PASSWORD

# AI Provider
GROQ_API_KEY=gsk_xxxxxxxxxxxxxxxxxxxx

# Security
SESSION_LIFETIME=28800       # 8 hours in seconds
CSRF_TOKEN_LENGTH=64
BCRYPT_COST=12

# File Processing
UPLOAD_MAX_SIZE_MB=200
EXTRACTION_TIMEOUT_SECONDS=60
LOG_TAIL_LINES=500
LOG_MAX_AGE_MONTHS=12

# Queue
MAX_CONCURRENT_SCANS=2
SCAN_TIMEOUT_LEVEL1=120
SCAN_TIMEOUT_LEVEL2=300
WORKER_POLL_INTERVAL=10

# Paths
STORAGE_PATH=/var/www/debugscan/storage
LOG_PATH=/var/www/debugscan/storage/logs
```

---

## 20. DEVELOPMENT WORKFLOW & keydata.md

### 20.1 keydata.md Purpose

The `guide/keydata.md` file is a living project state document. It MUST be read by the AI at the start of every development prompt, alongside `guide/ActiveDesign.md`.

### 20.2 keydata.md Structure

```markdown
# AI DebugScan v3 — Key Data File

## Current Version
- App Version: 0.1.0
- Schema Version: 001
- Last Updated: 2026-04-07

## Changelog
| Date | Version | Change |
|---|---|---|
| 2026-04-07 | 0.1.0 | Initial specification created |

## Implementation Status
| Component | Status | Notes |
|---|---|---|
| Database schema | Not started | |
| Authentication | Not started | |
| Admin module | Not started | |
| Tenant module | Not started | |
| File processing | Not started | |
| AI integration | Not started | |
| Queue system | Not started | |
| UI/CSS | Not started | |

## Active Decisions
- Queue: PostgreSQL-based (no Redis for MVP)
- Sessions: PostgreSQL-based
- Framework: Slim 4 + Twig + Alpine.js
- AI: Groq only (no fallback provider for MVP)

## Known Issues
(none yet)

## File Versioning
All files should use comment headers with version and last-modified date.
```

### 20.3 Development Rules

1. **Read first:** Every development session MUST begin by reading `keydata.md`, `ActiveDesign.md`, and this specification.
2. **Update keydata.md:** After completing any significant work, update the implementation status, changelog, and any new decisions or issues.
3. **Comment all code:** Every file must have a header comment with purpose, version, and last-modified date. Functions must have docblocks.
4. **Test as you go:** After implementing each component, verify it works with sample data before moving to the next.
5. **Follow ActiveDesign.md exactly:** UI components must match the design system specifications (colors, spacing, typography, states).
6. **Follow Hardwarev2.md exactly:** Parser logic must implement the detection rules, edge cases, and fallbacks documented in the extraction reference.

---

## APPENDIX A: CRITICAL PARSER RULES FROM HARDWAREV2.MD

These rules are non-negotiable and must be implemented exactly:

1. **Drive naming: DETECT, never assume.** Read `proc/partitions` and check for `sata` vs `sd` entries. Never infer from DSM version.
2. **Disk log format: DETECT by file presence.** Check for `disk_log.csv` first, then `disk_log.html`, then `none`. If both exist, merge by timestamp.
3. **CSV delimiter: `,\t` (comma+tab).** Normalize to plain comma before parsing.
4. **Partition layout: DETECT from mdstat.** Check which partition number feeds md2 (`p3` vs `p5`).
5. **Expansion drives: major device number 128.** Never detect by device name pattern.
6. **[8/5] notation: NOT degradation.** Empty bay slots are normal. Only flag if an active member is missing.
7. **PHYRdyChg errors on expansion plugin: NORMAL.** Only flag if persistent without a preceding plugin event.
8. **Fresh install sparsity: NORMAL.** Short uptime + few logs = freshly installed, not corrupted.
9. **MemAvailable > MemFree.** Use `MemAvailable` (DSM 7.x) for actual free memory; `buff/cache` is reclaimable.
10. **IO load vs CPU load.** Synology's `top.result` separates these — high load with low CPU% = I/O bottleneck.

---

## APPENDIX B: SAMPLE DEBUG FILE INVENTORY

Available for testing in `/sample/`:

| File | Model | DSM | RAM | Bays | Notable Features |
|---|---|---|---|---|---|
| debug.dat | RS818+-j | 6.2.4 | 8GB | 4+4 exp | Expansion unit (RX418), sda naming, HTML disk log |
| debug.dat.dat | RS3617rpxs | 7.2.1 | 16GB | 12+exp | Large rack unit, sda naming on DSM 7.x, CSV disk log, APIPA on eth0 |
| debug1.dat | DS215+-j | 6.0.3 | 1GB | 2 | Oldest DSM, structurally sparse, no disk_log, no load_info |
| debug2.dat | DS916+-j | 7.0.1 | 8GB | 4 | Early DSM 7.x, HTML disk log (not yet CSV) |
| debug4.dat | RS2212+ | 6.2.2 | 1GB | 10 | Legacy 5-partition layout (sda5), low RAM, no load_info |
| debug_2070*.dat | RS3617rpxs | 7.3.1 | 8GB | 12+exp | sda naming on latest DSM 7.x |
| debug_2320*.dat | DS1821+ | 7.3.2 | 8GB | 8 (5 filled) | sata naming, I/O crisis (37% wa), failing sata4 |
| debug_2550R*XJT0.dat | RS1221rp+ | 7.3 | 16GB | 5 | sata naming, healthy baseline |
| debug_2550R*ZQ0T.dat | RS1221rp+ | 7.3 | 16GB | 5 | Same unit, different time (Level 2 test pair) |

---

*End of specification. This document, combined with `ActiveDesign.md` and `Hardwarev2.md`, provides complete information needed to build the AI DebugScan v3 application.*
