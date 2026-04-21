<?php

declare(strict_types=1);

namespace App\DeepDive\Rules;

/**
 * Loads every *.yaml / *.yml rule from a directory (recursively) into an
 * addressable catalogue. Duplicate ids are fatal — that's almost always an
 * authoring mistake, and silently preferring one over the other would make
 * rule evaluation nondeterministic.
 *
 * The catalogue's `version()` is a content hash — two catalogues with
 * identical rule files produce the same string, which we persist on every
 * deepdive_jobs row so a report can be reproduced later.
 */
final class RuleCatalogue
{
    /** @var array<string,Rule> */
    private array $rules = [];

    /** @var array<string,string> id => source file path */
    private array $origins = [];

    private string $version = '';

    public function __construct(private readonly RuleLoader $loader) {}

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
