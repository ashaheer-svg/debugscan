<?php

declare(strict_types=1);

namespace App\Helpers;

use ZipArchive;
use RuntimeException;

class ZipHelper
{
    /**
     * Safely extract a list of files from a Synology debug .dat archive.
     * Uses path traversal and symlink protection.
     * 
     * @param string $zipPath Path to the zip file
     * @param array $targets List of files or directory prefixes to extract
     * @param string $destPath Destination directory
     * @return array List of extracted files (relative to destPath)
     */
    public static function extractSelected(string $zipPath, array $targets, string $destPath): array
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
                // 3. SECURE PATH RESOLUTION
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
}
