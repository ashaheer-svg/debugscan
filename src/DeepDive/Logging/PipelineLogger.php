<?php

declare(strict_types=1);

namespace App\DeepDive\Logging;

use DateTime;
use DateTimeZone;

/**
 * Pipeline Logger: Comprehensive execution logging and audit trail
 *
 * PURPOSE:
 * Provides detailed logging of all DeepDive pipeline operations for:
 * - Process transparency: See exactly what's happening at each step
 * - Deployment verification: Track which versions of code are running
 * - Debugging: Analyze failures and performance issues
 * - Compliance: Create downloadable audit trails
 *
 * FEATURES:
 * - Step-by-step operation tracking
 * - File extraction and processing records
 * - Component version verification
 * - Performance metrics (duration, memory, file counts)
 * - Error and warning tracking
 * - Structured JSON format for analysis
 * - HTML summary report generation
 *
 * USAGE:
 * $logger = new PipelineLogger($jobId);
 * $logger->logStepStart('parse', 'Parsing bundles');
 * $logger->logFileExtracted('var/log/messages', 524288);
 * $logger->logStepComplete('parse', 'Parsed 5 bundles successfully');
 * $log = $logger->exportLog(); // Downloadable audit trail
 *
 * @package App\DeepDive\Logging
 */
class PipelineLogger
{
    private const VERSION = '1.0.0';

    private string $jobId;
    private string $sessionId;
    private DateTime $startTime;
    private array $entries = [];
    private array $filesExtracted = [];
    private array $componentVersions = [];
    private array $stepStack = [];

    /**
     * Initialize pipeline logger
     *
     * @param string $jobId Unique job identifier
     * @param string $sessionId Optional session identifier
     */
    public function __construct(string $jobId, string $sessionId = '')
    {
        $this->jobId = $jobId;
        $this->sessionId = $sessionId ?: bin2hex(random_bytes(8));
        $this->startTime = new DateTime('now', new DateTimeZone('UTC'));

        // Log initialization
        $this->logEntry('INIT', 'Pipeline logger started', [
            'job_id' => $jobId,
            'session_id' => $this->sessionId,
            'timestamp' => $this->startTime->format('Y-m-d H:i:s.u'),
            'logger_version' => self::VERSION,
        ]);
    }

    /**
     * Get job ID
     *
     * @return string
     */
    public function getJobId(): string
    {
        return $this->jobId;
    }

    /**
     * Get session ID
     *
     * @return string
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Register component version for verification
     *
     * Used to verify that updated code is deployed correctly
     *
     * @param string $component Component name (e.g., 'PowerSupplyParser', 'FileAvailabilityValidator')
     * @param string $version Version string (e.g., '3.1.0')
     * @param array $metadata Optional metadata (file path, class, etc.)
     *
     * @return void
     */
    public function registerComponentVersion(
        string $component,
        string $version,
        array $metadata = []
    ): void {
        $this->componentVersions[$component] = [
            'version' => $version,
            'registered_at' => $this->now(),
            'metadata' => $metadata,
        ];

        $this->logEntry('VERSION', "Component version registered: {$component}", [
            'component' => $component,
            'version' => $version,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log step start
     *
     * @param string $stepId Step identifier (e.g., 'decompress', 'parse', 'evaluate', 'render')
     * @param string $description Human-readable description
     * @param array $metadata Optional metadata
     *
     * @return void
     */
    public function logStepStart(string $stepId, string $description, array $metadata = []): void
    {
        $this->stepStack[] = [
            'step_id' => $stepId,
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
        ];

        $this->logEntry('STEP_START', $description, array_merge([
            'step_id' => $stepId,
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ], $metadata));
    }

    /**
     * Log step completion
     *
     * @param string $stepId Step identifier
     * @param string $description Completion message
     * @param array $metadata Optional metadata (counts, results, etc.)
     *
     * @return array Duration and memory metrics
     */
    public function logStepComplete(string $stepId, string $description, array $metadata = []): array
    {
        $stepData = null;
        foreach (array_reverse($this->stepStack) as $step) {
            if ($step['step_id'] === $stepId) {
                $stepData = $step;
                break;
            }
        }

        if (!$stepData) {
            $this->logEntry('WARNING', "Step complete called for non-started step: {$stepId}", []);
            return [];
        }

        $duration = microtime(true) - $stepData['start_time'];
        $memoryUsed = memory_get_usage(true) - $stepData['memory_start'];
        $memoryPeak = memory_get_peak_usage(true);

        $metrics = [
            'duration_seconds' => round($duration, 3),
            'memory_used_mb' => round($memoryUsed / 1024 / 1024, 2),
            'memory_peak_mb' => round($memoryPeak / 1024 / 1024, 2),
        ];

        $this->logEntry('STEP_COMPLETE', $description, array_merge([
            'step_id' => $stepId,
        ], $metrics, $metadata));

        // Remove from stack
        $this->stepStack = array_filter(
            $this->stepStack,
            fn($s) => $s['step_id'] !== $stepId
        );

        return $metrics;
    }

    /**
     * Log file extraction
     *
     * Records that a file was extracted from bundle
     *
     * @param string $fileName Original file name/path
     * @param int $sizeBytes File size in bytes
     * @param string $category File category (log, config, database, etc.)
     * @param array $metadata Optional metadata
     *
     * @return void
     */
    public function logFileExtracted(
        string $fileName,
        int $sizeBytes,
        string $category = 'unknown',
        array $metadata = []
    ): void {
        $this->filesExtracted[] = [
            'file_name' => $fileName,
            'size_bytes' => $sizeBytes,
            'category' => $category,
            'extracted_at' => $this->now(),
            'metadata' => $metadata,
        ];

        $this->logEntry('FILE_EXTRACTED', "Extracted: {$fileName}", [
            'file_name' => $fileName,
            'size_kb' => round($sizeBytes / 1024, 2),
            'category' => $category,
        ]);
    }

    /**
     * Log data parsing
     *
     * Records data extraction from files
     *
     * @param string $parser Parser class name
     * @param string $fileName Source file name
     * @param int $recordsExtracted Number of records/items extracted
     * @param array $metadata Optional metadata
     *
     * @return void
     */
    public function logDataParsing(
        string $parser,
        string $fileName,
        int $recordsExtracted,
        array $metadata = []
    ): void {
        $this->logEntry('PARSE', "Data parsing: {$parser}", array_merge([
            'parser' => $parser,
            'source_file' => $fileName,
            'records_extracted' => $recordsExtracted,
        ], $metadata));
    }

    /**
     * Log validation check
     *
     * Records file/data validation operations
     *
     * @param string $validator Validator class name
     * @param string $target Target being validated
     * @param bool $passed Validation result
     * @param string $message Validation message
     * @param array $metadata Optional metadata
     *
     * @return void
     */
    public function logValidation(
        string $validator,
        string $target,
        bool $passed,
        string $message,
        array $metadata = []
    ): void {
        $this->logEntry('VALIDATION', $message, array_merge([
            'validator' => $validator,
            'target' => $target,
            'passed' => $passed,
            'status' => $passed ? 'PASS' : 'FAIL',
        ], $metadata));
    }

    /**
     * Log anomaly detection
     *
     * Records anomalies found during analysis
     *
     * @param string $type Anomaly type
     * @param string $description Anomaly description
     * @param float $confidence Confidence score (0-1)
     * @param array $metadata Optional metadata
     *
     * @return void
     */
    public function logAnomalyDetected(
        string $type,
        string $description,
        float $confidence,
        array $metadata = []
    ): void {
        $this->logEntry('ANOMALY', $description, array_merge([
            'anomaly_type' => $type,
            'confidence' => round($confidence * 100, 1) . '%',
        ], $metadata));
    }

    /**
     * Log warning
     *
     * @param string $message Warning message
     * @param array $context Optional context
     *
     * @return void
     */
    public function logWarning(string $message, array $context = []): void
    {
        $this->logEntry('WARNING', $message, $context);
    }

    /**
     * Log error
     *
     * @param string $message Error message
     * @param string $exception Optional exception class/message
     * @param array $context Optional context
     *
     * @return void
     */
    public function logError(string $message, string $exception = '', array $context = []): void
    {
        $data = [
            'error_message' => $message,
        ];

        if ($exception) {
            $data['exception'] = $exception;
        }

        $this->logEntry('ERROR', $message, array_merge($data, $context));
    }

    /**
     * Log custom entry
     *
     * @param string $level Log level (INFO, WARNING, ERROR, etc.)
     * @param string $message Log message
     * @param array $data Additional data
     *
     * @return void
     */
    public function logEntry(string $level, string $message, array $data = []): void
    {
        $this->entries[] = [
            'timestamp' => $this->now(),
            'level' => $level,
            'message' => $message,
            'data' => $data,
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ];
    }

    /**
     * Export complete audit log
     *
     * Returns structured log data suitable for storage/analysis
     *
     * @return array Complete audit log
     */
    public function exportLog(): array
    {
        $endTime = new DateTime('now', new DateTimeZone('UTC'));
        $totalDuration = $endTime->getTimestamp() - $this->startTime->getTimestamp();

        return [
            'metadata' => [
                'job_id' => $this->jobId,
                'session_id' => $this->sessionId,
                'logger_version' => self::VERSION,
                'start_time' => $this->startTime->format('Y-m-d H:i:s.u'),
                'end_time' => $endTime->format('Y-m-d H:i:s.u'),
                'total_duration_seconds' => $totalDuration,
                'timezone' => 'UTC',
            ],
            'component_versions' => $this->componentVersions,
            'files_extracted' => [
                'total_count' => count($this->filesExtracted),
                'total_size_bytes' => array_sum(array_column($this->filesExtracted, 'size_bytes')),
                'by_category' => $this->groupFilesByCategory(),
                'files' => $this->filesExtracted,
            ],
            'entries' => $this->entries,
            'summary' => $this->generateSummary(),
        ];
    }

    /**
     * Export log as JSON
     *
     * @return string JSON-encoded audit log
     */
    public function exportJson(): string
    {
        return json_encode($this->exportLog(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Export log as HTML report
     *
     * Generates a human-readable HTML summary for easy viewing
     *
     * @return string HTML report
     */
    public function exportHtml(): string
    {
        $log = $this->exportLog();
        $html = '';

        // HTML Header
        $html .= <<<'EOF'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DeepDive Pipeline Audit Log</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 3px solid #0066cc; padding-bottom: 10px; }
        h2 { color: #0066cc; margin-top: 30px; }
        .metadata { background: #f9f9f9; padding: 15px; border-radius: 4px; margin: 15px 0; border-left: 4px solid #0066cc; }
        .versions { background: #e8f4f8; padding: 15px; border-radius: 4px; margin: 15px 0; border-left: 4px solid #00aa00; }
        .entry { border-left: 4px solid #ccc; padding: 10px 15px; margin: 10px 0; background: #fafafa; border-radius: 4px; }
        .entry.STEP_START { border-left-color: #0066cc; background: #e3f2fd; }
        .entry.STEP_COMPLETE { border-left-color: #00aa00; background: #e8f5e9; }
        .entry.FILE_EXTRACTED { border-left-color: #ff9800; background: #fff3e0; }
        .entry.ERROR { border-left-color: #f44336; background: #ffebee; }
        .entry.WARNING { border-left-color: #ff9800; background: #fff3e0; }
        .entry.VALIDATION { border-left-color: #9c27b0; background: #f3e5f5; }
        .timestamp { color: #666; font-size: 0.85em; }
        .level { display: inline-block; padding: 2px 8px; border-radius: 3px; font-weight: bold; font-size: 0.8em; margin-right: 10px; }
        .level.ERROR { background: #f44336; color: white; }
        .level.WARNING { background: #ff9800; color: white; }
        .level.VALIDATION { background: #9c27b0; color: white; }
        .level.STEP_START, .level.STEP_COMPLETE { background: #0066cc; color: white; }
        .level.FILE_EXTRACTED { background: #ff9800; color: white; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th { background: #f5f5f5; padding: 10px; text-align: left; border-bottom: 2px solid #ddd; }
        td { padding: 10px; border-bottom: 1px solid #eee; }
        .summary { background: #e3f2fd; padding: 15px; border-radius: 4px; margin: 15px 0; }
        code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 DeepDive Pipeline Audit Log</h1>
EOF;

        // Metadata Section
        $html .= '<div class="metadata">';
        $html .= '<h2>📋 Metadata</h2>';
        $html .= '<table>';
        foreach ($log['metadata'] as $key => $value) {
            $html .= '<tr><td><strong>' . htmlspecialchars($key) . '</strong></td><td>' . htmlspecialchars((string)$value) . '</td></tr>';
        }
        $html .= '</table>';
        $html .= '</div>';

        // Component Versions
        if (!empty($log['component_versions'])) {
            $html .= '<div class="versions">';
            $html .= '<h2>📦 Component Versions (Deployment Verification)</h2>';
            $html .= '<table>';
            $html .= '<tr><th>Component</th><th>Version</th><th>Registered At</th></tr>';
            foreach ($log['component_versions'] as $component => $info) {
                $html .= '<tr>';
                $html .= '<td><strong>' . htmlspecialchars($component) . '</strong></td>';
                $html .= '<td><code>' . htmlspecialchars($info['version']) . '</code></td>';
                $html .= '<td>' . htmlspecialchars($info['registered_at']) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</table>';
            $html .= '</div>';
        }

        // Files Extracted Summary
        $html .= '<div class="summary">';
        $html .= '<h2>📂 Files Extracted</h2>';
        $html .= '<p>Total Files: <strong>' . $log['files_extracted']['total_count'] . '</strong></p>';
        $html .= '<p>Total Size: <strong>' . $this->formatBytes($log['files_extracted']['total_size_bytes']) . '</strong></p>';
        if (!empty($log['files_extracted']['by_category'])) {
            $html .= '<h3>By Category</h3><table>';
            $html .= '<tr><th>Category</th><th>Count</th><th>Total Size</th></tr>';
            foreach ($log['files_extracted']['by_category'] as $category => $info) {
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars($category) . '</td>';
                $html .= '<td>' . $info['count'] . '</td>';
                $html .= '<td>' . $this->formatBytes($info['total_size_bytes']) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</table>';
        }
        $html .= '</div>';

        // Entries
        $html .= '<h2>📝 Execution Log</h2>';
        foreach ($log['entries'] as $entry) {
            $levelClass = str_replace('-', '_', $entry['level']);
            $html .= '<div class="entry ' . htmlspecialchars($levelClass) . '">';
            $html .= '<span class="level ' . htmlspecialchars($levelClass) . '">' . htmlspecialchars($entry['level']) . '</span>';
            $html .= '<span class="timestamp">' . htmlspecialchars($entry['timestamp']) . '</span>';
            $html .= '<br>';
            $html .= '<strong>' . htmlspecialchars($entry['message']) . '</strong>';
            if (!empty($entry['data'])) {
                $html .= '<br><pre style="margin: 5px 0; font-size: 0.85em; background: #f5f5f5; padding: 8px; border-radius: 3px;">' . htmlspecialchars(json_encode($entry['data'], JSON_PRETTY_PRINT)) . '</pre>';
            }
            $html .= '</div>';
        }

        // Summary
        $html .= '<h2>📊 Summary</h2>';
        $html .= '<pre>' . htmlspecialchars(json_encode($log['summary'], JSON_PRETTY_PRINT)) . '</pre>';

        $html .= '</div></body></html>';

        return $html;
    }

    /**
     * Internal: Get current timestamp
     */
    private function now(): string
    {
        return (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    /**
     * Internal: Group files by category
     */
    private function groupFilesByCategory(): array
    {
        $grouped = [];
        foreach ($this->filesExtracted as $file) {
            $category = $file['category'];
            if (!isset($grouped[$category])) {
                $grouped[$category] = ['count' => 0, 'total_size_bytes' => 0];
            }
            $grouped[$category]['count']++;
            $grouped[$category]['total_size_bytes'] += $file['size_bytes'];
        }
        return $grouped;
    }

    /**
     * Internal: Generate execution summary
     */
    private function generateSummary(): array
    {
        $summary = [
            'total_entries' => count($this->entries),
            'entries_by_level' => [],
            'files_extracted' => count($this->filesExtracted),
            'total_file_size_bytes' => array_sum(array_column($this->filesExtracted, 'size_bytes')),
        ];

        // Count by level
        foreach ($this->entries as $entry) {
            $level = $entry['level'];
            if (!isset($summary['entries_by_level'][$level])) {
                $summary['entries_by_level'][$level] = 0;
            }
            $summary['entries_by_level'][$level]++;
        }

        return $summary;
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
}
