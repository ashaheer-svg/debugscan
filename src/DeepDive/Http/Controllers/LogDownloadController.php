<?php

declare(strict_types=1);

namespace App\DeepDive\Http\Controllers;

use App\DeepDive\Logging\PipelineLogger;
use App\DeepDive\Logging\LogExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Exception;

/**
 * Log Download Controller: Web interface for downloading audit logs
 *
 * Provides:
 * - Dashboard for browsing available logs
 * - Download endpoints for JSON/HTML formats
 * - API for programmatic access
 * - Log listing and filtering
 *
 * @package App\DeepDive\Http\Controllers
 */
class LogDownloadController
{
    private string $logsPath;

    /**
     * Initialize controller
     */
    public function __construct()
    {
        $this->logsPath = config('deepdive.logging.path', storage_path('logs/deepdive'));
    }

    /**
     * Show logs dashboard
     *
     * Displays list of available audit logs with download options
     *
     * @return View
     */
    public function dashboard(): View
    {
        $logs = $this->getAvailableLogs();

        return view('deepdive.logs.dashboard', [
            'logs' => $logs,
            'total_logs' => count($logs),
            'total_size_gb' => round(array_sum(array_column($logs, 'size_bytes')) / (1024 ** 3), 2),
        ]);
    }

    /**
     * Get available logs as JSON
     *
     * API endpoint for listing logs
     *
     * @return JsonResponse
     */
    public function listLogs(): JsonResponse
    {
        try {
            $logs = $this->getAvailableLogs();

            return response()->json([
                'success' => true,
                'logs' => $logs,
                'total_count' => count($logs),
                'total_size_bytes' => array_sum(array_column($logs, 'size_bytes')),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed log information
     *
     * @param string $jobId Job ID
     *
     * @return JsonResponse
     */
    public function getLog(string $jobId): JsonResponse
    {
        try {
            $jobDir = $this->logsPath . '/' . $jobId;

            if (!is_dir($jobDir)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Log not found: ' . $jobId,
                ], 404);
            }

            $files = [];
            $totalSize = 0;

            foreach (scandir($jobDir) ?: [] as $filename) {
                if (in_array($filename, ['.', '..'])) continue;

                $path = $jobDir . '/' . $filename;
                if (!is_file($path)) continue;

                $size = filesize($path);
                $totalSize += $size;

                $files[] = [
                    'filename' => $filename,
                    'size_bytes' => $size,
                    'size_kb' => round($size / 1024, 2),
                    'modified' => date('Y-m-d H:i:s', filemtime($path)),
                ];
            }

            return response()->json([
                'success' => true,
                'job_id' => $jobId,
                'files' => $files,
                'total_size_bytes' => $totalSize,
                'total_size_kb' => round($totalSize / 1024, 2),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download log file
     *
     * @param string $jobId Job ID
     * @param string $filename Filename (audit.json, report.html, etc.)
     *
     * @return Response|JsonResponse
     */
    public function downloadLog(string $jobId, string $filename)
    {
        try {
            // Validate filename to prevent directory traversal
            if (strpos($filename, '..') !== false || strpos($filename, '/') !== false) {
                return response()->json(['error' => 'Invalid filename'], 400);
            }

            $filePath = $this->logsPath . '/' . $jobId . '/' . $filename;

            if (!is_file($filePath)) {
                return response()->json(['error' => 'File not found'], 404);
            }

            // Determine MIME type
            $mimeType = $this->getMimeType($filename);

            // Get file contents
            $contents = file_get_contents($filePath);
            if ($contents === false) {
                return response()->json(['error' => 'Unable to read file'], 500);
            }

            // Determine download filename
            $downloadName = $this->getDownloadFilename($jobId, $filename);

            return response($contents, 200, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'attachment; filename="' . $downloadName . '"',
                'Content-Length' => strlen($contents),
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * View log as HTML (for report.html)
     *
     * Display HTML report in browser instead of downloading
     *
     * @param string $jobId Job ID
     *
     * @return Response|JsonResponse
     */
    public function viewReport(string $jobId)
    {
        try {
            $filePath = $this->logsPath . '/' . $jobId . '/report.html';

            if (!is_file($filePath)) {
                return response()->json(['error' => 'Report not found'], 404);
            }

            $contents = file_get_contents($filePath);
            if ($contents === false) {
                return response()->json(['error' => 'Unable to read file'], 500);
            }

            return response($contents, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get audit log as JSON and display/download
     *
     * @param string $jobId Job ID
     *
     * @return JsonResponse|Response
     */
    public function getAuditJson(string $jobId)
    {
        try {
            $filePath = $this->logsPath . '/' . $jobId . '/audit.json';

            if (!is_file($filePath)) {
                return response()->json(['error' => 'Audit log not found'], 404);
            }

            $contents = file_get_contents($filePath);
            if ($contents === false) {
                return response()->json(['error' => 'Unable to read file'], 500);
            }

            // Parse JSON to return as JSON response (allows browser viewing)
            $data = json_decode($contents, true);

            return response()->json($data);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete log (cleanup)
     *
     * @param string $jobId Job ID
     *
     * @return JsonResponse
     */
    public function deleteLog(string $jobId): JsonResponse
    {
        try {
            $jobDir = $this->logsPath . '/' . $jobId;

            if (!is_dir($jobDir)) {
                return response()->json(['success' => false, 'error' => 'Log not found'], 404);
            }

            // Recursively delete directory
            $this->deleteDirectory($jobDir);

            return response()->json(['success' => true, 'message' => 'Log deleted']);
        } catch (Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Search logs
     *
     * Filter logs by job ID, date range, or size
     *
     * @return JsonResponse
     */
    public function search(): JsonResponse
    {
        $query = request()->input('q', '');
        $sort = request()->input('sort', 'date');
        $order = request()->input('order', 'desc');

        try {
            $logs = $this->getAvailableLogs();

            // Filter by query
            if ($query) {
                $logs = array_filter($logs, function ($log) use ($query) {
                    return stripos($log['job_id'], $query) !== false;
                });
            }

            // Sort
            usort($logs, function ($a, $b) use ($sort, $order) {
                $aVal = $a[$sort] ?? '';
                $bVal = $b[$sort] ?? '';

                $cmp = $aVal <=> $bVal;
                return $order === 'desc' ? -$cmp : $cmp;
            });

            return response()->json([
                'success' => true,
                'query' => $query,
                'results' => array_values($logs),
                'count' => count($logs),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get statistics about logs
     *
     * @return JsonResponse
     */
    public function statistics(): JsonResponse
    {
        try {
            $logs = $this->getAvailableLogs();

            $stats = [
                'total_logs' => count($logs),
                'total_size_bytes' => array_sum(array_column($logs, 'size_bytes')),
                'oldest_log' => $logs ? min(array_column($logs, 'created_at')) : null,
                'newest_log' => $logs ? max(array_column($logs, 'created_at')) : null,
                'average_size_bytes' => count($logs) > 0 ? array_sum(array_column($logs, 'size_bytes')) / count($logs) : 0,
            ];

            return response()->json([
                'success' => true,
                'statistics' => $stats,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Internal: Get all available logs
     *
     * @return array
     */
    private function getAvailableLogs(): array
    {
        $logs = [];

        if (!is_dir($this->logsPath)) {
            return $logs;
        }

        foreach (scandir($this->logsPath) ?: [] as $jobId) {
            if (in_array($jobId, ['.', '..'])) continue;

            $jobDir = $this->logsPath . '/' . $jobId;
            if (!is_dir($jobDir)) continue;

            $files = [];
            $totalSize = 0;

            foreach (scandir($jobDir) ?: [] as $filename) {
                if (in_array($filename, ['.', '..'])) continue;

                $path = $jobDir . '/' . $filename;
                if (!is_file($path)) continue;

                $size = filesize($path);
                $totalSize += $size;

                $files[] = [
                    'filename' => $filename,
                    'size_bytes' => $size,
                    'size_kb' => round($size / 1024, 2),
                ];
            }

            $logs[] = [
                'job_id' => $jobId,
                'files' => $files,
                'file_count' => count($files),
                'size_bytes' => $totalSize,
                'size_kb' => round($totalSize / 1024, 2),
                'created_at' => date('Y-m-d H:i:s', filectime($jobDir) ?: time()),
                'modified_at' => date('Y-m-d H:i:s', filemtime($jobDir) ?: time()),
            ];
        }

        // Sort by date, newest first
        usort($logs, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $logs;
    }

    /**
     * Internal: Get MIME type for file
     */
    private function getMimeType(string $filename): string
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
     * Internal: Get download filename
     */
    private function getDownloadFilename(string $jobId, string $filename): string
    {
        $timestamp = date('Y-m-d_Hi');
        $ext = pathinfo($filename, PATHINFO_EXTENSION);

        return "audit_{$jobId}_{$timestamp}.{$ext}";
    }

    /**
     * Internal: Recursively delete directory
     */
    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) return false;

        foreach (scandir($dir) ?: [] as $file) {
            if (in_array($file, ['.', '..'])) continue;

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
