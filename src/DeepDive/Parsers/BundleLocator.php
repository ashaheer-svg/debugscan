<?php

declare(strict_types=1);

namespace App\DeepDive\Parsers;

use App\DeepDive\Rules\Sources\FileLogSource;
use App\DeepDive\Rules\Sources\PdoSqliteSource;
use App\DeepDive\Rules\Sources\SourceRegistry;

/**
 * BundleLocator: Intelligent registry of all data sources in extracted bundle
 *
 * PURPOSE:
 * Walks extracted Synology debug bundle directory tree and inventories all
 * available data sources (logs, SQLite databases, /proc snapshots). Registers
 * sources in a SourceRegistry keyed by stable logical names so rule matchers
 * can reference sources without knowing DSM version specifics or directory
 * layout variations.
 *
 * PROBLEM SOLVED:
 * Synology bundles have inconsistent layouts:
 * - DSM 6 vs 7 use different log paths
 * - Bundles may be nested (dbg_info/{serial}/{timestamp}/var/log/...)
 * - Rotated logs have version suffixes (.1, .2, .3 or .gz variants)
 * - Not all subsystems present in every bundle
 * BundleLocator abstracts these details: rules reference "messages" without
 * caring where it actually lives or how many rotations exist.
 *
 * LOCATOR FUNCTIONS:
 * 1. Detect all possible filesystem roots (any dir with "var" or "proc")
 * 2. For each log type in LOG_GLOBS, find all matches across all roots
 * 3. Collect rotations under single logical name (e.g. "messages.1", "messages.2" → "messages")
 * 4. Sort rotations chronologically (oldest first) so regex matchers see records in order
 * 5. Register FileLogSource and PdoSqliteSource objects in central registry
 * 6. Return populated SourceRegistry for rule evaluation
 *
 * LOG SOURCE CONSOLIDATION:
 * Messages from "messages", "messages.1", "messages.2" are merged into single
 * logical "messages" source. Rotations are reordered newest→oldest then reversed
 * to chronological order (oldest first). This allows rules to match across log
 * rotations seamlessly: "find all disk failures regardless of rotation boundary"
 *
 * SQLITE DATABASES:
 * Located via glob patterns for forensic databases:
 * - scemd.db: Event logs (fan, thermal, power events)
 * - synocrond: Scheduled task logs
 * - smart.db: SMART health and error counters
 * Each database registered separately, accessible by logical name.
 *
 * SNAPSHOT PARSING:
 * /proc-like files (mdstat, uptime, cpuinfo) are NOT directly registered.
 * Instead, SnapshotParsers creates synthetic FileLogSource objects that
 * present fact snapshots as "log records" so matchers work uniformly.
 *
 * DEFENSIVE DESIGN:
 * Missing files don't cause failure - they're simply not registered.
 * This handles incomplete bundles (missing auth.log, missing SMART db, etc.)
 * gracefully. Rule matchers handle empty sources (0 matches) without error.
 *
 * @package App\DeepDive\Parsers
 */
final class BundleLocator
{
    /**
     * Known log sources with path globs. Globs are resolved against
     * $root + '/var/log/' first, then against $root itself.
     *
     * @var array<string,list<string>>
     */
    private const LOG_GLOBS = [
        'messages' => ['var/log/messages*', 'messages*'],
        'kern.log' => ['var/log/kern.log*', 'kern.log*'],
        'scemd.log'=> ['var/log/scemd.log*', 'var/log/synolog/scemd.log*', 'scemd.log*'],
        'auth.log' => ['var/log/auth.log*', 'auth.log*'],
        'dmesg'    => ['var/log/dmesg*', 'dmesg*'],
        'synoscgi' => ['var/log/synoscgi.log*', 'synoscgi.log*'],
    ];

    private const SQLITE_GLOBS = [
        'scemd.db'       => ['var/log/scemd/*.db', 'var/log/synolog/scemd*.db'],
        'synocrond'      => ['var/lib/synocrond*.sqlite', 'var/lib/syno*/synocrond*.sqlite'],
        'smart.db'       => ['var/lib/smart*.db', 'var/log/smart*.db'],
    ];

    public function __construct(private readonly TimestampParser $tsp) {}

    public function locate(string $extractedRoot): SourceRegistry
    {
        $reg  = new SourceRegistry();
        $root = rtrim($extractedRoot, '/\\');

        // Synology bundles are usually nested under one or more wrapper dirs
        // (e.g. dbg_info/<serial>/<timestamp>/var/log/...). Detect every
        // directory in the extracted tree that could be a DSM filesystem
        // root (contains a "var" or "proc" child) and run the globs against
        // each. Dedupe file hits via the registry.
        $roots = $this->detectFsRoots($root);

        foreach (self::LOG_GLOBS as $name => $globs) {
            $paths = [];
            foreach ($roots as $r) {
                foreach ($this->resolveGlobs($r, $globs) as $p) $paths[$p] = true;
            }
            $paths = array_keys($paths);
            if ($paths !== []) {
                usort($paths, static function (string $a, string $b): int {
                    return self::rotationIndex($b) <=> self::rotationIndex($a);
                });
                $reg->registerLog(new FileLogSource(
                    name: $name,
                    paths: $paths,
                    tsp: $this->tsp,
                ));
            }
        }

        foreach (self::SQLITE_GLOBS as $name => $globs) {
            foreach ($roots as $r) {
                $hits = $this->resolveGlobs($r, $globs);
                if ($hits !== []) {
                    $reg->registerSqlite(new PdoSqliteSource($name, $hits[0]));
                    continue 2; // first hit wins across roots too
                }
            }
        }

        // Snapshot-style sources (mdstat, df, top, vmstat, btrfs-check).
        foreach ($roots as $r) {
            SnapshotParsers::register($r, $reg);
        }

        return $reg;
    }

    /**
     * Return every directory under $root (plus $root itself) that looks like
     * a DSM filesystem root — i.e. contains a child named "var" or "proc".
     * Depth is capped to keep us out of rabbit-holes in weird bundles.
     *
     * @return list<string>
     */
    private function detectFsRoots(string $root, int $maxDepth = 5): array
    {
        $candidates = [];
        $check = function (string $dir) use (&$candidates): void {
            if (is_dir($dir . '/var') || is_dir($dir . '/proc')) {
                $candidates[$dir] = true;
            }
        };
        $check($root);

        // BFS so shallow matches come first (they're more likely the canonical root).
        $queue = [[$root, 0]];
        while ($queue !== []) {
            [$dir, $depth] = array_shift($queue);
            if ($depth >= $maxDepth) continue;
            $handle = @opendir($dir);
            if (!$handle) continue;
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') continue;
                $sub = $dir . '/' . $entry;
                if (!is_dir($sub) || is_link($sub)) continue;
                $check($sub);
                $queue[] = [$sub, $depth + 1];
            }
            closedir($handle);
        }

        // Always keep $root as a fallback even if nothing matched, so the
        // registry at least gets SnapshotParsers a shot at the top level.
        $out = array_keys($candidates);
        if ($out === []) $out = [$root];
        return $out;
    }

    /**
     * Resolve a list of relative globs against the bundle root and return
     * absolute paths, .gz files filtered out, ordered oldest → newest
     * (so that "messages.3" comes before "messages.1" which comes before
     * "messages").
     *
     * @param list<string> $globs
     * @return list<string>
     */
    private function resolveGlobs(string $root, array $globs): array
    {
        $hits = [];
        foreach ($globs as $g) {
            foreach (glob($root . '/' . $g) ?: [] as $p) {
                if (str_ends_with($p, '.gz') || str_ends_with($p, '.xz') || str_ends_with($p, '.bz2')) continue;
                $hits[$p] = true;
            }
        }
        $paths = array_keys($hits);

        // Order: base file LAST so rotations appear chronologically.
        usort($paths, static function (string $a, string $b): int {
            $ra = self::rotationIndex($a);
            $rb = self::rotationIndex($b);
            return $rb <=> $ra; // higher index = older = earlier
        });
        return $paths;
    }

    private static function rotationIndex(string $path): int
    {
        if (preg_match('/\.(\d+)$/', $path, $m)) return (int)$m[1];
        return 0; // no suffix = newest
    }
}
