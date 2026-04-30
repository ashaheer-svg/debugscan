<?php

declare(strict_types=1);

namespace App\DeepDive\Logging;

use Exception;

/**
 * Log Exporter: Save and retrieve audit logs
 *
 * PURPOSE:
 * Provides functionality to export logs to disk and retrieve them for download.
 * Supports multiple formats: JSON, HTML, CSV
 *
 * STORAGE STRUCTURE:
 * logs/
 *   ├── {job_id}/
 *   │   ├── audit.json (raw audit log)
 *   │   ├── report.html (human-readable report)
 *   │   └── manifest.json (metadata about exports)
 *
 * USAGE:
 * $exporter = new LogExporter($logger, '/var/logs/deepdive');
 * $exporter->save(); // Save all formats
 * $logPath = $exporter->getPath('audit.json');
 * $html = $exporter->download('report.html');
 *
 * @package App\DeepDive\Logging
 */
class LogExporter
{
    private PipelineLogger $logger;
    private string $basePath;
    private string $jobId;

    /**
     * Initialize log exporter
     *
     * @param PipelineLogger $logger Pipeline logger with completed execution data
     * @param string $basePath Base directory for log storage
     *
     * @throws Exception If base path doesn't exist or isn't writable
     */
    public function __construct(PipelineLogger $logger, string $basePath)
    {
        $this->logger = $logger;
        $this->basePath = rtrim($basePath, '/\\');
        $this->jobId = $logger->getJobId();

        // Verify storage directory
        if (!is_dir($this->basePath)) {
            if (!@mkdir($this->basePath, 0755, true)) {
                throw new Exception("Cannot create log directory: {$this->basePath}");
            }
        }

        if (!is_writable($this->basePath)) {
            throw new Exception("Log directory not writable: {$this->basePath}");
        }
    }

    /**
     * Save all log formats
     *
     * Saves JSON, HTML, and manifest files
     *
     * @return array{json: string, html: string, manifest: string} Paths to saved files
     *
     * @throws Exception On write failures
     */
    public function save(): array
    {
        $jobDir = $this->getJobDir();

        if (!is_dir($jobDir)) {
            if (!@mkdir($jobDir, 0755, true)) {
                throw new Exception("Cannot create job log directory: {$jobDir}");
            }
        }

        $paths = [];

        // Save JSON audit log
        $jsonPath = $jobDir . '/audit.json';
        if (file_put_contents($jsonPath, $this->logger->exportJson()) === false) {
            throw new Exception("Failed to write audit log: {$jsonPath}");
        }
        $paths['json'] = $jsonPath;

        // Save HTML report
        $htmlPath = $jobDir . '/report.html';
        if (file_put_contents($htmlPath, $this->logger->exportHtml()) === false) {
            throw new Exception("Failed to write HTML report: {$htmlPath}");
        }
        $paths['html'] = $htmlPath;

        // Save manifest
        $manifest = $this->generateManifest($paths);
        $manifestPath = $jobDir . '/manifest.json';
        if (file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT)) === false) {
            throw new Exception("Failed to write manifest: {$manifestPath}");
        }
        $paths['manifest'] = $manifestPath;

        return $paths;
    }

    /**
     * Get file path for a log file
     *
     * @param string $filename Filename (audit.json, report.html, etc.)
     *
     * @return string Full path to log file
     */
    public function getPath(string $filename): string
    {
        return $this->getJobDir() . '/' . $filename;
    }

    /**
     * Get file size in bytes
     *
     * @param string $filename Filename
     *
     * @return int File size, or 0 if file doesn't exist
     */
    public function getSize(string $filename): int
    {
        $path = $this->getPath($filename);
        return is_file($path) ? filesize($path) : 0;
    }

    /**
     * Download log file contents
     *
     * Suitable for HTTP download response
     *
     * @param string $filename Filename (audit.json, report.html, etc.)
     *
     * @return string File contents, or empty string if not found
     */
    public function download(string $filename): string
    {
        $path = $this->getPath($filename);

        if (!is_file($path)) {
            return '';
        }

        return file_get_contents($path) ?: '';
    }

    /**
     * Get download filename for HTTP response
     *
     * @param string $filename Filename
     *
     * @return string Formatted download filename with job ID and timestamp
     */
    public function getDownloadFilename(string $filename): string
    {
        $timestamp = date('Y-m-d_Hi');
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        return "audit_{$this->jobId}_{$timestamp}.{$ext}";
    }

    /**
     * Get MIME type for filename
     *
     * @param string $filename Filename
     *
     * @return string MIME type
     */
    public function getMimeType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'json' => 'application/json',
            'html' => 'text/html',
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            default => 'application/octet-stream',
        };
    }

    /**
     * List all logs for a job
     *
     * @return array{
     *     job_id: string,
     *     files: array,
     *     created_at: string,
     *     total_size_bytes: int
     * }
     */
    public function listLogs(): array
    {
        $jobDir = $this->getJobDir();
        $files = [];
        $totalSize = 0;

        if (!is_dir($jobDir)) {
            return [
                'job_id' => $this->jobId,
                'files' => $files,
                'created_at' => null,
                'total_size_bytes' => 0,
            ];
        }

        $dirScan = @scandir($jobDir);
        if ($dirScan === false) {
            return [
                'job_id' => $this->jobId,
                'files' => $files,
                'created_at' => null,
                'total_size_bytes' => 0,
            ];
        }

        foreach ($dirScan as $filename) {
            if ($filename === '.' || $filename === '..') {
                continue;
            }

            $fullPath = $jobDir . '/' . $filename;
            if (!is_file($fullPath)) {
                continue;
            }

            $size = filesize($fullPath);
            $totalSize += $size;

            $files[] = [
                'filename' => $filename,
                'size_bytes' => $size,
                'modified_at' => date('Y-m-d H:i:s', filemtime($fullPath)),
                'download_as' => $this->getDownloadFilename($filename),
            ];
        }

        // Sort by modification time, newest first
        usort($files, fn($a, $b) => strcmp($b['modified_at'], $a['modified_at']));

        return [
            'job_id' => $this->jobId,
            'files' => $files,
            'created_at' => $this->getCreatedAt(),
            'total_size_bytes' => $totalSize,
        ];
    }

    /**
     * Clean up old logs (retention policy)
     *
     * Removes logs older than specified days
     *
     * @param int $olderThanDays Delete logs older than this many days
     *
     * @return int Number of logs deleted
     */
    public function cleanup(int $olderThanDays = 30): int
    {
        $deleted = 0;
        $cutoff = time() - ($olderThanDays * 86400);

        if (!is_dir($this->basePath)) {
            return 0;
        }

        $dirs = @scandir($this->basePath);
        if ($dirs === false) {
            return 0;
        }

        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $jobDir = $this->basePath . '/' . $dir;
            if (!is_dir($jobDir)) {
                continue;
            }

            $mtime = filemtime($jobDir);
            if ($mtime !== false && $mtime < $cutoff) {
                if ($this->deleteDirectory($jobDir)) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Internal: Get job-specific directory
     */
    private function getJobDir(): string
    {
        return $this->basePath . '/' . $this->jobId;
    }

    /**
     * Internal: Get directory creation time
     */
    private function getCreatedAt(): ?string
    {
        $jobDir = $this->getJobDir();
        if (!is_dir($jobDir)) {
            return null;
        }

        $mtime = filemtime($jobDir);
        return $mtime ? date('Y-m-d H:i:s', $mtime) : null;
    }

    /**
     * Internal: Generate manifest metadata
     */
    private function generateManifest(array $paths): array
    {
        return [
            'job_id' => $this->jobId,
            'generated_at' => date('Y-m-d H:i:s'),
            'exports' => [
                'json' => [
                    'file' => 'audit.json',
                    'size_bytes' => filesize($paths['json']),
                    'description' => 'Raw audit log in JSON format',
                ],
                'html' => [
                    'file' => 'report.html',
                    'size_bytes' => filesize($paths['html']),
                    'description' => 'Human-readable HTML report',
                ],
            ],
            'retention_days' => 30,
        ];
    }

    /**
     * Internal: Recursively delete directory
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = @scandir($dir);
        if ($files === false) {
            return false;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        return @rmdir($dir);
    }
}

// Extension to PipelineLogger for accessing job ID
// Note: This requires adding a getter method to PipelineLogger class
