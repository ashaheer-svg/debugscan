<?php

declare(strict_types=1);

namespace App\DeepDive\Support;

use App\DeepDive\Rules\RuleCatalogue;
use App\DeepDive\Rules\RuleLoader;

final class Engine
{
    public const VERSION = 'deepdive-0.1';

    /**
     * Pre-2a fallback. The live catalogue version is a content hash computed
     * by RuleCatalogue::version() and recorded on every deepdive_jobs row.
     */
    public const RULE_CATALOGUE_VERSION_FALLBACK = 'catalogue-0.1';

    /** Absolute path to the rules directory. Overridable via env for tests. */
    public static function rulesDir(): string
    {
        $env = getenv('DEEPDIVE_RULES_DIR');
        if (is_string($env) && $env !== '' && is_dir($env)) return $env;
        return realpath(__DIR__ . '/../../../config/deepdive/rules') ?: __DIR__ . '/../../../config/deepdive/rules';
    }

    /** Load the catalogue from disk. Returns null on total failure. */
    public static function loadCatalogue(): ?RuleCatalogue
    {
        $dir = self::rulesDir();
        if (!is_dir($dir)) return null;
        $cat = new RuleCatalogue(new RuleLoader());
        try {
            $cat->loadDirectory($dir);
        } catch (\Throwable $e) {
            error_log("[deepdive] catalogue load failed: " . $e->getMessage());
            return null;
        }
        return $cat;
    }

    public static function catalogueVersion(?RuleCatalogue $cat = null): string
    {
        return $cat?->version() ?: self::RULE_CATALOGUE_VERSION_FALLBACK;
    }
}
