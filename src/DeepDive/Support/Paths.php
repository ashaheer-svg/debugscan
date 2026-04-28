<?php

declare(strict_types=1);

namespace App\DeepDive\Support;

/**
 * Paths: DeepDive file system layout management
 *
 * PURPOSE:
 * Centralized, single-source-of-truth for all file system paths used by
 * DeepDive. Prevents hardcoded path strings scattered throughout codebase.
 * Enables:
 * - Easy relocation (change base dir in one place)
 * - Path validation (all paths go through safe())
 * - Consistency (no typos in subdirectories)
 * - Clear directory structure documentation
 *
 * DIRECTORY STRUCTURE:
 * All DeepDive storage lives under storage/deepdive/:
 * ├── extracted/
 * │   └── {jobId}/          ← Extracted debug bundles per job
 * │       └── {debugFileId}/
 * ├── tmp/
 * │   └── {jobId}/          ← Working files (mPDF cache, temp extractions)
 * ├── reports/
 * │   ├── {jobId}.html      ← Rendered HTML reports
 * │   └── {jobId}.pdf       ← PDF exports
 * ├── logs/
 * │   └── {jobId}.log       ← Job execution logs
 *
 * ISOLATION:
 * Fully separated from existing ScanService storage:
 * - ScanService: storage/extracted/{scanId}, storage/reports/{scanId}
 * - DeepDive: storage/deepdive/extracted/{jobId}, storage/deepdive/reports/{jobId}
 * No collision, independent cleanup, separate quota tracking possible.
 *
 * STATIC METHODS (Job-agnostic):
 * - root(): Application root (computed from __DIR__)
 * - storageRoot(): storage/deepdive base path
 * - reportsDir(): storage/deepdive/reports
 * - uploads(): storage/uploads (for initial debug file staging)
 * - detectorCatalogue(): config/deepdive/detectors (rule YAML location)
 *
 * JOB-SPECIFIC PATHS:
 * - extracted(jobId): storage/deepdive/extracted/{jobId}
 * - tmp(jobId): storage/deepdive/tmp/{jobId}
 * - reportHtml(jobId): storage/deepdive/reports/{jobId}.html
 * - reportPdf(jobId): storage/deepdive/reports/{jobId}.pdf
 * - jobLog(jobId): storage/deepdive/logs/{jobId}.log
 *
 * DIRECTORY CREATION:
 * ensure(dir): Creates directory recursively (mkdir -p equivalent)
 * Throws RuntimeException if creation fails. Used throughout pipeline
 * to ensure parent dirs exist before writing files.
 *
 * PATH VALIDATION:
 * safe(jobId): Sanitizes job IDs to prevent directory traversal attacks
 * - Validates format: [A-Za-z0-9\-]{8,64} (UUID-like string)
 * - Rejects "../../etc/passwd", "..", "~", etc.
 * - Throws InvalidArgumentException on invalid format
 * - All public methods pass jobId through safe() before using
 *
 * SECURITY:
 * Never constructs paths with unsanitized user input. All paths derived
 * from application constants or safe-filtered jobId. Prevents:
 * - Directory traversal (../../)
 * - Symlink escape (if filesystem is configured)
 * - Absolute path injection (/etc/password)
 *
 * USAGE:
 * # Create extracted directory for job
 * $dir = Paths::extracted($jobId);
 * Paths::ensure($dir);
 *
 * # Write report
 * $html = Paths::reportHtml($jobId);
 * file_put_contents($html, $content);
 *
 * # Cleanup job
 * exec("rm -rf " . escapeshellarg(Paths::extracted($jobId)));
 * exec("rm -rf " . escapeshellarg(Paths::tmp($jobId)));
 *
 * @package App\DeepDive\Support
 */
final class Paths
{
    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function storageRoot(): string
    {
        return self::root() . '/storage/deepdive';
    }

    public static function extracted(string $jobId): string
    {
        return self::storageRoot() . '/extracted/' . self::safe($jobId);
    }

    public static function tmp(string $jobId): string
    {
        return self::storageRoot() . '/tmp/' . self::safe($jobId);
    }

    public static function reportsDir(): string
    {
        return self::storageRoot() . '/reports';
    }

    public static function reportHtml(string $jobId): string
    {
        return self::reportsDir() . '/' . self::safe($jobId) . '.html';
    }

    public static function reportPdf(string $jobId): string
    {
        return self::reportsDir() . '/' . self::safe($jobId) . '.pdf';
    }

    public static function jobLog(string $jobId): string
    {
        return self::storageRoot() . '/logs/' . self::safe($jobId) . '.log';
    }

    public static function detectorCatalogue(): string
    {
        return self::root() . '/config/deepdive/detectors';
    }

    public static function uploads(): string
    {
        return self::root() . '/storage/uploads';
    }

    public static function ensure(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }

    /**
     * Defensive: never trust a caller-supplied job_id. Strip anything
     * that isn't a valid UUID-shaped character so paths cannot escape
     * their parent.
     */
    private static function safe(string $id): string
    {
        if (!preg_match('/^[A-Za-z0-9\-]{8,64}$/', $id)) {
            throw new \InvalidArgumentException('Invalid job id');
        }
        return $id;
    }
}
