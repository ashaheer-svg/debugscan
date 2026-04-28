<?php

declare(strict_types=1);

namespace App\DeepDive\Services;

use App\DeepDive\Correlation\Incident;
use App\DeepDive\Rules\FindingRecord;
use PDO;
use Ramsey\Uuid\Uuid;

/**
 * IncidentRepository: Data access layer for incidents and findings
 *
 * PURPOSE:
 * Persists correlated incidents and their constituent findings to database
 * after pipeline evaluation. Handles incident/finding linking, transaction
 * management, and row-level security (RLS) enforcement via tenant_id.
 * Transforms in-memory Incident objects into database records.
 *
 * DATA MODEL:
 * Two tables with referential integrity:
 * 1. deepdive_incidents: Root cause clusters
 *    - id: UUID primary key
 *    - deepdive_job_id: Job this incident belongs to
 *    - tenant_id: Row-level security filter (which tenant owns)
 *    - priority: Urgency (high/medium/low)
 *    - actionability: User ability to fix (user_fixable/upgrade/vendor/info)
 *    - title: Short summary ("RAID Array Degraded")
 *    - summary: Longer explanation
 *    - narrative: AI-generated analysis (populated by Narrator)
 *    - recommended_actions: User-facing remediation steps
 *    - root_cause_finding_id: FK to findings(id), which finding is root
 *
 * 2. deepdive_findings: Individual rule matches
 *    - id: UUID primary key
 *    - deepdive_job_id: Job this finding belongs to
 *    - incident_id: FK to incidents (which cluster this belongs to)
 *    - tenant_id: Row-level security filter
 *    - rule_id: Rule that fired ("storage.raid_degraded")
 *    - rule_version: Rule version (for reproducibility)
 *    - severity: Impact (info/warn/high/critical)
 *    - confidence: Match quality (0-100%)
 *    - actionability: User action (same enum as incident)
 *    - title: Rule-derived title
 *    - entities: {device: "md2", ...} for Correlator grouping
 *    - citations: [{file, line, excerpt, ...}] evidence from logs
 *    - cause_chain_refs: [finding_id, ...] causal precedents
 *
 * INCIDENT→FINDINGS RELATIONSHIP:
 * One incident contains many findings (typically 1-5):
 * - Findings are individual rule matches (facts)
 * - Incident is correlation result (hypothesis of root cause)
 * - rootCause field points to finding designated root cause
 * - cause_chain_refs allow tracing fault propagation:
 *   "storage.smart_pending_sectors → storage.raid_kicked_disk → storage.raid_degraded"
 *
 * PERSISTENCE:
 * persist($jobId, $tenantId, $incidents) → count of rows written
 * Called after Correlator completes, before Narrator starts.
 * Atomic transaction: all incidents+findings succeed or all fail.
 * No partial writes: if one finding insert fails, rollback everything.
 *
 * TRANSACTION STRATEGY:
 * 1. Begin transaction
 * 2. For each incident:
 *    a. Generate UUIDs for all findings
 *    b. Track rootCause finding's UUID
 *    c. Insert incident row (with root_cause_finding_id)
 *    d. For each finding, insert row (with incident_id)
 * 3. Commit transaction (all-or-nothing)
 * 4. Return total rows written (for logging)
 *
 * RLS SECURITY:
 * Both tables have tenant_id column. PostgreSQL RLS policies ensure:
 * - Only rows with session_user's tenant_id are visible
 * - Session-level variable set by middleware
 * - Application cannot bypass RLS (enforced at database level)
 * - persist() must provide jobId and tenantId (come from request context)
 *
 * TIMING:
 * persist() called by CorrelateStep after unions are complete.
 * Does NOT populate narrative or recommended_actions (null values).
 * Narrator step runs next, queries findings, generates narrative.
 * RenderStep then fetches incidents+findings for HTML report generation.
 *
 * ERROR HANDLING:
 * Throws exception on SQL error (transaction rollback):
 * - FK violation: jobId or tenantId mismatches
 * - Unique constraint: rule_id/severity combination already in table
 * - Serialization conflict: concurrent updates (rare)
 * Pipeline catches exception, marks job failed, stops processing.
 *
 * IDEMPOTENCY:
 * NOT idempotent. Calling persist twice creates duplicate rows.
 * Pipeline ensures single call per job (CorrelateStep → persist → Narrator).
 * If re-running job, must delete old incident/finding rows first.
 *
 * QUERY (After Persistence):
 * ReportRenderer calls forIncident(incidentId) to fetch findings for report.
 * IncidentRepository (or separate QueryRepository) provides read access.
 * Reads can be cached (findings immutable after persist).
 *
 * @package App\DeepDive\Services
 */
final class IncidentRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Persist all incidents (plus their findings) for a job. Returns the
     * number of rows written across both tables.
     *
     * Finding-to-incident linkage is preserved: every finding ends up with
     * an incident_id, and the incident's root_cause_finding_id points to
     * whichever finding the correlator designated root.
     *
     * @param list<Incident> $incidents
     */
    public function persist(string $jobId, string $tenantId, array $incidents): int
    {
        if ($incidents === []) return 0;

        $this->pdo->beginTransaction();
        try {
            $insIncident = $this->pdo->prepare("
                INSERT INTO deepdive_incidents
                (id, deepdive_job_id, tenant_id, priority, actionability,
                 title, summary, narrative, recommended_actions, root_cause_finding_id)
                VALUES (:id, :job, :tid, :pri, :act, :title, :summary, :narr, :acts, :root)
            ");
            $insFinding = $this->pdo->prepare("
                INSERT INTO deepdive_findings
                (id, deepdive_job_id, incident_id, tenant_id, rule_id, rule_version,
                 severity, confidence, actionability, title, entities, citations, cause_chain_refs)
                VALUES (:id, :job, :inc, :tid, :rid, :rv, :sev, :conf, :act, :title,
                        :ent, :cit, :chain)
            ");

            $written = 0;
            foreach ($incidents as $inc) {
                // Findings first so we know root_cause_finding_id before the incident row goes in.
                $findingIds      = [];
                $rootFindingId   = null;
                foreach ($inc->findings as $f) {
                    $fid = Uuid::uuid4()->toString();
                    $findingIds[spl_object_id($f)] = $fid;
                    if ($inc->rootCause === $f) $rootFindingId = $fid;
                }

                $insIncident->execute([
                    'id'      => $inc->id,
                    'job'     => $jobId,
                    'tid'     => $tenantId,
                    'pri'     => $inc->priority,
                    'act'     => $inc->actionability,
                    'title'   => $inc->title,
                    'summary' => $inc->summary,
                    'narr'    => null, // narrator fills this in Sprint 3b
                    'acts'    => null, // recommended_actions populated by narrator
                    'root'    => $rootFindingId,
                ]);
                $written++;

                foreach ($inc->findings as $f) {
                    $fid = $findingIds[spl_object_id($f)];
                    $insFinding->execute([
                        'id'    => $fid,
                        'job'   => $jobId,
                        'inc'   => $inc->id,
                        'tid'   => $tenantId,
                        'rid'   => $f->ruleId,
                        'rv'    => $f->ruleVersion,
                        'sev'   => $f->severity,
                        'conf'  => $f->confidence,
                        'act'   => $f->actionability,
                        'title' => $f->title,
                        'ent'   => json_encode($f->entities,  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'cit'   => json_encode($f->citations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'chain' => json_encode($inc->causeChain, JSON_UNESCAPED_SLASHES),
                    ]);
                    $written++;
                }
            }
            $this->pdo->commit();
            return $written;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Load all incidents + findings for a job (tenant-scoped via RLS or
     * explicit filter).
     *
     * @return list<array<string,mixed>>
     */
    public function loadForJob(string $jobId, string $tenantId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT i.*
            FROM deepdive_incidents i
            WHERE i.deepdive_job_id = :job AND i.tenant_id = :tid
            ORDER BY i.priority ASC, i.title ASC
        ");
        $stmt->execute(['job' => $jobId, 'tid' => $tenantId]);
        $incidents = $stmt->fetchAll();

        $fstmt = $this->pdo->prepare("
            SELECT * FROM deepdive_findings
            WHERE incident_id = :id AND tenant_id = :tid
            ORDER BY severity DESC, title ASC
        ");
        foreach ($incidents as &$i) {
            $fstmt->execute(['id' => $i['id'], 'tid' => $tenantId]);
            $i['findings'] = $fstmt->fetchAll();
        }
        return $incidents;
    }
}
