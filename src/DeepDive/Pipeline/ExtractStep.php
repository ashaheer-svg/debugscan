<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use App\DeepDive\Support\Paths;

/**
 * Extract each debug bundle into an isolated per-job directory.
 * Does NOT reuse storage/extracted from the existing scan pipeline.
 * Each bundle is extracted under storage/deepdive/extracted/{job_id}/{debug_file_id}/.
 *
 * Hardened against zip-slip: entry names are validated with realpath()
 * before write.
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
