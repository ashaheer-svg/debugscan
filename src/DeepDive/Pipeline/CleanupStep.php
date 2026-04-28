<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use App\DeepDive\Support\Paths;

/**
 * Cleanup Step: Remove temporary extraction and working directories
 *
 * PURPOSE:
 * Performs immediate cleanup of temporary storage after pipeline completion.
 * Deletes extracted debug bundles and temporary working directories.
 * Frees disk space by removing large decompressed and intermediate files.
 * Preserves report output (HTML/PDF) which persists in reports/ directory.
 *
 * SEQUENCE:
 * Executes after RenderStep (final step in pipeline), completes pipeline
 *
 * FILES REMOVED:
 * - storage/deepdive/extracted/{job_id}/ : Decompressed debug bundles
 * - storage/deepdive/tmp/{job_id}/ : Intermediate processing files
 * Both directories recursively deleted including all contents.
 *
 * DEBUG MODE PRESERVATION:
 * If job.debug_mode is TRUE:
 * - Extraction directory RETAINED for offline re-analysis
 * - Allows rule authors to re-evaluate rules without re-extracting
 * - Useful for development and troubleshooting
 * - Still marks cleanup as 'skipped' in job record
 *
 * REPORT PERSISTENCE:
 * Reports are ALWAYS persisted regardless of debug mode:
 * - storage/reports/{job_id}.html : Final HTML report
 * - storage/reports/{job_id}.pdf : Optional PDF report (if generated)
 * These survive cleanup and remain available for download.
 *
 * DISK SPACE IMPACT:
 * Typical cleanup reclaims 100MB-1GB per job:
 * - Decompressed logs and system files
 * - Extracted SQLite databases
 * - Temporary processing artifacts
 *
 * ORPHAN SWEEP:
 * Static orphanSweep() method runs periodically from Daemon
 * Cleans up extraction directories for jobs that:
 * - Have no corresponding database record (unknown jobs)
 * - Are older than 1 hour (lost in transit)
 * - Completed/failed with debug_mode=false but cleanup didn't finish
 * Self-healing mechanism for cleanup failures or process crashes
 *
 * ERROR HANDLING:
 * Directory deletion failures are silent (@-suppressed errors)
 * Partial deletions succeed (removes what's deletable)
 * Never throws exceptions (soft step)
 * Cleanup_status recorded in database for audit trail
 *
 * @package App\DeepDive\Pipeline
 */
final class CleanupStep implements StepInterface
{
    /**
     * Get step identifier
     *
     * @return string 'cleanup'
     */
    public function id(): string { return 'cleanup'; }

    /**
     * Remove temporary extraction and working directories after pipeline completion
     *
     * FLOW:
     * 1. Check if debug_mode is enabled on job
     * 2. If debug_mode=true: skip cleanup, preserve extraction, mark skipped, return
     * 3. If debug_mode=false:
     *    a. Build list of target directories to delete
     *    b. Recursively delete each directory with all contents
     *    c. Track file/directory count removed
     * 4. Update job cleanup_status record in database
     * 5. Record statistics and mark step complete
     *
     * PRECONDITIONS:
     * - RenderStep has completed (reports written to disk)
     * - Job record exists with debug_mode flag
     *
     * DEBUG MODE:
     * If ctx->debugMode is TRUE:
     * - Extraction directory retained for offline re-analysis
     * - Cleanup marked 'skipped' in database (audit trail)
     * - Reports still persisted (cleanup only removes temp directories)
     *
     * DELETION TARGETS:
     * - Paths::extracted($jobId) : Decompressed debug bundles
     * - Paths::tmp($jobId) : Intermediate working files
     * Deleted recursively with all subdirectories and files.
     *
     * ERROR HANDLING:
     * - Silent deletion: errors suppressed (@-operator)
     * - Partial success: removes what's deletable
     * - Never throws (soft step)
     * - Reports still survive (in reports/ directory)
     *
     * DATABASE UPDATES:
     * - cleanup_status set to 'skipped' (debug mode) or 'done' (normal)
     * - Used for audit trail and orphan detection
     *
     * @param PipelineContext $ctx Shared pipeline context
     *
     * @return void Removes directories, updates cleanup_status in database
     *
     * @throws none Never throws; all failures handled silently
     */
    public function run(PipelineContext $ctx): void
    {
        $ctx->startStep($this->id());

        // === Check debug mode flag ===
        // If debug_mode=true: preserve extraction directory for offline re-analysis
        // Rule authors can re-evaluate without re-extracting (development convenience)
        // Reports still persist in reports/ directory regardless
        if ($ctx->debugMode) {
            // Mark cleanup as skipped (audit trail)
            $ctx->jobs->markCleanupStatus($ctx->jobId, 'skipped');
            $ctx->stepDetail($this->id(), 'Skipped — debug_mode retained extraction');
            $ctx->completeStep($this->id());
            return;
        }

        // === Build list of temporary directories to delete ===
        // Two buckets of temporary storage:
        // 1. extracted/{job_id}/ : Decompressed debug bundles and parsed files
        // 2. tmp/{job_id}/ : Intermediate processing artifacts
        $targets = [Paths::extracted($ctx->jobId), Paths::tmp($ctx->jobId)];
        $deleted = 0;

        // === Recursively delete each directory ===
        // Silent deletion via @-operator: permission errors don't block pipeline
        // Counts total files and subdirectories removed for logging
        foreach ($targets as $t) {
            if (is_dir($t)) {
                $deleted += $this->rrmdir($t);
            }
        }

        // === Record cleanup completion ===
        // Update database cleanup_status for audit trail and orphan detection
        $ctx->jobs->markCleanupStatus($ctx->jobId, 'done');

        // Report statistics: file and directory count removed
        $ctx->stepDetail($this->id(), "Removed {$deleted} file(s)/dir(s)");

        // Mark cleanup as complete
        $ctx->completeStep($this->id());
    }

    /**
     * Self-healing orphan sweep: remove abandoned extraction directories
     *
     * PURPOSE:
     * Periodic maintenance task that cleans up extraction/tmp directories
     * for jobs that are orphaned or failed to cleanup normally.
     * Called from Daemon on worker boot (not per-pipeline execution).
     *
     * ORPHAN CONDITIONS:
     * Removes directories matching any of:
     * 1. Unknown job: Directory exists in storage but no database record found
     *    AND directory is older than 1 hour (prevents false positives)
     * 2. Post-terminal: Job status is completed/failed/cancelled
     *    AND debug_mode=false (not intentionally preserved)
     *    AND cleanup_status != 'done' (cleanup didn't complete)
     *
     * DIRECTORY VALIDATION:
     * Only targets valid UUID-formatted directory names:
     * - Format: {8 hex}-{4 hex}-{4 hex}-{4 hex}-{12 hex}
     * - Prevents accidental deletion of unrelated directories
     *
     * SCANNING:
     * Scans both buckets:
     * - storage/deepdive/extracted/
     * - storage/deepdive/tmp/
     *
     * DATABASE UPDATES:
     * For post-terminal jobs: updates cleanup_status='done' after removal
     *
     * LOGGING:
     * Logs each removal with reason:
     * - "no job row": Unknown job directory
     * - "post-terminal": Job finished but cleanup didn't complete
     *
     * ERROR HANDLING:
     * Continues on errors; partial cleanup succeeds
     * Silent deletion (@-operator) for permission issues
     *
     * RETURN:
     * Total count of files and directories removed across all orphans
     *
     * @param \PDO $pdo Database connection for job status queries
     * @param \Psr\Log\LoggerInterface $log Logger for recording removals
     *
     * @return int Total files and directories removed
     */
    public static function orphanSweep(\PDO $pdo, \Psr\Log\LoggerInterface $log): int
    {
        $removed = 0;

        // === Scan both storage buckets ===
        // Check extracted/ and tmp/ directories for orphaned subdirectories
        foreach (['extracted', 'tmp'] as $bucket) {
            $base = Paths::storageRoot() . '/' . $bucket;
            if (!is_dir($base)) continue;

            // === Scan directory for potential orphans ===
            // Each subdirectory should have a corresponding deepdive_jobs record
            foreach (scandir($base) ?: [] as $name) {
                // Skip current and parent directory markers
                if ($name === '.' || $name === '..') continue;

                // === Validate directory name is UUID format ===
                // Prevents deletion of unrelated directories
                // UUID format: {8}-{4}-{4}-{4}-{12} hexadecimal characters
                if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $name)) continue;

                // === Query database for corresponding job record ===
                // Fetch job status, debug mode, and cleanup status
                // Age calculated in seconds from queued_at timestamp
                $stmt = $pdo->prepare("
                    SELECT status, cleanup_status, debug_mode,
                           EXTRACT(EPOCH FROM (NOW() - queued_at)) AS age
                    FROM deepdive_jobs WHERE id = :id
                ");
                $stmt->execute(['id' => $name]);
                $row = $stmt->fetch();

                // === Case 1: Unknown job directory (no database record) ===
                // Can happen if:
                // - Job record was deleted
                // - Directory left behind after crash
                // - Database records deleted but directories remained
                // Safety check: only delete if older than 1 hour (prevents false positives)
                if (!$row) {
                    $path = $base . '/' . $name;
                    if (is_dir($path) && (time() - @filemtime($path)) > 3600) {
                        // Directory is definitely orphaned (> 1 hour old, no job record)
                        $removed += self::rrmdirStatic($path);
                        $log->info("DeepDive orphan sweep removed {$bucket}/{$name} (no job row)");
                    }
                    continue;
                }

                // === Case 2: Post-terminal job with incomplete cleanup ===
                // Conditions:
                // - Job status is terminal (completed, failed, or cancelled)
                // - debug_mode is false (extraction not intentionally preserved)
                // - cleanup_status is not 'done' (cleanup step didn't finish)
                // Can happen if:
                // - Process crashed during cleanup
                // - Pipeline was killed mid-cleanup
                // - Database update failed but deletion started
                if (in_array($row['status'], ['completed','failed','cancelled'], true)
                    && !$row['debug_mode']
                    && $row['cleanup_status'] !== 'done') {
                    // Job is terminal and cleanup didn't complete: clean up now
                    $path = $base . '/' . $name;
                    $removed += self::rrmdirStatic($path);
                    $log->info("DeepDive orphan sweep removed {$bucket}/{$name} (post-terminal)");

                    // Update database to mark cleanup as done (prevents re-sweeping)
                    $pdo->prepare("UPDATE deepdive_jobs SET cleanup_status='done' WHERE id=:id")
                        ->execute(['id' => $name]);
                }
            }
        }
        return $removed;
    }

    /**
     * Instance method wrapper for recursive directory deletion
     *
     * Delegates to static rrmdirStatic() for implementation.
     * Provides consistent interface for run() method.
     *
     * @param string $dir Directory path to recursively delete
     *
     * @return int Total files and subdirectories removed
     */
    private function rrmdir(string $dir): int
    {
        return self::rrmdirStatic($dir);
    }

    /**
     * Recursively delete directory tree
     *
     * ALGORITHM:
     * 1. Check directory exists (return 0 if not)
     * 2. Use RecursiveIteratorIterator in CHILD_FIRST mode
     *    - CHILD_FIRST: processes children before parents
     *    - Ensures subdirectories are removed before parent
     * 3. For each item (depth-first, children first):
     *    - If directory: rmdir()
     *    - If file: unlink()
     *    - Increment count
     * 4. Finally delete the root directory itself
     * 5. Return total count of files and directories removed
     *
     * ERROR HANDLING:
     * All file operations suppressed with @-operator:
     * - Permission denied: silently skipped
     * - File locked: silently skipped
     * - Partial deletion succeeds (removes what's deletable)
     * Continues iteration after failures
     *
     * PERFORMANCE:
     * Efficient recursive deletion using SPL iterators
     * Iterates full tree once
     * Memory-bounded by iterator state (not directory size)
     *
     * SAFETY:
     * Only called on validated job directories (UUID format)
     * Path validation prevents accidental deletion
     *
     * @param string $dir Directory path (validated before calling)
     *
     * @return int Total files and subdirectories removed (count > 0 if succeeded)
     */
    private static function rrmdirStatic(string $dir): int
    {
        // Early return if directory doesn't exist
        if (!is_dir($dir)) return 0;

        $count = 0;

        // === Initialize recursive directory iterator ===
        // CHILD_FIRST mode: yields children before parents
        // This is CRITICAL: must delete children before attempting to delete parent
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        // === Delete all files and subdirectories (depth-first) ===
        // CHILD_FIRST ensures we can rmdir() parents after children are gone
        foreach ($iter as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                // Delete subdirectory (safe because children already removed)
                @rmdir($item->getPathname());
            } else {
                // Delete regular file
                @unlink($item->getPathname());
            }
            // Count all removed items (both files and dirs)
            $count++;
        }

        // === Delete the root directory itself ===
        // Now that all children are deleted, parent directory is empty
        @rmdir($dir);

        return $count;
    }
}
