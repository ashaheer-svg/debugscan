-- Migration: DeepDive isolated diagnostic engine schema
-- Date: 2026-04-21
-- Safe: only CREATEs new tables and ADDs one nullable column.
-- Reverse with 017_deepdive_schema.down.sql.
--
-- NOTE: in this schema, "tenants" are rows in the `users` table with role='tenant'.
-- There is no separate `tenants` table. All DeepDive code follows that convention.

BEGIN;

-- ============================================================
-- Feature flag on users (default OFF — nothing renders for existing tenants)
-- Only meaningful when users.role = 'tenant'.
-- ============================================================
ALTER TABLE users ADD COLUMN IF NOT EXISTS deepdive_enabled BOOLEAN NOT NULL DEFAULT FALSE;

-- ============================================================
-- deepdive_jobs
-- ============================================================
CREATE TABLE IF NOT EXISTS deepdive_jobs (
    id                   UUID PRIMARY KEY,
    tenant_id            UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    project_id           UUID NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    debug_file_ids       UUID[] NOT NULL,
    status               TEXT NOT NULL DEFAULT 'queued'
                         CHECK (status IN ('queued','running','completed','failed','cancelled')),
    steps_json           JSONB,
    progress_percent     SMALLINT NOT NULL DEFAULT 0,
    progress_stage       TEXT,
    report_html_path     TEXT,
    report_pdf_path      TEXT,
    report_format        TEXT,
    engine_version       TEXT NOT NULL,
    rule_catalogue_ver   TEXT,
    debug_mode           BOOLEAN NOT NULL DEFAULT FALSE,
    error_message        TEXT,
    queued_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    started_at           TIMESTAMPTZ,
    completed_at         TIMESTAMPTZ,
    worker_id            TEXT,
    lease_expires_at     TIMESTAMPTZ,
    cleanup_status       TEXT NOT NULL DEFAULT 'pending'
                         CHECK (cleanup_status IN ('pending','done','failed','skipped'))
);
CREATE INDEX IF NOT EXISTS idx_deepdive_jobs_tenant_status ON deepdive_jobs(tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_deepdive_jobs_queue         ON deepdive_jobs(status, queued_at);
CREATE INDEX IF NOT EXISTS idx_deepdive_jobs_project       ON deepdive_jobs(project_id);

-- ============================================================
-- deepdive_incidents
-- ============================================================
CREATE TABLE IF NOT EXISTS deepdive_incidents (
    id                     UUID PRIMARY KEY,
    deepdive_job_id        UUID NOT NULL REFERENCES deepdive_jobs(id) ON DELETE CASCADE,
    tenant_id              UUID NOT NULL,
    priority               TEXT NOT NULL CHECK (priority IN ('P1','P2','P3','P4')),
    actionability          TEXT NOT NULL
                           CHECK (actionability IN ('user_fixable','upgrade_recommended','vendor_issue','informational')),
    title                  TEXT NOT NULL,
    summary                TEXT,
    narrative              TEXT,
    recommended_actions    JSONB,
    root_cause_finding_id  UUID,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_deepdive_incidents_job_priority ON deepdive_incidents(deepdive_job_id, priority);
CREATE INDEX IF NOT EXISTS idx_deepdive_incidents_tenant       ON deepdive_incidents(tenant_id);

-- ============================================================
-- deepdive_findings
-- ============================================================
CREATE TABLE IF NOT EXISTS deepdive_findings (
    id                 UUID PRIMARY KEY,
    deepdive_job_id    UUID NOT NULL REFERENCES deepdive_jobs(id) ON DELETE CASCADE,
    incident_id        UUID REFERENCES deepdive_incidents(id) ON DELETE SET NULL,
    tenant_id          UUID NOT NULL,
    rule_id            TEXT NOT NULL,
    rule_version       INT  NOT NULL,
    severity           TEXT NOT NULL CHECK (severity IN ('info','warn','high','critical')),
    confidence         NUMERIC(3,2),
    actionability      TEXT NOT NULL
                       CHECK (actionability IN ('user_fixable','upgrade_recommended','vendor_issue','informational')),
    title              TEXT NOT NULL,
    entities           JSONB,
    citations          JSONB,
    cause_chain_refs   JSONB,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_deepdive_findings_job      ON deepdive_findings(deepdive_job_id);
CREATE INDEX IF NOT EXISTS idx_deepdive_findings_rule     ON deepdive_findings(rule_id);
CREATE INDEX IF NOT EXISTS idx_deepdive_findings_incident ON deepdive_findings(incident_id);

-- ============================================================
-- RLS — mirror existing scan_* pattern
-- ============================================================
ALTER TABLE deepdive_jobs      ENABLE ROW LEVEL SECURITY;
ALTER TABLE deepdive_incidents ENABLE ROW LEVEL SECURITY;
ALTER TABLE deepdive_findings  ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS tenant_isolation_deepdive_jobs      ON deepdive_jobs;
DROP POLICY IF EXISTS tenant_isolation_deepdive_incidents ON deepdive_incidents;
DROP POLICY IF EXISTS tenant_isolation_deepdive_findings  ON deepdive_findings;

CREATE POLICY tenant_isolation_deepdive_jobs ON deepdive_jobs
    USING (
        current_setting('app.current_user_role', true) = 'admin'
        OR tenant_id = (current_setting('app.current_tenant_id', true)::uuid)
    );

CREATE POLICY tenant_isolation_deepdive_incidents ON deepdive_incidents
    USING (
        current_setting('app.current_user_role', true) = 'admin'
        OR tenant_id = (current_setting('app.current_tenant_id', true)::uuid)
    );

CREATE POLICY tenant_isolation_deepdive_findings ON deepdive_findings
    USING (
        current_setting('app.current_user_role', true) = 'admin'
        OR tenant_id = (current_setting('app.current_tenant_id', true)::uuid)
    );

COMMIT;
