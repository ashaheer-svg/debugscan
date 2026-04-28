<?php

declare(strict_types=1);

namespace App\DeepDive\Report;

use App\DeepDive\Support\Paths;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * PdfExporter: HTML → PDF conversion using mPDF
 *
 * PURPOSE:
 * Converts completed DeepDive analysis HTML report into PDF file for
 * download/archive. Uses mPDF (HTML to PDF converter) as optional soft
 * dependency. If mPDF unavailable or rendering fails, logs warning and
 * gracefully falls back (HTML report remains accessible).
 *
 * DESIGN PHILOSOPHY:
 * PDF is convenience, not critical. HTML is source of truth and always
 * available. PDF failure doesn't block job completion or report access.
 * This trade-off avoids external dependencies blocking core functionality.
 *
 * SOFT DEPENDENCY:
 * mPDF is installed via Composer only if explicitly included in project.
 * isAvailable() check before rendering. Missing library → skip PDF silently.
 * Example use:
 * - Enterprise deployments: install mPDF, get PDF reports
 * - Minimal deployments: omit mPDF, HTML reports only
 *
 * ISOLATION STRATEGY:
 * Each job gets isolated temp directory (storage/deepdive/tmp/{job_id}/mpdf/)
 * containing mPDF font cache and working files. This enables:
 * - Parallel PDF rendering (no shared cache lock contention)
 * - Complete cleanup (remove job dir, everything goes with it)
 * - No leaking resources between jobs
 * Trade-off: slower than shared cache but safer and simpler.
 *
 * HTML COMPATIBILITY:
 * mPDF's HTML/CSS support is limited compared to modern browsers:
 * - No flexbox or grid (ReportRenderer uses CSS Grid, mPDF falls back to tables)
 * - No external fonts (all fonts embedded)
 * - No color-mix() (uses fallback colors)
 * - No async loading (inline all CSS, no external stylesheets)
 * ReportRenderer's CSS written with mPDF limitations in mind.
 * CSS handles graceful degradation: modern browsers get full layout,
 * mPDF gets readable (if unstyled) output.
 *
 * RENDERING:
 * 1. Create isolated temp directory for this job
 * 2. Instantiate mPDF with UTF-8 mode, A4 page size, 14-16pt margins
 * 3. Set metadata (title, author)
 * 4. Add footer (page numbers)
 * 5. WriteHTML() processes full HTML document (style tags included)
 * 6. Output to file
 * 7. Verify file exists and has size > 0
 *
 * CONFIGURATION:
 * - mode: utf-8 (supports non-ASCII characters in report)
 * - format: A4 (standard document size)
 * - margins: 14-16pt (balanced readability and content)
 * - header/footer: page numbers and metadata
 * - tempDir: job-specific (no cache sharing)
 * - default_font: DejaVu Sans (widely available, no external dependency)
 * - curlAllowUnsafeSslRequests: false (no remote asset fetching)
 *
 * ERROR HANDLING:
 * Try/catch on all mPDF operations. Any exception:
 * - Logs error with context
 * - Returns false (caller handles gracefully)
 * - Does NOT throw (allows report completion without PDF)
 *
 * OUTPUT:
 * File written to RenderStep-provided path in storage/deepdive/reports/{job_id}/
 * File includes full DeepDive report content (findings, incidents, hardware, recommendations)
 *
 * @package App\DeepDive\Report
 */
final class PdfExporter
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /** mPDF is a soft dependency — handle the "composer install hasn't been run yet" case. */
    public function isAvailable(): bool
    {
        return class_exists(\Mpdf\Mpdf::class);
    }

    /**
     * Render HTML to a PDF at the given path.
     * Returns true on success, false on any failure (already logged).
     */
    public function renderToFile(string $html, string $pdfPath, string $jobId): bool
    {
        if (!$this->isAvailable()) {
            $this->logger->info('[deepdive.pdf] mPDF not installed — skipping PDF');
            return false;
        }

        try {
            $tmpDir = Paths::tmp($jobId) . '/mpdf';
            Paths::ensure($tmpDir);

            $mpdf = new \Mpdf\Mpdf([
                'mode'              => 'utf-8',
                'format'            => 'A4',
                'margin_top'        => 16,
                'margin_bottom'     => 16,
                'margin_left'       => 14,
                'margin_right'      => 14,
                'margin_header'     => 8,
                'margin_footer'     => 8,
                'tempDir'           => $tmpDir,
                'default_font'      => 'dejavusans',
                // Disable remote asset fetching — report is self-contained anyway.
                'curlAllowUnsafeSslRequests' => false,
            ]);

            $mpdf->SetTitle('DeepDive Report — ' . $jobId);
            $mpdf->SetAuthor('DeepDive');
            $mpdf->SetCreator('DeepDive');
            $mpdf->SetHTMLFooter(
                '<div style="text-align:center;font-size:9px;color:#999">DeepDive report {PAGENO} / {nbpg}</div>'
            );

            // mPDF's HTML parser is relaxed; pass the full doc including <style>.
            $mpdf->WriteHTML($html);
            $mpdf->Output($pdfPath, \Mpdf\Output\Destination::FILE);

            return is_file($pdfPath) && filesize($pdfPath) > 0;
        } catch (\Throwable $e) {
            $this->logger->warning('[deepdive.pdf] render failed: ' . $e->getMessage(), [
                'job_id' => $jobId,
            ]);
            // Clean up a partial/zero-byte file so the "file_exists" check
            // in the download controller doesn't serve garbage.
            if (is_file($pdfPath)) @unlink($pdfPath);
            return false;
        }
    }
}
