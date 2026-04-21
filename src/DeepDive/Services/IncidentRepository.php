<?php

declare(strict_types=1);

namespace App\DeepDive\Services;

use App\DeepDive\Correlation\Incident;
use App\DeepDive\Rules\FindingRecord;
use PDO;
use Ramsey\Uuid\Uuid;

/**
 * Persists incidents and findings for a DeepDive job. Both tables share
 * tenant_id + deepdive_job_id — RLS policies installed in migration 017
 * keep tenants isolated at the row level.
 *
 * All writes go through a single transaction per persist() call so a
 * mid-way failure (unlikely — schema is tight) leaves the report tables
 * in a consistent state with the job status.
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
