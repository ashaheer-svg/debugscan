<?php

declare(strict_types=1);

namespace App\DeepDive\Support;

use App\DeepDive\Rules\RuleCatalogue;
use App\DeepDive\Rules\RuleLoader;

/**
 * Engine: DeepDive configuration and initialization
 *
 * PURPOSE:
 * Centralized configuration for DeepDive analysis engine
 * Environment-based overrides for testing and deployment
 * Rule loading and versioning
 *
 * CONFIGURATION:
 * - DEEPDIVE_WITHIN_DAYS: Age filter for findings (default: 365 days)
 * - DEEPDIVE_RULES_DIR: Path to rule definitions (default: config/deepdive/rules)
 *
 * VERSIONING:
 * - Engine VERSION: DeepDive engine version
 * - RULE_CATALOGUE_VERSION_FALLBACK: Legacy fallback version string
 * - Rule catalogue version tracked on every job (reproducibility)
 *
 * @package App\DeepDive\Support
 */
final class Engine
{
    public const VERSION = 'deepdive-0.1';

    /**
     * Pre-2a fallback. The live catalogue version is a content hash computed
     * by RuleCatalogue::version() and recorded on every deepdive_jobs row.
     */
    public const RULE_CATALOGUE_VERSION_FALLBACK = 'catalogue-0.1';

    /**
     * Findings older than this many days are excluded from every report.
     * Override with env DEEPDIVE_WITHIN_DAYS. Set to 0 to disable filtering.
     * Individual rules may override via signature.within_days.
     */
    public static function withinDays(): int
    {
        $env = getenv('DEEPDIVE_WITHIN_DAYS');
        if (is_string($env) && $env !== '' && ctype_digit($env)) return (int)$env;
        return 365;
    }

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
