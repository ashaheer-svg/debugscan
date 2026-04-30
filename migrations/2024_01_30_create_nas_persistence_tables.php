<?php

declare(strict_types=1);

/**
 * Migration: Create NAS persistence tables for metrics, findings, and analysis
 *
 * Creates 5 tables to maintain data continuity when debug bundles are deleted:
 * - nas_health_timeseries: Metrics from each bundle analysis
 * - nas_findings_index: Discovered issues and their recurrence
 * - nas_analysis_archive: AI diagnoses and remediation steps
 * - nas_baseline_profiles: Per-NAS normal operating ranges
 * - nas_log_archive: Compressed historical logs
 */

return function (\PDO $pdo): void {
    // === TABLE 1: Health Metrics Timeseries ===
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nas_health_timeseries (
            id BIGSERIAL PRIMARY KEY,
            tenant_id VARCHAR(255) NOT NULL,
            nas_id VARCHAR(255) NOT NULL,
            bundle_date TIMESTAMP NOT NULL,
            metric_name VARCHAR(255) NOT NULL,
            metric_value NUMERIC(12, 4),
            unit VARCHAR(50),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            UNIQUE(tenant_id, nas_id, bundle_date, metric_name),
            INDEX idx_tenant_nas_date (tenant_id, nas_id, bundle_date),
            INDEX idx_tenant_metric (tenant_id, nas_id, metric_name, bundle_date)
        ) TABLESPACE pg_default;

        COMMENT ON TABLE nas_health_timeseries IS
        'Time-series metrics extracted from each bundle: network errors, memory %,
         RAID progress, thermal readings, service restarts, etc.';
    ");

    // === TABLE 2: Findings Index (Issue Registry) ===
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nas_findings_index (
            finding_id BIGSERIAL PRIMARY KEY,
            tenant_id VARCHAR(255) NOT NULL,
            nas_id VARCHAR(255) NOT NULL,
            issue_type VARCHAR(100) NOT NULL,
            severity VARCHAR(20) NOT NULL,

            -- Timestamps
            first_detected TIMESTAMP NOT NULL,
            last_detected TIMESTAMP,
            resolved_at TIMESTAMP,

            -- Description and context
            description TEXT NOT NULL,
            affected_component VARCHAR(100),
            root_cause VARCHAR(255),

            -- Status tracking
            resolution_status VARCHAR(20) DEFAULT 'open',
            occurrence_count INT DEFAULT 1,

            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            INDEX idx_tenant_nas_status (tenant_id, nas_id, resolution_status),
            INDEX idx_tenant_nas_date (tenant_id, nas_id, last_detected),
            INDEX idx_tenant_issue_type (tenant_id, nas_id, issue_type)
        ) TABLESPACE pg_default;

        COMMENT ON TABLE nas_findings_index IS
        'Registry of discovered issues. Each finding tracks first/last occurrence,
         helping identify recurring problems even after bundles are deleted.';
    ");

    // === TABLE 3: AI Analysis Archive ===
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nas_analysis_archive (
            analysis_id BIGSERIAL PRIMARY KEY,
            finding_id BIGINT REFERENCES nas_findings_index(finding_id) ON DELETE CASCADE,

            analysis_date TIMESTAMP NOT NULL,
            ai_model VARCHAR(100),

            -- AI output
            ai_diagnosis TEXT,
            remediation_steps TEXT,
            technical_explanation TEXT,
            confidence_score NUMERIC(3, 2),

            -- Outcome tracking
            outcome_status VARCHAR(20),
            fix_applied_date TIMESTAMP,
            fix_effective BOOLEAN,
            improvement_percent NUMERIC(5, 2),

            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            INDEX idx_finding (finding_id),
            INDEX idx_date (analysis_date)
        ) TABLESPACE pg_default;

        COMMENT ON TABLE nas_analysis_archive IS
        'Stores AI-generated analyses, diagnoses, and remediation steps.
         Enables playbook building: \"what worked last time for this issue?\"';
    ");

    // === TABLE 4: Baseline Profiles (Per-NAS Normal Ranges) ===
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nas_baseline_profiles (
            profile_id BIGSERIAL PRIMARY KEY,
            tenant_id VARCHAR(255) NOT NULL,
            nas_id VARCHAR(255) NOT NULL,
            profile_date TIMESTAMP NOT NULL,

            metric_name VARCHAR(255) NOT NULL,

            -- Normal operating ranges
            normal_min NUMERIC(12, 4),
            normal_max NUMERIC(12, 4),

            -- Alert threshold
            alarm_threshold NUMERIC(12, 4),
            warning_threshold NUMERIC(12, 4),

            -- Unit for display
            unit VARCHAR(50),

            -- Notes
            notes TEXT,

            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            UNIQUE(tenant_id, nas_id, metric_name),
            INDEX idx_tenant_nas_metric (tenant_id, nas_id, metric_name)
        ) TABLESPACE pg_default;

        COMMENT ON TABLE nas_baseline_profiles IS
        'Per-NAS baseline normal ranges. Enables personalized anomaly detection:
         70% memory is normal for system A but alarm for system B.';
    ");

    // === TABLE 5: Log Archive (Compressed Historical Logs) ===
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nas_log_archive (
            log_id BIGSERIAL PRIMARY KEY,
            nas_id VARCHAR(255) NOT NULL,
            log_type VARCHAR(100) NOT NULL,
            log_date TIMESTAMP NOT NULL,

            -- Compressed log content
            compressed_content BYTEA,
            original_size INT,
            compressed_size INT,
            compression_ratio NUMERIC(3, 2),

            -- Metadata
            entries_count INT,
            error_entries_count INT,

            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

            INDEX idx_nas_type_date (nas_id, log_type, log_date)
        ) TABLESPACE pg_default;

        COMMENT ON TABLE nas_log_archive IS
        'Compressed historical logs (syslog, RAID, thermal, service).
         Preserves important context even when debug bundle is deleted.';
    ");

    // === Create Indexes for Performance ===
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_timeseries_nas_metric_date
        ON nas_health_timeseries(nas_id, metric_name, bundle_date DESC);
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_findings_nas_last_detected
        ON nas_findings_index(nas_id, last_detected DESC);
    ");

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_analysis_finding_model
        ON nas_analysis_archive(finding_id, ai_model);
    ");

    // Success message
    echo "✓ Created 5 persistence tables for NAS continuity analysis\n";
    echo "  - nas_health_timeseries (metrics)\n";
    echo "  - nas_findings_index (issue registry)\n";
    echo "  - nas_analysis_archive (AI diagnoses)\n";
    echo "  - nas_baseline_profiles (personalized thresholds)\n";
    echo "  - nas_log_archive (compressed logs)\n";
};
