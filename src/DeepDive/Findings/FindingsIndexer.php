<?php

declare(strict_types=1);

namespace App\DeepDive\Findings;

use PDO;
use Psr\Log\LoggerInterface;

/**
 * FindingsIndexer: Track and correlate discovered issues across bundles
 *
 * PURPOSE:
 * Maintains registry of discovered issues and tracks their recurrence,
 * enabling the system to recognize patterns even after original debug
 * bundles are deleted.
 *
 * KEY FEATURES:
 * - Detect recurring issues: "has this problem happened before?"
 * - Calculate occurrence frequency: "how often does this fail?"
 * - Track resolution status: "was the fix effective?"
 * - Enable playbook building: "what worked last time for this issue?"
 */
final class FindingsIndexer
{
    private PDO $pdo;
    private ?LoggerInterface $logger;

    public function __construct(PDO $pdo, ?LoggerInterface $logger = null)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    /**
     * Index a new finding or update if already exists
     *
     * @param string $tenant_id Tenant identifier for row-level security
     * @param string $nas_id NAS identifier
     * @param string $issue_type Type of issue (network, memory, raid, thermal, etc.)
     * @param string $severity Severity level (info, warning, alarm)
     * @param string $description Human-readable description
     * @param string $affected_component Component affected (eth0, md0, disk1, etc.)
     * @param ?string $root_cause Root cause if identified
     *
     * @return int Finding ID (new or existing match)
     */
    public function indexFinding(
        string $tenant_id,
        string $nas_id,
        string $issue_type,
        string $severity,
        string $description,
        string $affected_component,
        ?string $root_cause = null
    ): int {
        // Try to find matching recurring issue
        $recurring = $this->findRecurringIssue(
            $tenant_id,
            $nas_id,
            $issue_type,
            $affected_component
        );

        if ($recurring) {
            // Update existing finding with new occurrence
            $this->updateFindingOccurrence(
                $recurring['finding_id'],
                $severity,
                $root_cause
            );

            return $recurring['finding_id'];
        }

        // Create new finding
        $stmt = $this->pdo->prepare("
            INSERT INTO nas_findings_index
            (tenant_id, nas_id, issue_type, severity, description, affected_component,
             root_cause, first_detected, last_detected, resolution_status, occurrence_count)
            VALUES (:tenant_id, :nas_id, :issue_type, :severity, :description, :affected_component,
                    :root_cause, NOW(), NOW(), 'open', 1)
            RETURNING finding_id
        ");

        $stmt->execute([
            'tenant_id' => $tenant_id,
            'nas_id' => $nas_id,
            'issue_type' => $issue_type,
            'severity' => $severity,
            'description' => $description,
            'affected_component' => $affected_component,
            'root_cause' => $root_cause,
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $finding_id = (int)$row['finding_id'];

        $this->log('info', "Indexed new finding #{$finding_id}: {$issue_type} on {$affected_component}");

        return $finding_id;
    }

    /**
     * Find matching recurring issue
     *
     * Searches for the same issue type on the same component,
     * allowing system to recognize recurring problems.
     *
     * @param string $tenant_id Tenant identifier for row-level security
     * @param string $nas_id NAS identifier
     * @param string $issue_type Type of issue
     * @param string $affected_component Component affected
     *
     * @return ?array Finding data if match found, null otherwise
     */
    public function findRecurringIssue(
        string $tenant_id,
        string $nas_id,
        string $issue_type,
        string $affected_component
    ): ?array {
        $stmt = $this->pdo->prepare("
            SELECT finding_id, severity, issue_type, affected_component,
                   first_detected, last_detected, occurrence_count, resolution_status
            FROM nas_findings_index
            WHERE tenant_id = :tenant_id
              AND nas_id = :nas_id
              AND issue_type = :issue_type
              AND affected_component = :affected_component
              AND resolution_status IN ('open', 'monitoring')
            ORDER BY last_detected DESC
            LIMIT 1
        ");

        $stmt->execute([
            'tenant_id' => $tenant_id,
            'nas_id' => $nas_id,
            'issue_type' => $issue_type,
            'affected_component' => $affected_component,
        ]);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'finding_id' => (int)$row['finding_id'],
                'severity' => $row['severity'],
                'issue_type' => $row['issue_type'],
                'affected_component' => $row['affected_component'],
                'first_detected' => $row['first_detected'],
                'last_detected' => $row['last_detected'],
                'occurrence_count' => (int)$row['occurrence_count'],
                'resolution_status' => $row['resolution_status'],
            ];
        }

        return null;
    }

    /**
     * Update finding occurrence count and last detection
     *
     * @param int $finding_id Finding ID to update
     * @param string $severity New severity level
     * @param ?string $root_cause Updated root cause
     */
    private function updateFindingOccurrence(
        int $finding_id,
        string $severity,
        ?string $root_cause = null
    ): void {
        $stmt = $this->pdo->prepare("
            UPDATE nas_findings_index
            SET last_detected = NOW(),
                occurrence_count = occurrence_count + 1,
                severity = GREATEST(severity, :severity)
        ");

        // If root cause provided, update it
        if ($root_cause) {
            $stmt = $this->pdo->prepare("
                UPDATE nas_findings_index
                SET last_detected = NOW(),
                    occurrence_count = occurrence_count + 1,
                    severity = GREATEST(severity, :severity),
                    root_cause = :root_cause
                WHERE finding_id = :finding_id
            ");

            $stmt->execute([
                'finding_id' => $finding_id,
                'severity' => $severity,
                'root_cause' => $root_cause,
            ]);
        } else {
            $stmt->execute([
                'finding_id' => $finding_id,
                'severity' => $severity,
            ]);
        }

        $this->log('debug', "Updated occurrence for finding #{$finding_id}");
    }

    /**
     * Resolve a finding (issue fixed or no longer relevant)
     *
     * @param string $tenant_id Tenant identifier for row-level security
     * @param int $finding_id Finding ID
     * @param string $resolution_notes Optional notes about resolution
     */
    public function resolveFinding(string $tenant_id, int $finding_id, ?string $resolution_notes = null): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE nas_findings_index
            SET resolution_status = 'resolved',
                resolved_at = NOW()
            WHERE tenant_id = :tenant_id AND finding_id = :finding_id
        ");

        $stmt->execute(['tenant_id' => $tenant_id, 'finding_id' => $finding_id]);

        $this->log('info', "Marked finding #{$finding_id} as resolved");
    }

    /**
     * Get all open findings for a NAS
     *
     * @param string $tenant_id Tenant identifier for row-level security
     * @param string $nas_id NAS identifier
     * @param ?string $status Filter by status (open, monitoring, resolved)
     *
     * @return array List of findings
     */
    public function getFindings(string $tenant_id, string $nas_id, ?string $status = null): array
    {
        $query = "
            SELECT finding_id, issue_type, severity, description, affected_component,
                   root_cause, first_detected, last_detected, occurrence_count, resolution_status
            FROM nas_findings_index
            WHERE tenant_id = :tenant_id AND nas_id = :nas_id
        ";

        $params = ['tenant_id' => $tenant_id, 'nas_id' => $nas_id];

        if ($status) {
            $query .= " AND resolution_status = :status";
            $params['status'] = $status;
        }

        $query .= " ORDER BY last_detected DESC";

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);

        $findings = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $findings[] = [
                'finding_id' => (int)$row['finding_id'],
                'issue_type' => $row['issue_type'],
                'severity' => $row['severity'],
                'description' => $row['description'],
                'affected_component' => $row['affected_component'],
                'root_cause' => $row['root_cause'],
                'first_detected' => $row['first_detected'],
                'last_detected' => $row['last_detected'],
                'occurrence_count' => (int)$row['occurrence_count'],
                'resolution_status' => $row['resolution_status'],
            ];
        }

        return $findings;
    }

    /**
     * Get specific finding by ID
     *
     * @param string $tenant_id Tenant identifier for row-level security
     * @param int $finding_id Finding ID
     *
     * @return ?array Finding data or null
     */
    public function getFinding(string $tenant_id, int $finding_id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT finding_id, nas_id, issue_type, severity, description, affected_component,
                   root_cause, first_detected, last_detected, occurrence_count, resolution_status
            FROM nas_findings_index
            WHERE tenant_id = :tenant_id AND finding_id = :finding_id
        ");

        $stmt->execute(['tenant_id' => $tenant_id, 'finding_id' => $finding_id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'finding_id' => (int)$row['finding_id'],
            'nas_id' => $row['nas_id'],
            'issue_type' => $row['issue_type'],
            'severity' => $row['severity'],
            'description' => $row['description'],
            'affected_component' => $row['affected_component'],
            'root_cause' => $row['root_cause'],
            'first_detected' => $row['first_detected'],
            'last_detected' => $row['last_detected'],
            'occurrence_count' => (int)$row['occurrence_count'],
            'resolution_status' => $row['resolution_status'],
        ];
    }

    /**
     * Get recurring issues (occurred more than once)
     *
     * Useful for identifying systemic problems that happen repeatedly.
     *
     * @param string $tenant_id Tenant identifier for row-level security
     * @param string $nas_id NAS identifier
     * @param int $min_occurrences Minimum occurrences to consider recurring
     *
     * @return array Recurring findings
     */
    public function getRecurringIssues(string $tenant_id, string $nas_id, int $min_occurrences = 2): array
    {
        $stmt = $this->pdo->prepare("
            SELECT finding_id, issue_type, severity, description, affected_component,
                   first_detected, last_detected, occurrence_count
            FROM nas_findings_index
            WHERE tenant_id = :tenant_id
              AND nas_id = :nas_id
              AND occurrence_count >= :min_occurrences
              AND resolution_status IN ('open', 'monitoring')
            ORDER BY occurrence_count DESC, last_detected DESC
        ");

        $stmt->execute([
            'tenant_id' => $tenant_id,
            'nas_id' => $nas_id,
            'min_occurrences' => $min_occurrences,
        ]);

        $findings = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $findings[] = [
                'finding_id' => (int)$row['finding_id'],
                'issue_type' => $row['issue_type'],
                'severity' => $row['severity'],
                'description' => $row['description'],
                'affected_component' => $row['affected_component'],
                'first_detected' => $row['first_detected'],
                'last_detected' => $row['last_detected'],
                'occurrence_count' => (int)$row['occurrence_count'],
            ];
        }

        return $findings;
    }

    /**
     * Log a message
     */
    private function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->log($level, '[findings-indexer] ' . $message);
        }
    }
}
