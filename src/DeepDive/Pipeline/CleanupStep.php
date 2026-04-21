<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use App\DeepDive\Support\Paths;

/**
 * Immediate cleanup — deletes storage/deepdive/extracted/{job_id}/
 * and storage/deepdive/tmp/{job_id}/ as soon as the report is written.
 *
 * If debug_mode is set on the job, the extraction tree is preserved so
 * rule authors can re-evaluate without re-extracting. Reports always
 * persist regardless.
 */
final class CleanupStep implements StepInterface
{
    public function id(): string { return 'cleanup'; }

    public function run(PipelineContext $ctx): void
    {
        $ctx->startStep($this->id());

        if ($ctx->debugMode) {
            $ctx->jobs->markCleanupStatus($ctx->jobId, 'skipped');
            $ctx->stepDetail($this->id(), 'Skipped — debug_mode retained extraction');
            $ctx->completeStep($this->id());
            return;
        }

        $targets = [Paths::extracted($ctx->jobId), Paths::tmp($ctx->jobId)];
        $deleted = 0;
        foreach ($targets as $t) {
            if (is_dir($t)) {
                $deleted += $this->rrmdir($t);
            }
        }

        $ctx->jobs->markCleanupStatus($ctx->jobId, 'done');
        $ctx->stepDetail($this->id(), "Removed {$deleted} file(s)/dir(s)");
        $ctx->completeStep($this->id());
    }

    /**
     * Self-healing orphan sweep: removes any extracted/tmp directory
     * whose parent job is already completed or failed. Called on worker
     * boot from the Daemon, not per-pipeline.
     */
    public static function orphanSweep(\PDO $pdo, \Psr\Log\LoggerInterface $log): int
    {
        $removed = 0;
        foreach (['extracted', 'tmp'] as $bucket) {
            $base = Paths::storageRoot() . '/' . $bucket;
            if (!is_dir($base)) continue;

            foreach (scandir($base) ?: [] as $name) {
                if ($name === '.' || $name === '..') continue;
                if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $name)) continue;

                $stmt = $pdo->prepare("
                    SELECT status, cleanup_status, debug_mode,
                           EXTRACT(EPOCH FROM (NOW() - queued_at)) AS age
                    FROM deepdive_jobs WHERE id = :id
                ");
                $stmt->execute(['id' => $name]);
                $row = $stmt->fetch();

                // Unknown job dir older than 1 hour -> orphaned.
                if (!$row) {
                    $path = $base . '/' . $name;
                    if (is_dir($path) && (time() - @filemtime($path)) > 3600) {
                        $removed += self::rrmdirStatic($path);
                        $log->info("DeepDive orphan sweep removed {$bucket}/{$name} (no job row)");
                    }
                    continue;
                }

                // Finished or failed + debug mode off and cleanup didn't
                // complete: clean it now.
                if (in_array($row['status'], ['completed','failed','cancelled'], true)
                    && !$row['debug_mode']
                    && $row['cleanup_status'] !== 'done') {
                    $path = $base . '/' . $name;
                    $removed += self::rrmdirStatic($path);
                    $log->info("DeepDive orphan sweep removed {$bucket}/{$name} (post-terminal)");
                    $pdo->prepare("UPDATE deepdive_jobs SET cleanup_status='done' WHERE id=:id")
                        ->execute(['id' => $name]);
                }
            }
        }
        return $removed;
    }

    private function rrmdir(string $dir): int
    {
        return self::rrmdirStatic($dir);
    }

    private static function rrmdirStatic(string $dir): int
    {
        if (!is_dir($dir)) return 0;
        $count = 0;
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
            $count++;
        }
        @rmdir($dir);
        return $count;
    }
}
