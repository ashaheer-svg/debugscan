-- AI DebugScan v3 - RLS Policies
-- ============================================================
-- ENABLE RLS
-- ============================================================
ALTER TABLE projects ENABLE ROW LEVEL SECURITY;
ALTER TABLE debug_files ENABLE ROW LEVEL SECURITY;
ALTER TABLE scan_jobs ENABLE ROW LEVEL SECURITY;
ALTER TABLE scan_findings ENABLE ROW LEVEL SECURITY;

-- ============================================================
-- POLITIES
-- ============================================================

-- Projects: Admin sees all, Tenant sees only own
CREATE POLICY tenant_isolation_projects ON projects
    USING (
        current_setting('app.current_user_role', true) = 'admin'
        OR tenant_id = (current_setting('app.current_tenant_id', true)::uuid)
    );

-- Debug Files: Admin sees all, Tenant sees only own
CREATE POLICY tenant_isolation_debug_files ON debug_files
    USING (
        current_setting('app.current_user_role', true) = 'admin'
        OR tenant_id = (current_setting('app.current_tenant_id', true)::uuid)
    );

-- Scan Jobs: Admin sees all, Tenant sees only own
CREATE POLICY tenant_isolation_scan_jobs ON scan_jobs
    USING (
        current_setting('app.current_user_role', true) = 'admin'
        OR tenant_id = (current_setting('app.current_tenant_id', true)::uuid)
    );

-- Scan Findings: Admin sees all, Tenant sees only own
CREATE POLICY tenant_isolation_scan_findings ON scan_findings
    USING (
        current_setting('app.current_user_role', true) = 'admin'
        OR tenant_id = (current_setting('app.current_tenant_id', true)::uuid)
    );
