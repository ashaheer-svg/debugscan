<?php

declare(strict_types=1);

namespace App\DeepDive\Report;

use App\DeepDive\Support\Paths;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Thin mPDF adapter. Converts a completed DeepDive HTML report into a PDF.
 *
 * Design:
 *   - mPDF is optional. If the vendor class is missing or throws, we log a
 *     warning and return false. The HTML report is always the source of
 *     truth; PDF is a convenience.
 *   - All per-job state (tmp + font cache) lives under storage/deepdive/tmp
 *     so it's cleaned up with the rest of the job working set. No shared
 *     cache between runs — we prioritised isolation over speed here.
 *   - We feed mPDF the HTML as-is. The ReportRenderer's CSS was written
 *     with that in mind: no external fonts, no flex layouts that mPDF
 *     can't handle, no color-mix() outside the priority chips (those
 *     degrade to solid fill in PDF, which is fine).
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
