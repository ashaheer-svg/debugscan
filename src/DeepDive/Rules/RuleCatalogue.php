<?php

declare(strict_types=1);

namespace App\DeepDive\Rules;

/**
 * RuleCatalogue: Load and manage analysis rules from YAML files
 *
 * PURPOSE:
 * Loads all rule definitions from a directory tree
 * Provides addressable rule catalogue for evaluation
 * Maintains version hash for report reproducibility
 *
 * RULE LOADING:
 * Recursively scans directory for *.yaml and *.yml files
 * Loads each rule via RuleLoader
 * Enforces unique rule IDs (duplicates are fatal errors)
 * Maintains alphabetical order for deterministic processing
 *
 * DUPLICATE DETECTION:
 * Same ID in multiple files → RuntimeException
 * Philosophy: Authoring mistakes must not silently fail
 * Prevents nondeterministic evaluation
 *
 * VERSIONING:
 * Content hash (SHA256) computed from:
 * - Rule IDs and versions
 * - File MD5 checksums
 * Result: "cat-" + first 12 hex chars
 *
 * REPRODUCIBILITY:
 * Version persisted on every job record
 * Enables later report reproduction with same rules
 * If rules change, version changes (breaks reproducibility)
 *
 * API:
 * - all(): Get all Rule objects
 * - byId(): Fetch single rule
 * - origin(): Get file path of rule
 * - count(): Rule count
 * - version(): Content hash for audit trail
 *
 * @package App\DeepDive\Rules
 */
final class RuleCatalogue
{
    /** @var array<string,Rule> Loaded rules indexed by ID */
    private array $rules = [];

    /** @var array<string,string> Rule ID -> source file path mapping */
    private array $origins = [];

    /** @var string Content hash for version tracking */
    private string $version = '';

    /**
     * Constructor: Initialize catalogue with RuleLoader dependency
     *
     * @param RuleLoader $loader YAML rule file parser
     */
    public function __construct(private readonly RuleLoader $loader) {}

    /**
     * Load all rules from directory tree
     *
     * ALGORITHM:
     * 1. Recursively scan directory for *.yaml / *.yml files
     * 2. Sort files alphabetically (deterministic order)
     * 3. For each file:
     *    - Load rule via RuleLoader
     *    - Check for duplicate ID (fatal)
     *    - Store rule and origin path
     *    - Update hash context
     * 4. Finalize version hash
     *
     * @param string $dir Root directory to scan
     *
     * @throws RuntimeException If directory not found or duplicate rule ID
     */
    public function loadDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            throw new \RuntimeException("Rule directory not found: {$dir}");
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $ext = strtolower($f->getExtension());
            if ($ext !== 'yaml' && $ext !== 'yml') continue;
            $files[] = $f->getPathname();
        }
        sort($files, SORT_STRING);  // deterministic order → deterministic hash

        $hashCtx = hash_init('sha256');
        foreach ($files as $path) {
            $rule = $this->loader->loadFile($path);
            if (isset($this->rules[$rule->id])) {
                throw new \RuntimeException(
                    "Duplicate rule id '{$rule->id}' in {$path} (previously seen in {$this->origins[$rule->id]})"
                );
            }
            $this->rules[$rule->id]   = $rule;
            $this->origins[$rule->id] = $path;
            hash_update($hashCtx, $rule->id . '@' . $rule->version . ':' . md5_file($path));
        }
        $this->version = 'cat-' . substr(hash_final($hashCtx), 0, 12);
    }

    /** @return array<string,Rule> */
    public function all(): array { return $this->rules; }

    public function byId(string $id): ?Rule { return $this->rules[$id] ?? null; }

    public function origin(string $id): ?string { return $this->origins[$id] ?? null; }

    public function count(): int { return count($this->rules); }

    public function version(): string { return $this->version; }
}
