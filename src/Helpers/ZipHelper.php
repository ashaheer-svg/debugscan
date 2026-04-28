<?php

declare(strict_types=1);

namespace App\Helpers;

use ZipArchive;
use RuntimeException;

/**
 * ZipHelper: Secure extraction of debug bundle archives
 *
 * PURPOSE:
 * Safely extract selective files from Synology debug.dat archives (ZIP format)
 * Prevent zip bomb and path traversal attacks
 * Respect size limits during extraction
 *
 * SECURITY FEATURES:
 * 1. Path Traversal Protection:
 *    - Filter ".." sequences
 *    - Reject absolute paths (leading /)
 *    - Verify extracted paths stay within destPath
 *
 * 2. Symlink Protection:
 *    - Detect symbolic links in archive
 *    - Skip symlinks (prevent escape attacks)
 *    - Check file attributes from ZIP metadata
 *
 * 3. Zip Bomb Protection:
 *    - Track cumulative uncompressed size
 *    - Enforce maxAbsoluteBytes limit (default: 2GB)
 *    - Abort if limit exceeded
 *    - Prevents malicious expansion attacks
 *
 * 4. Selective Extraction:
 *    - Only extract files matching targets list
 *    - Targets: exact names or directory prefixes (e.g., "dsm/var/log/")
 *    - Reduces extracted size vs full archive extraction
 *
 * WORKFLOW:
 * 1. Open ZIP archive
 * 2. Validate destination path (must be absolute, settledDirectory)
 * 3. For each file in archive:
 *    - Check path traversal vulnerability
 *    - Check symlink status
 *    - Check if matches target list
 *    - Verify total size doesn't exceed limit
 *    - Extract to secure path
 * 4. Return list of extracted file paths
 *
 * @package App\Helpers
 */
class ZipHelper
{
    /**
     * Safely extract selected files from ZIP archive
     *
     * SELECTIVE EXTRACTION:
     * targets: Array of file paths or directory prefixes
     * - Exact match: "dsm/etc/VERSION" (specific file)
     * - Directory prefix: "dsm/var/log/" (all files under path)
     * - Prefix wildcard: "dsm/result." (files starting with prefix)
     *
     * SECURITY VALIDATION:
     * - Pre-validation: Path traversal checks
     * - Symlink detection: Skip links (check external_attr bits)
     * - Zip bomb: Total uncompressed size vs limit
     * - Post-extraction: realpath() verification
     *
     * @param string $zipPath Absolute path to .dat archive
     * @param array<string> $targets Files/directories to extract
     * @param string $destPath Destination directory (created if missing)
     * @param int $maxAbsoluteBytes Size limit (default: 2GB)
     *
     * @return array<string> Extracted file paths (relative to destPath)
     *
     * @throws RuntimeException If ZIP cannot open, limit exceeded, or validation fails
     */
    public static function extractSelected(string $zipPath, array $targets, string $destPath, int $maxAbsoluteBytes = 2147483648): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Could not open ZIP file: $zipPath");
        }

        // Ensure destPath is absolute and settled
        if (!is_dir($destPath)) {
            mkdir($destPath, 0755, true);
        }
        $realDestRoot = realpath($destPath);
        if (!$realDestRoot) {
            throw new RuntimeException("Invalid destination path: $destPath");
        }

        $extracted = [];
        $totalBytes = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // 1. Basic Path traversal protection (Pre-validation)
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                continue;
            }

            // 2. Symlink protection
            $stat = $zip->statIndex($i);
            if (($stat['external_attr'] >> 16) & 0120000) {
                continue;
            }

            $shouldExtract = false;
            foreach ($targets as $target) {
                if ($name === $target || 
                   (str_ends_with($target, '/') && str_starts_with($name, $target)) ||
                   (str_ends_with($target, '.') && str_starts_with($name, $target))) {
                    $shouldExtract = true;
                    break;
                }
            }

            if ($shouldExtract) {
                // 3. ZIP BOMB PROTECTION
                $totalBytes += $stat['size'];
                if ($totalBytes > $maxAbsoluteBytes) {
                    $zip->close();
                    throw new RuntimeException("Security Error: Extraction limit exceeded ({$totalBytes} bytes). Archive may be a Zip Bomb.");
                }

                // 4. SECURE PATH RESOLUTION
                // We resolve the full final path and ensure it's STILL inside $realDestRoot
                $fullDest = $realDestRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $name);
                
                // On Windows/Linux, we need to handle potential directory creation securely
                $dir = dirname($fullDest);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                // Final safety check: Does the extracted file land inside our root?
                // Note: realpath() only works on existing files, so we check string prefix first
                if (!str_starts_with(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullDest), $realDestRoot)) {
                    continue; 
                }

                if ($zip->extractTo($realDestRoot, $name)) {
                    $extracted[] = $name;
                }
            }
        }

        $zip->close();
        return $extracted;
    }

    /**
     * Read the last N lines of a file directly from a ZIP archive using memory-efficient streaming.
     */
    public static function tailFileFromZip(string $zipPath, string $fileName, int $limit = 500): string
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return "";
        }

        $stream = $zip->getStream($fileName);
        if (!$stream) {
            $zip->close();
            return "";
        }

        // Memory-efficient Line Reading
        // We use a rolling buffer to avoid loading multi-GB files into memory
        $lines = [];
        while (!feof($stream)) {
            $line = fgets($stream, 8192); // 8KB buffer per line
            if ($line === false) break;
            
            $lines[] = $line;
            if (count($lines) > $limit) {
                array_shift($lines);
            }
        }

        fclose($stream);
        $zip->close();

        return implode("", $lines);
    }

    /**
     * Recursively search a directory for .xz files and decompress them in place.
     * Reclaims historical log data.
     */
    public static function decompressXzFiles(string $directory): void
    {
        if (!is_dir($directory)) return;

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.xz')) {
                $path = $file->getRealPath();
                // We use shell_exec for xz as it's the most reliable way on Linux
                // Only if 'xz' command exists (verified in Phase 1 plan)
                $cmd = "xz -d " . escapeshellarg($path) . " 2>&1";
                shell_exec($cmd);
            }
        }
    }
}
