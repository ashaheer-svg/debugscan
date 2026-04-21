<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use App\DeepDive\Parsers\BundleLocator;
use App\DeepDive\Parsers\TimestampParser;
use App\DeepDive\Rules\Sources\SourceRegistry;

/**
 * Walks each extracted bundle, identifies known DSM logical sources
 * (messages, kern.log, scemd, mdstat, df, sqlite artefacts, …) and registers
 * them into a SourceRegistry for the rule evaluator.
 *
 * Every bundle contributes its sources to the *same* registry so rules can
 * address the whole job's evidence uniformly. This matches how a human
 * analyst reads multiple snapshot files side-by-side.
 *
 * Also emits coarse per-bundle `facts` for the report's "bundles processed"
 * section, preserving the Sprint 1 behaviour.
 */
final class ParseStep implements StepInterface
{
    public function id(): string { return 'parse'; }

    public function run(PipelineContext $ctx): void
    {
        $ctx->startStep($this->id());

        $year     = (int)date('Y');
        $locator  = new BundleLocator(new TimestampParser($year));
        $registry = new SourceRegistry();
        $facts    = [];

        foreach ($ctx->bag['bundles'] as $bundle) {
            $base = $bundle['extracted_path'] ?? null;
            if (!$base || !is_dir($base)) continue;

            // Fold this bundle's sources into the shared registry.
            $bundleReg = $locator->locate($base);
            foreach ($bundleReg->allLogs()    as $src) $registry->registerLog($src);
            foreach ($bundleReg->allSqlite()  as $src) $registry->registerSqlite($src);

            $facts[] = [
                'debug_file_id'  => $bundle['debug_file_id'],
                'root'           => basename($base),
                'file_count'     => $this->countFiles($base),
                'size_bytes'     => $this->dirSize($base),
                'top_level'      => $this->topLevel($base),
                'sources_log'    => array_keys($bundleReg->allLogs()),
                'sources_sqlite' => array_keys($bundleReg->allSqlite()),
            ];
        }

        $ctx->bag['facts']           = $facts;
        $ctx->bag['source_registry'] = $registry;

        $detail = sprintf(
            '%d bundle(s), %d log source(s), %d sqlite source(s)',
            count($facts),
            count($registry->allLogs()),
            count($registry->allSqlite()),
        );
        $ctx->stepDetail($this->id(), $detail);
        $ctx->completeStep($this->id());
    }

    private function countFiles(string $dir): int
    {
        $n = 0;
        try {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $f) if ($f->isFile()) $n++;
        } catch (\Throwable) { /* swallow */ }
        return $n;
    }

    private function dirSize(string $dir): int
    {
        $n = 0;
        try {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $f) {
                /** @var \SplFileInfo $f */
                if ($f->isFile()) $n += $f->getSize();
            }
        } catch (\Throwable) { /* swallow */ }
        return $n;
    }

    private function topLevel(string $dir): array
    {
        $items = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $items[] = $name;
            if (count($items) >= 20) break;
        }
        return $items;
    }
}
