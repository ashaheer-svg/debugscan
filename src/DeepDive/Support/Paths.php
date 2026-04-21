<?php

declare(strict_types=1);

namespace App\DeepDive\Support;

/**
 * Single source of truth for every path DeepDive uses.
 * All paths live under storage/deepdive/* — fully isolated from the
 * existing scan pipeline's storage/extracted and storage/reports.
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
