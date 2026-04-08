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

        if (!is_dir($destPath)) {
            mkdir($destPath, 0755, true);
        }

        $extracted = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Path traversal protection
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                continue;
            }

            // Symlink protection
            $stat = $zip->statIndex($i);
            if (($stat['external_attr'] >> 16) & 0120000) {
                continue;
            }

            $shouldExtract = false;
            foreach ($targets as $target) {
                // Check for exact match or prefix (for directories)
                if ($name === $target || (str_ends_with($target, '/') && str_starts_with($name, $target))) {
                    $shouldExtract = true;
                    break;
                }
            }

            if ($shouldExtract) {
                $fullDest = $destPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $name);
                $dir = dirname($fullDest);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                if ($zip->extractTo($destPath, $name)) {
                    $extracted[] = $name;
                }
            }
        }

        $zip->close();
        return $extracted;
    }

    /**
     * Read the last N lines of a file directly from a ZIP archive without fully extracting it.
     * 
     * @param string $zipPath
     * @param string $fileName
     * @param int $limit Last N lines
     * @return string
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

        // For simplicity and efficiency on smaller logs, we'll read the whole stream if it's not huge
        // Otherwise, we'd need a more complex "tail" logic for zip streams
        $content = stream_get_contents($stream);
        fclose($stream);
        $zip->close();

        $lines = explode("\n", $content);
        if (count($lines) <= $limit) {
            return $content;
        }

        return implode("\n", array_slice($lines, -$limit));
    }
}
