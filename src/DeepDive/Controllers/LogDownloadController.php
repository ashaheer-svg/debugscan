<?php

declare(strict_types=1);

namespace App\DeepDive\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Exception;

/**
 * Log Download Controller - Slim Framework version
 *
 * Provides web interface for downloading and viewing audit logs
 * Slim Framework compatible with PSR-7 request/response
 *
 * Routes:
 * - GET  /deepdive/logs                    - Dashboard
 * - GET  /deepdive/logs/view/{jobId}       - View HTML report
 * - GET  /deepdive/logs/download/{jobId}/{file}  - Download file
 * - GET  /api/deepdive/logs/list           - List logs (JSON)
 * - GET  /api/deepdive/logs/{jobId}        - Get log details (JSON)
 * - POST /api/deepdive/logs/delete/{jobId} - Delete log
 *
 * @package App\DeepDive\Controllers
 */
class LogDownloadController
{
    private string $logsPath;

    public function __construct()
    {
        // Configure logs path - match where LogExporter saves files
        $this->logsPath = dirname(__DIR__, 2) . '/storage/logs/deepdive';
    }

    /**
     * Show dashboard (HTML)
     * GET /deepdive/logs
     */
    public function dashboard(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $logs = $this->getAvailableLogs();

        $html = $this->renderDashboard($logs);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * View HTML report in browser
     * GET /deepdive/logs/view/{jobId}
     */
    public function viewReport(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $jobId = $args['jobId'] ?? '';
            $filePath = $this->logsPath . '/' . $jobId . '/report.html';

            if (!is_file($filePath)) {
                $response->getBody()->write('Report not found');
                return $response->withStatus(404)->withHeader('Content-Type', 'text/plain');
            }

            $contents = file_get_contents($filePath);
            if ($contents === false) {
                $response->getBody()->write('Unable to read file');
                return $response->withStatus(500)->withHeader('Content-Type', 'text/plain');
            }

            $response->getBody()->write($contents);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        } catch (Exception $e) {
            $response->getBody()->write('Error: ' . $e->getMessage());
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain');
        }
    }

    /**
     * Download log file
     * GET /deepdive/logs/download/{jobId}/{filename}
     */
    public function downloadLog(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $jobId = $args['jobId'] ?? '';
            $filename = $args['filename'] ?? '';

            // Validate filename to prevent directory traversal
            if (strpos($filename, '..') !== false || strpos($filename, '/') !== false) {
                $response->getBody()->write('Invalid filename');
                return $response->withStatus(400);
            }

            $filePath = $this->logsPath . '/' . $jobId . '/' . $filename;

            if (!is_file($filePath)) {
                $response->getBody()->write('File not found');
                return $response->withStatus(404);
            }

            $contents = file_get_contents($filePath);
            if ($contents === false) {
                $response->getBody()->write('Unable to read file');
                return $response->withStatus(500);
            }

            $mimeType = $this->getMimeType($filename);
            $downloadName = $this->getDownloadFilename($jobId, $filename);

            $response->getBody()->write($contents);
            return $response
                ->withHeader('Content-Type', $mimeType)
                ->withHeader('Content-Disposition', 'attachment; filename="' . $downloadName . '"')
                ->withHeader('Content-Length', (string)strlen($contents));
        } catch (Exception $e) {
            $response->getBody()->write('Error: ' . $e->getMessage());
            return $response->withStatus(500);
        }
    }

    /**
     * List available logs (JSON API)
     * GET /api/deepdive/logs/list
     */
    public function listLogs(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $logs = $this->getAvailableLogs();

            $data = [
                'success' => true,
                'logs' => $logs,
                'total_count' => count($logs),
                'total_size_bytes' => array_sum(array_column($logs, 'size_bytes')),
            ];

            $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $data = ['success' => false, 'error' => $e->getMessage()];
            $response->getBody()->write(json_encode($data));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Get log details (JSON API)
     * GET /api/deepdive/logs/{jobId}
     */
    public function getLog(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $jobId = $args['jobId'] ?? '';
            $jobDir = $this->logsPath . '/' . $jobId;

            if (!is_dir($jobDir)) {
                $data = ['success' => false, 'error' => 'Log not found'];
                $response->getBody()->write(json_encode($data));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
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
                ];
            }

            $data = [
                'success' => true,
                'job_id' => $jobId,
                'files' => $files,
                'total_size_bytes' => $totalSize,
            ];

            $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $data = ['success' => false, 'error' => $e->getMessage()];
            $response->getBody()->write(json_encode($data));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Delete log (JSON API)
     * POST /api/deepdive/logs/delete/{jobId}
     */
    public function deleteLog(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $jobId = $args['jobId'] ?? '';
            $jobDir = $this->logsPath . '/' . $jobId;

            if (!is_dir($jobDir)) {
                $data = ['success' => false, 'error' => 'Log not found'];
                $response->getBody()->write(json_encode($data));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $this->deleteDirectory($jobDir);

            $data = ['success' => true, 'message' => 'Log deleted'];
            $response->getBody()->write(json_encode($data));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (Exception $e) {
            $data = ['success' => false, 'error' => $e->getMessage()];
            $response->getBody()->write(json_encode($data));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Internal: Get all available logs
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
            ];
        }

        // Sort by date, newest first
        usort($logs, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $logs;
    }

    /**
     * Internal: Render dashboard HTML
     */
    private function renderDashboard(array $logs): string
    {
        $totalSize = array_sum(array_column($logs, 'size_bytes'));
        $totalSizeGb = round($totalSize / (1024 ** 3), 2);

        $logsHtml = '';
        if (!empty($logs)) {
            $logsHtml = '<table class="logs-table"><thead><tr><th>Job ID</th><th>Files</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
            foreach ($logs as $log) {
                $jobId = htmlspecialchars($log['job_id']);
                $filesBadges = '';
                foreach ($log['files'] as $file) {
                    $filesBadges .= '<span class="file-badge">' . htmlspecialchars($file['filename']) . '</span> ';
                }
                $logsHtml .= "<tr>";
                $logsHtml .= "<td><span class=\"job-id\">{$jobId}</span></td>";
                $logsHtml .= "<td>{$filesBadges}</td>";
                $logsHtml .= "<td>" . $this->formatBytes($log['size_bytes']) . "</td>";
                $logsHtml .= "<td>" . htmlspecialchars($log['created_at']) . "</td>";
                $logsHtml .= "<td><a href=\"/deepdive/logs/view/{$jobId}\" class=\"btn btn-secondary\" target=\"_blank\">👁️ View</a> <a href=\"/deepdive/logs/download/{$jobId}/report.html\" class=\"btn btn-primary\">⬇️ HTML</a> <a href=\"/deepdive/logs/download/{$jobId}/audit.json\" class=\"btn btn-primary\">⬇️ JSON</a></td>";
                $logsHtml .= "</tr>";
            }
            $logsHtml .= '</tbody></table>';
        } else {
            $logsHtml = '<div class="no-logs"><div class="no-logs-icon">📁</div><p>No audit logs found</p></div>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DeepDive Audit Logs</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background: white; padding: 40px; border-radius: 12px 12px 0 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .header h1 { color: #333; font-size: 28px; margin-bottom: 10px; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 20px; }
        .stat-box { background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #667eea; }
        .stat-label { color: #666; font-size: 13px; text-transform: uppercase; margin-bottom: 5px; }
        .stat-value { color: #333; font-size: 24px; font-weight: bold; }
        .logs-container { background: white; border-radius: 0 0 12px 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow: hidden; }
        .no-logs { padding: 60px 40px; text-align: center; color: #999; }
        .no-logs-icon { font-size: 48px; margin-bottom: 15px; }
        .logs-table { width: 100%; border-collapse: collapse; }
        .logs-table thead { background: #f8f9fa; border-bottom: 2px solid #eee; }
        .logs-table th { padding: 15px 20px; text-align: left; color: #666; font-weight: 600; font-size: 13px; }
        .logs-table td { padding: 15px 20px; border-bottom: 1px solid #eee; }
        .logs-table tr:hover { background: #f8f9fa; }
        .job-id { font-family: monospace; color: #667eea; font-weight: 500; }
        .file-badge { display: inline-block; padding: 4px 8px; background: #e8eaf6; color: #667eea; border-radius: 4px; font-size: 12px; margin-right: 5px; }
        .btn { padding: 6px 12px; border-radius: 4px; border: none; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-block; margin-right: 5px; }
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5568d3; }
        .btn-secondary { background: #e8eaf6; color: #667eea; }
        .btn-secondary:hover { background: #d1d5f3; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 DeepDive Audit Logs</h1>
            <p>Download and view execution logs from pipeline analysis jobs</p>
            <div class="stats">
                <div class="stat-box"><div class="stat-label">Total Logs</div><div class="stat-value">{$logs|count}</div></div>
                <div class="stat-box"><div class="stat-label">Total Size</div><div class="stat-value">{$totalSizeGb} GB</div></div>
            </div>
        </div>
        <div class="logs-container">
            {$logsHtml}
        </div>
    </div>
</body>
</html>
HTML;
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
     * Internal: Format bytes for display
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
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
