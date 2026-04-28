<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

/**
 * Decompress Step: Expand XZ-compressed logs from debug bundles
 *
 * PURPOSE:
 * Synology rotates system logs as *.log.N.xz compressed archives.
 * Debug bundles contain many of these. This step expands them in place
 * for analysis by downstream parsers.
 *
 * FILE FORMAT:
 * - Synology logs: *.log, *.log.0, *.log.1, etc. (uncompressed)
 * - Rotated archives: *.log.0.xz, *.log.1.xz, etc. (compressed)
 * - Compression: XZ format (LZMA2 compression)
 *
 * PROCESS:
 * 1. Recursively scan all bundles for *.xz files
 * 2. For each .xz file: decompress to same directory (removing .xz)
 * 3. Leave original .xz files intact (audit trail)
 * 4. Skip if already decompressed (idempotent)
 * 5. Count successes and failures
 *
 * DECOMPRESSION METHODS (priority order):
 * 1. xz CLI command: `/usr/bin/xz -dc src > dst` (preferred, external tool)
 * 2. PHP xz extension: xzopen/xzread/xzclose (fallback, built-in)
 * 3. Skip if neither available (soft skip, warning)
 *
 * REQUIREMENTS:
 * - One of: `xz` CLI tool OR PHP xz extension
 * - Typically available on Linux/Mac, not Windows
 * - xz-utils package on Linux provides CLI tool
 *
 * SOFT SKIP CONDITION:
 * If neither xz CLI nor PHP extension available
 * Pipeline continues but logs remain compressed (parsers may handle .xz)
 *
 * @package App\DeepDive\Pipeline
 */
final class DecompressStep implements StepInterface
{
    /**
     * Get step identifier
     *
     * @return string 'decompress'
     */
    public function id(): string
    {
        return 'decompress';
    }

    /**
     * Decompress all *.xz files in debug bundles
     *
     * FLOW:
     * 1. Check for xz availability (CLI or PHP extension)
     * 2. If neither available: soft skip with warning
     * 3. Scan all bundles for .xz files
     * 4. For each: decompress using best available method
     * 5. Track successes and failures
     * 6. Report results
     *
     * IDEMPOTENCY:
     * - Skips already decompressed files (checks if destination exists)
     * - Safe to re-run without duplicate decompression
     *
     * @param PipelineContext $ctx Shared pipeline context
     *
     * @return void Updates context with decompression results
     */
    public function run(PipelineContext $ctx): void
    {
        $ctx->startStep($this->id());

        // === Check for XZ decompression capability ===
        $hasXzCli = $this->hasXzCli();
        $hasXzExt = extension_loaded('xz');

        // Soft skip if neither CLI nor extension available
        if (!$hasXzCli && !$hasXzExt) {
            $ctx->skipStep($this->id(), 'no xz decoder available (install xz-utils)');
            return;
        }

        $expanded = 0;
        $failed   = 0;

        // === Scan all bundles for .xz files ===
        foreach ($ctx->bag['bundles'] as $bundle) {
            // Get extracted bundle path (set by DecompressStep after archive extraction)
            $path = $bundle['extracted_path'] ?? null;
            if (!$path || !is_dir($path)) continue;

            // Recursively scan directory tree for .xz files
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iter as $file) {
                /** @var \SplFileInfo $file */
                // Only process regular files
                if (!$file->isFile()) continue;
                // Only process .xz files (case-insensitive)
                if (!preg_match('/\.xz$/i', $file->getFilename())) continue;

                // Determine source (.xz) and destination (without .xz) paths
                $src = $file->getPathname();
                $dst = preg_replace('/\.xz$/i', '', $src);
                if ($dst === null || $dst === $src) continue;

                // Skip if already decompressed (idempotent)
                if (file_exists($dst)) continue;

                // === Decompress using best available method ===
                $ok = $hasXzCli
                    ? $this->decompressCli($src, $dst)
                    : $this->decompressExt($src, $dst);

                if ($ok) {
                    $expanded++;
                    // Update progress every 25 files for long decompression runs
                    if ($expanded % 25 === 0) {
                        $ctx->stepDetail($this->id(), "Decompressed {$expanded} file(s)");
                    }
                } else {
                    // Track failed decompressions
                    $failed++;
                }
            }
        }

        // === Report results ===
        $detail = "Decompressed {$expanded} file(s)";
        if ($failed > 0) $detail .= ", {$failed} failed";
        $ctx->stepDetail($this->id(), $detail);
        $ctx->completeStep($this->id());
    }

    /**
     * Internal helper: Check if xz CLI tool is available
     *
     * DETECTION:
     * Uses `command -v xz` shell command to locate xz binary
     * Returns true if command succeeds (exit code 0) and has output
     *
     * @return bool True if xz CLI tool is installed and callable
     */
    private function hasXzCli(): bool
    {
        $out = [];
        $ret = 0;
        // Use POSIX command -v to check if xz exists in PATH
        @exec('command -v xz 2>/dev/null', $out, $ret);
        return $ret === 0 && !empty($out);
    }

    /**
     * Internal helper: Decompress using xz CLI tool
     *
     * COMMAND:
     * `xz -dc src > dst`
     *   -d: Decompress
     *   -c: Write to stdout
     *   > dst: Redirect stdout to destination file
     *
     * ADVANTAGES:
     * - Preferred method (uses native system command)
     * - Handles all edge cases of xz format
     * - Leaves original .xz file intact (-c flag)
     *
     * @param string $src Source .xz file path
     * @param string $dst Destination uncompressed file path
     *
     * @return bool True if decompression succeeded and file exists
     */
    private function decompressCli(string $src, string $dst): bool
    {
        // Use shell redirection to decompress: xz -dc src > dst
        // escapeshellarg escapes argument for safe shell execution
        $cmd = sprintf('xz -dc %s > %s 2>/dev/null',
            escapeshellarg($src),
            escapeshellarg($dst)
        );
        $ret = 0;
        @system($cmd, $ret);
        // Verify decompression succeeded and destination file exists
        return $ret === 0 && file_exists($dst);
    }

    /**
     * Internal helper: Decompress using PHP xz extension
     *
     * FALLBACK METHOD:
     * Used only if xz CLI not available but PHP xz extension is loaded
     *
     * ALGORITHM:
     * 1. Open source .xz file with xzopen()
     * 2. Open destination file for writing
     * 3. Read from compressed file in 64KB chunks
     * 4. Write decompressed data to destination
     * 5. Close both files
     * 6. Verify destination exists
     *
     * ERROR HANDLING:
     * Returns false on any error (missing extension, permission, etc.)
     *
     * @param string $src Source .xz file path
     * @param string $dst Destination uncompressed file path
     *
     * @return bool True if decompression succeeded and file exists
     */
    private function decompressExt(string $src, string $dst): bool
    {
        // Open .xz file for reading
        $in = @xzopen($src, 'rb');
        if (!$in) return false;

        // Open destination for writing
        $out = @fopen($dst, 'wb');
        if (!$out) {
            xzclose($in);
            return false;
        }

        // Decompress in 64KB chunks (1 << 16 = 65536 bytes)
        while (!xzeof($in)) {
            $buf = xzread($in, 1 << 16); // Read 64KB at a time
            if ($buf === false) break;
            fwrite($out, $buf);
        }

        // Clean up file handles
        xzclose($in);
        fclose($out);

        // Verify destination file was created successfully
        return file_exists($dst);
    }
}
