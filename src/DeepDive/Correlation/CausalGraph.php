<?php

declare(strict_types=1);

namespace App\DeepDive\Correlation;

/**
 * Static knowledge graph of "rule X is typically caused by rule Y".
 * Intentionally hand-curated — this is the one place where engineering
 * judgement about Synology DSM failure modes lives in code. The
 * correlator uses it to pick root causes within an entity cluster.
 *
 * Edges are directed: CAUSE → EFFECT. If rule B is caused by rule A, then
 * A is closer to the root. When multiple rules appear in an entity
 * cluster, the correlator walks the graph to find the most upstream one
 * and designates it root.
 *
 * Adding an edge is safe — an unknown rule id just means "no relationship
 * known", and the correlator falls back to timestamp + severity ordering.
 */
final class CausalGraph
{
    /** @var array<string,list<string>> cause → [effect, ...] */
    private const EDGES = [
        // Storage: SMART degradation → RAID failure → filesystem consequences
        'storage.smart_test_failed'         => ['storage.smart_reallocated_sectors', 'storage.smart_pending_sectors'],
        'storage.smart_pending_sectors'     => ['storage.smart_reallocated_sectors', 'storage.raid_kicked_disk'],
        'storage.smart_reallocated_sectors' => ['storage.raid_kicked_disk'],
        'storage.raid_kicked_disk'          => ['storage.raid_degraded'],
        'storage.raid_degraded'             => ['storage.btrfs_errors'],
        'storage.btrfs_errors'              => ['storage.btrfs_transaction_abort'],
        'storage.btrfs_transaction_abort'   => ['storage.readonly_remount'],

        // Network: physical flap → bond reacts
        'network.link_flapping' => ['network.bond_failover'],

        // Memory: pressure → OOM
        'memory.high_swap_pressure' => ['memory.oom_killed_process'],
    ];

    /** @return list<string> direct effects caused by $cause */
    public static function effectsOf(string $cause): array
    {
        return self::EDGES[$cause] ?? [];
    }

    /** Returns true if $maybeCause → ... → $effect exists in the graph. */
    public static function reaches(string $maybeCause, string $effect, int $maxDepth = 6): bool
    {
        if ($maybeCause === $effect) return false;
        $stack = [[$maybeCause, 0]];
        $seen  = [];
        while ($stack !== []) {
            [$node, $depth] = array_pop($stack);
            if ($depth >= $maxDepth) continue;
            if (isset($seen[$node])) continue;
            $seen[$node] = true;
            foreach (self::effectsOf($node) as $eff) {
                if ($eff === $effect) return true;
                $stack[] = [$eff, $depth + 1];
            }
        }
        return false;
    }

    /**
     * Rank a list of rule ids by causal depth (how many effects they have
     * in the graph). Most upstream first. Ties broken by id for determinism.
     *
     * @param list<string> $ruleIds
     * @return list<string>
     */
    public static function rankByUpstreamness(array $ruleIds): array
    {
        $scored = [];
        foreach ($ruleIds as $id) {
            $scored[$id] = self::descendantCount($id);
        }
        uksort($scored, static function(string $a, string $b) use ($scored): int {
            $d = $scored[$b] <=> $scored[$a];      // more descendants → higher (more upstream)
            return $d !== 0 ? $d : strcmp($a, $b);
        });
        return array_keys($scored);
    }

    private static function descendantCount(string $node, int $maxDepth = 6): int
    {
        $stack = [[$node, 0]]; $seen=[]; $n=0;
        while ($stack !== []) {
            [$cur, $depth] = array_pop($stack);
            if (isset($seen[$cur]) || $depth >= $maxDepth) continue;
            $seen[$cur] = true;
            foreach (self::effectsOf($cur) as $e) { $n++; $stack[] = [$e, $depth + 1]; }
        }
        return $n;
    }
}
