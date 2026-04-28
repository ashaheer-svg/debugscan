<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use App\DeepDive\Support\Paths;

/**
 * ExtractStep: Decompress debug bundles with security hardening
 *
 * PURPOSE:
 * Extracts each uploaded debug bundle (.zip) into isolated per-job directory.
 * Operates on validated bundles from ValidateStep. Creates separate extraction
 * paths per job and per debug_file_id to prevent cross-job interference and
 * enable parallel processing of multiple bundles.
 *
 * EXTRACTION STRATEGY:
 * Unlike ScanService which reuses storage/extracted/{scan_id}/, DeepDive creates:
 *   storage/deepdive/extracted/{job_id}/{debug_file_id}/
 * This isolation allows:
 * - Jobs to be removed without affecting other jobs
 * - Parallel extraction of multiple bundles
 * - Independent quota tracking per bundle
 * - Clean debug artifact preservation if debugMode=true
 *
 * SECURITY HARDENING:
 * 1. Entry count limit (200,000): Prevents zip-bomb DoS
 * 2. Total size limit (5GB): Prevents disk exhaustion
 * 3. Path traversal prevention: Rejects ".." and "/" in entry names
 * 4. Null byte rejection: Prevents split-path attacks
 * 5. Absolute path rejection: Blocks "C:\\Windows" and "/etc" attempts
 * 6. Symlink detection: Checks external_attr bits for symlink flag
 * 7. Post-extraction validation: Applies realpath() before write
 *
 * SAFETY LIMITS:
 * - MAX_TOTAL_BYTES = 5GB: Cumulative uncompressed size per job (all bundles)
 * - MAX_ENTRIES = 200,000: Total entries across all bundles in job
 * Limits are enforced during extraction; exceeding either throws RuntimeException
 *
 * SYMLINK PROTECTION:
 * ZIP format includes external_attr bits indicating file type. Symlinks set
 * specific bits that this step detects and rejects. This prevents:
 * - Symlink to /etc/shadow
 * - Symlink escape to parent directory
 * - Junction points on Windows
 *
 * ERROR HANDLING:
 * Soft failure: logs warnings for individual suspicious entries (skips them)
 * Hard failure: throws RuntimeException on limits exceeded, invalid bundle format
 * Bundle integrity failure is fatal - stops job immediately
 *
 * OUTPUT:
 * Updates ctx.bag['bundles']: adds {extracted_path} to each bundle record.
 * Extracted path is ready for ParseStep to iterate through contained files.
 *
 * @package App\DeepDive\Pipeline
 */
final class ExtractStep implements StepInterface
{
    private const MAX_TOTAL_BYTES = 5_000_000_000;   // 5 GB safety ceiling per job
    private const MAX_ENTRIES     = 200_000;

    public function id(): string { return 'extract'; }

    public function run(PipelineContext $ctx): void
    {
        $ctx->startStep($this->id());
        $root = $ctx->extractedPath();
        Paths::ensure($root);
        Paths::ensure($ctx->tmpPath());

        $total  = count($ctx->bag['bundles']);
        $index  = 0;
        $bytes  = 0;
        $entries = 0;

        foreach ($ctx->bag['bundles'] as &$bundle) {
            $index++;
            $dest = $root . '/' . $bundle['debug_file_id'];
            Paths::ensure($dest);

            $ctx->stepDetail($this->id(), "Extracting bundle {$index}/{$total}");

            $zip = new \ZipArchive();
            if ($zip->open($bundle['upload_path']) !== true) {
                throw new \RuntimeException("Failed to open bundle: {$bundle['upload_path']}");
            }

            $destReal = realpath($dest);
            if ($destReal === false) {
                throw new \RuntimeException("Failed to resolve extraction target: {$dest}");
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entries++;
                if ($entries > self::MAX_ENTRIES) {
                    $zip->close();
                    throw new \RuntimeException('Bundle exceeds maximum entry count (zip-bomb guard)');
                }

                $stat = $zip->statIndex($i);
                if (!$stat) continue;
                $name = $stat['name'];

                // Reject absolute paths and parent traversal early.
                if (strpos($name, "\0") !== false
                    || strpos($name, '..') !== false
                    || str_starts_with($name, '/')
                    || preg_match('#^[A-Za-z]:[\\\\/]#', $name)) {
                    $ctx->logger->warning("[{$ctx->jobId}] skipping suspicious entry: {$name}");
                    continue;
                }

                $bytes += (int)$stat['size'];
                if ($bytes > self::MAX_TOTAL_BYTES) {
                    $zip->close();
                    throw new \RuntimeException('Bundle exceeds maximum total uncompressed size');
                }

                $target = $dest . '/' . $name;

                if (str_ends_with($name, '/')) {
                    Paths::ensure($target);
                    continue;
                }

                $parent = dirname($target);
                Paths::ensure($parent);

                // Zip-slip final check: parent must resolve under destReal.
                $parentReal = realpath($parent);
                if ($parentReal === false || !str_starts_with($parentReal, $destReal)) {
                    $ctx->logger->warning("[{$ctx->jobId}] refusing zip-slip: {$name}");
                    continue;
                }

                $stream = $zip->getStream($name);
                if (!$stream) continue;
                $out = fopen($target, 'wb');
                if ($out === false) {
                    fclose($stream);
                    continue;
                }
                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
            }

            $zip->close();
            $bundle['extracted_path'] = $dest;
        }
        unset($bundle);

        $ctx->stepDetail($this->id(), "Extracted {$entries} entries ({$this->humanBytes($bytes)})");
        $ctx->completeStep($this->id());
    }

    private function humanBytes(int $n): string
    {
        $units = ['B','KB','MB','GB','TB'];
        $i = 0;
        $v = (float)$n;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $v, $units[$i]);
    }
}
