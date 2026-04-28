<?php

declare(strict_types=1);

namespace App\DeepDive\Correlation;

use App\DeepDive\Rules\FindingRecord;
use Ramsey\Uuid\Uuid;

/**
 * Correlator: Group findings into coherent incidents via correlation analysis
 *
 * PURPOSE:
 * Transforms flat list of rule findings into grouped incidents
 * Uses shared entities and causal relationships to detect related findings
 * Produces prioritized incidents with root causes and remediation context
 *
 * CORRELATION ALGORITHM:
 * Two-phase approach combining entity clustering and causal analysis:
 *
 * Phase 1: Entity-based clustering (Union-Find)
 * - Links findings mentioning same entities (disk, array, interface, mount)
 * - Entity keys checked in specificity order for best matching
 * - All findings with shared entity value merged into cluster
 *
 * Phase 2: Causal edge merging
 * - Checks if findings have direct causal relationship (CausalGraph)
 * - Merges causally-related findings if they share ANY entity value
 * - Prevents unrelated findings from being swept together
 *
 * Phase 3: Incident building
 * - For each cluster: builds Incident object
 * - Derives priority from findings and actionability
 * - Identifies root cause in causal chain
 * - Sorts by priority for report presentation
 *
 * SHARED ENTITIES:
 * Findings linked by common objects:
 * - disk/device: Physical drive references
 * - array: RAID array identifier
 * - interface: Network interface
 * - iface: Alternative interface name
 * - mount: Filesystem mount point
 * - process: Process name/PID
 * Checked in priority order for specificity
 *
 * SINGLETON INCIDENTS:
 * Findings with no shared entities become singleton incidents
 * Philosophy: Better to over-report critical than silently drop
 *
 * INCIDENT PRIORITY:
 * Automatic calculation from findings:
 * - P1: Critical + any actionability
 * - P2: High + user_fixable
 * - P3: High + other, or warning
 * - P4: Informational
 *
 * INCIDENT SORTING:
 * By priority (P1→P4), then title (deterministic ordering)
 *
 * @package App\DeepDive\Correlation
 */
final class Correlator
{
    /** Entity keys we use to detect shared-subject findings. Ordered by specificity. */
    private const LINK_KEYS = ['disk', 'device', 'array', 'interface', 'iface', 'mount', 'process'];

    /**
     * @param list<FindingRecord> $findings
     * @return list<Incident>
     */
    public function correlate(array $findings): array
    {
        if ($findings === []) return [];

        // ---- Phase 1: union-find clusters keyed by shared entity value ----
        $n = count($findings);
        $parent = range(0, $n - 1);
        $find = function(int $x) use (&$parent, &$find): int {
            while ($parent[$x] !== $x) { $parent[$x] = $parent[$parent[$x]]; $x = $parent[$x]; }
            return $x;
        };
        $union = function(int $a, int $b) use (&$parent, $find): void {
            $ra = $find($a); $rb = $find($b);
            if ($ra !== $rb) $parent[$ra] = $rb;
        };

        // Bucket finding indices by "key=value" fingerprint for any linkable entity.
        $buckets = [];
        foreach ($findings as $i => $f) {
            foreach (self::LINK_KEYS as $k) {
                if (!isset($f->entities[$k])) continue;
                $v = (string)$f->entities[$k];
                if ($v === '') continue;
                $fp = $k . '=' . $v;
                $buckets[$fp][] = $i;
            }
        }
        foreach ($buckets as $indices) {
            for ($i = 1, $c = count($indices); $i < $c; $i++) {
                $union($indices[0], $indices[$i]);
            }
        }

        // Phase 2: also merge findings that have a direct causal edge even
        // if they don't share an entity (e.g. "unexpected shutdown" + "readonly remount").
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $findings[$i]->ruleId;
                $b = $findings[$j]->ruleId;
                if (CausalGraph::reaches($a, $b) || CausalGraph::reaches($b, $a)) {
                    // Only merge if one of them is already in a shared-entity cluster
                    // with the other — prevents sweeping unrelated issues together.
                    if ($find($i) === $find($j)) continue;
                    if ($this->shareAnyEntityValue($findings[$i], $findings[$j])) {
                        $union($i, $j);
                    }
                }
            }
        }

        // Materialise clusters
        $clusters = [];
        for ($i = 0; $i < $n; $i++) {
            $r = $find($i);
            $clusters[$r][] = $i;
        }

        // ---- Phase 3: build an Incident per cluster ----
        $incidents = [];
        foreach ($clusters as $indices) {
            $group = array_map(static fn(int $i) => $findings[$i], $indices);
            $incidents[] = $this->buildIncident($group);
        }

        // Sort P1 → P4, then by title for determinism
        usort($incidents, static function(Incident $a, Incident $b): int {
            $p = strcmp($a->priority, $b->priority);
            return $p !== 0 ? $p : strcmp($a->title, $b->title);
        });
        return $incidents;
    }

    /** @param list<FindingRecord> $group */
    private function buildIncident(array $group): Incident
    {
        $ruleIds = array_values(array_unique(array_map(static fn(FindingRecord $f) => $f->ruleId, $group)));

        // Upstream-most rule wins the root-cause slot.
        $ranked = CausalGraph::rankByUpstreamness($ruleIds);
        $rootRuleId = $ranked[0] ?? $ruleIds[0];
        $rootFinding = null;
        foreach ($group as $f) { if ($f->ruleId === $rootRuleId) { $rootFinding = $f; break; } }

        // Cause chain: only include rule ids that form a linear path through the graph.
        $chain = $this->deriveChain($rootRuleId, $ruleIds);

        // Shared entities = intersection of all findings' linkable entities
        $shared = $this->intersectEntities($group);

        // Priority & actionability derive from the severest / most-actionable member,
        // but we pin actionability to the root-cause finding since that drives the fix.
        $priority      = $this->derivePriority($group);
        $actionability = $rootFinding?->actionability ?? $group[0]->actionability;

        $title = $rootFinding?->title ?? ($group[0]->title);
        $summary = $this->buildSummary($group, $rootFinding, $shared);

        return new Incident(
            id:             Uuid::uuid4()->toString(),
            priority:       $priority,
            actionability:  $actionability,
            title:          $title,
            summary:        $summary,
            findings:       $group,
            rootCause:      $rootFinding,
            sharedEntities: $shared,
            causeChain:     $chain,
        );
    }

    /**
     * @param list<string> $ruleIds
     * @return list<string>
     */
    private function deriveChain(string $root, array $ruleIds): array
    {
        $chain = [$root];
        $set   = array_flip($ruleIds);
        unset($set[$root]);

        $cur = $root;
        while (true) {
            $next = null;
            foreach (CausalGraph::effectsOf($cur) as $eff) {
                if (isset($set[$eff])) { $next = $eff; break; }
            }
            if ($next === null) break;
            $chain[] = $next;
            unset($set[$next]);
            $cur = $next;
        }
        // Append any unchained rules (co-occurring without causal link) at the end for visibility.
        foreach ($set as $id => $_) $chain[] = (string)$id;
        return $chain;
    }

    /** @param list<FindingRecord> $group */
    private function intersectEntities(array $group): array
    {
        $out = [];
        foreach (self::LINK_KEYS as $k) {
            $values = [];
            foreach ($group as $f) {
                if (isset($f->entities[$k]) && $f->entities[$k] !== '') {
                    $values[] = (string)$f->entities[$k];
                }
            }
            $values = array_values(array_unique($values));
            if (count($values) === 1) $out[$k] = $values[0];
        }
        return $out;
    }

    /** @param list<FindingRecord> $group */
    private function derivePriority(array $group): string
    {
        $maxSev = 'info';
        $order  = ['info' => 0, 'warn' => 1, 'high' => 2, 'critical' => 3];
        $hasUserFixable = false;
        foreach ($group as $f) {
            if (($order[$f->severity] ?? 0) > ($order[$maxSev] ?? 0)) $maxSev = $f->severity;
            if ($f->actionability === 'user_fixable') $hasUserFixable = true;
        }
        return match (true) {
            $maxSev === 'critical'                => 'P1',
            $maxSev === 'high' && $hasUserFixable => 'P2',
            $maxSev === 'high'                    => 'P3',
            $maxSev === 'warn'                    => 'P3',
            default                                => 'P4',
        };
    }

    /** @param list<FindingRecord> $group */
    private function buildSummary(array $group, ?FindingRecord $root, array $shared): string
    {
        $count = count($group);
        $rootTitle = $root?->title ?? $group[0]->title;
        $bits = [];
        foreach ($shared as $k => $v) $bits[] = $k . '=' . $v;
        $entityTag = $bits === [] ? '' : ' (' . implode(', ', $bits) . ')';
        if ($count === 1) {
            return $rootTitle . $entityTag;
        }
        return sprintf('%s%s — and %d related finding(s)', $rootTitle, $entityTag, $count - 1);
    }

    private function shareAnyEntityValue(FindingRecord $a, FindingRecord $b): bool
    {
        foreach (self::LINK_KEYS as $k) {
            if (!isset($a->entities[$k]) || !isset($b->entities[$k])) continue;
            if ((string)$a->entities[$k] !== '' && $a->entities[$k] === $b->entities[$k]) return true;
        }
        return false;
    }
}
