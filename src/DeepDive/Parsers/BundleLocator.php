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

        foreach (self::LOG_GLOBS as $name => $globs) {
            $paths = $this->resolveGlobs($root, $globs);
            if ($paths !== []) {
                $reg->registerLog(new FileLogSource(
                    name: $name,
                    paths: $paths,
                    tsExtractor: fn(string $line): ?string => $this->tsp->parse($line),
                ));
            }
        }

        foreach (self::SQLITE_GLOBS as $name => $globs) {
            foreach ($this->resolveGlobs($root, $globs) as $path) {
                $reg->registerSqlite(new PdoSqliteSource($name, $path));
                break; // first hit only — DSM doesn't rotate sqlites
            }
        }

        // Snapshot-style sources (mdstat, df, top, vmstat, btrfs-check) get
        // turned into synthetic streams by SnapshotParsers.
        SnapshotParsers::register($root, $reg);

        return $reg;
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
