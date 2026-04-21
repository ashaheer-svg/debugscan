<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

/**
 * Synology rotates logs as *.log.N.xz. DSM bundles include plenty of
 * these. We expand them in place (leaving the .xz alongside for audit).
 * Uses the `xz` CLI if available; falls back to the PHP xz extension
 * if present; otherwise marks the step skipped with a warning.
 */
final class DecompressStep implements StepInterface
{
    public function id(): string { return 'decompress'; }

    public function run(PipelineContext $ctx): void
    {
        $ctx->startStep($this->id());

        $hasXzCli = $this->hasXzCli();
        $hasXzExt = extension_loaded('xz');

        if (!$hasXzCli && !$hasXzExt) {
            $ctx->skipStep($this->id(), 'no xz decoder available (install xz-utils)');
            return;
        }

        $expanded = 0;
        $failed   = 0;

        foreach ($ctx->bag['bundles'] as $bundle) {
            $path = $bundle['extracted_path'] ?? null;
            if (!$path || !is_dir($path)) continue;

            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iter as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) continue;
                if (!preg_match('/\.xz$/i', $file->getFilename())) continue;

                $src = $file->getPathname();
                $dst = preg_replace('/\.xz$/i', '', $src);
                if ($dst === null || $dst === $src) continue;
                if (file_exists($dst)) continue; // already expanded

                $ok = $hasXzCli
                    ? $this->decompressCli($src, $dst)
                    : $this->decompressExt($src, $dst);

                if ($ok) {
                    $expanded++;
                    if ($expanded % 25 === 0) {
                        $ctx->stepDetail($this->id(), "Decompressed {$expanded} file(s)");
                    }
                } else {
                    $failed++;
                }
            }
        }

        $detail = "Decompressed {$expanded} file(s)";
        if ($failed > 0) $detail .= ", {$failed} failed";
        $ctx->stepDetail($this->id(), $detail);
        $ctx->completeStep($this->id());
    }

    private function hasXzCli(): bool
    {
        $out = [];
        $ret = 0;
        @exec('command -v xz 2>/dev/null', $out, $ret);
        return $ret === 0 && !empty($out);
    }

    private function decompressCli(string $src, string $dst): bool
    {
        // `xz -dk` keeps the original and writes .log next to .log.xz.
        // We need to redirect because -dk writes to a deterministic name.
        $cmd = sprintf('xz -dc %s > %s 2>/dev/null',
            escapeshellarg($src),
            escapeshellarg($dst)
        );
        $ret = 0;
        @system($cmd, $ret);
        return $ret === 0 && file_exists($dst);
    }

    private function decompressExt(string $src, string $dst): bool
    {
        // Only called when ext/xz is loaded.
        $in = @xzopen($src, 'rb');
        if (!$in) return false;
        $out = @fopen($dst, 'wb');
        if (!$out) { xzclose($in); return false; }
        while (!xzeof($in)) {
            $buf = xzread($in, 1 << 16);
            if ($buf === false) break;
            fwrite($out, $buf);
        }
        xzclose($in);
        fclose($out);
        return file_exists($dst);
    }
}
