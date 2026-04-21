<?php

declare(strict_types=1);

namespace App\DeepDive\Parsers;

use App\DeepDive\Rules\Sources\FileLogSource;
use App\DeepDive\Rules\Sources\PdoSqliteSource;
use App\DeepDive\Rules\Sources\SourceRegistry;

/**
 * Walks an extracted Synology debug bundle and registers every known
 * logical source into the SourceRegistry so rules can address them by
 * stable name regardless of DSM version quirks or path layout changes.
 *
 * Conventions:
 *   - Rotated log files ("messages", "messages.1", "messages.2", ...) are
 *     collected under a single logical name, ordered oldest → newest so
 *     records appear in chronological order.
 *   - Gzip-compressed rotations (messages.2.gz) are expected to have been
 *     decompressed by DecompressStep; we still try to auto-skip .gz files
 *     here for safety.
 *   - Fact snapshots (/proc/mdstat, df, top) are surfaced via synthetic
 *     streams in SnapshotParsers.
 *
 * The locator is intentionally defensive: missing files are ignored rather
 * than fatal — most bundles only carry a subset of DSM subsystems.
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
                    tsExtractor: fn(string $line): ?string => $this->tsp->parse($line),
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
