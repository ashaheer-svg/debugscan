<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

/**
 * Validate Step: Verify job preconditions and locate debug bundles
 *
 * PURPOSE:
 * Validates all preconditions before processing can begin:
 * - Tenant has DeepDive feature enabled
 * - Debug files are specified for analysis
 * - All debug files exist and are accessible
 * - Tenant owns the debug files (security check)
 *
 * SEQUENCE:
 * Executed as step 2 in pipeline (after decompression setup, before parsing)
 *
 * VALIDATIONS:
 * 1. Feature flag check: DeepDive enabled for tenant
 * 2. Selection check: At least one debug file provided
 * 3. Database check: All specified files exist in database
 * 4. Ownership check: All files belong to tenant (RLS enforced)
 * 5. Disk check: All files exist on disk at expected path
 *
 * CONTEXT UPDATES:
 * Adds $ctx->bag['bundles'] array with:
 *   - debug_file_id: UUID of file from database
 *   - upload_path: Current path to uploaded bundle
 *   - extracted_path: Will be set by DecompressStep
 *
 * FAILURES (all throw exceptions):
 * - Feature not enabled
 * - No debug files selected
 * - File not found in database
 * - File not found on disk
 * - Ownership mismatch
 *
 * DEFENSIVE PROGRAMMING:
 * Feature flag is double-checked here even though controller enforces it.
 * Worker process may run asynchronously, so this step ensures tenant
 * didn't disable feature between job submission and execution.
 *
 * @package App\DeepDive\Pipeline
 */
final class ValidateStep implements StepInterface
{
    /**
     * Get step identifier
     *
     * @return string 'validate'
     */
    public function id(): string
    {
        return 'validate';
    }

    /**
     * Validate job preconditions and locate all debug bundles
     *
     * FLOW:
     * 1. Record step start in context (for progress tracking)
     * 2. Check feature flag is enabled for tenant
     * 3. Verify at least one debug file is selected
     * 4. Query database for all specified files
     * 5. Verify all requested files found (no missing files)
     * 6. For each file: verify it exists on disk
     * 7. Build bundles array with paths
     * 8. Record step completion
     *
     * DATABASE QUERIES:
     * - users table: Check deepdive_enabled flag
     * - debug_files table: Resolve file paths and verify ownership
     *
     * @param PipelineContext $ctx Shared pipeline context
     *
     * @return void Updates context->bag['bundles'], throws on error
     *
     * @throws RuntimeException For any validation failure (all failures are fatal)
     */
    public function run(PipelineContext $ctx): void
    {
        // Record step start for progress tracking
        $ctx->startStep($this->id());

        // === CHECK 1: Tenant feature enabled ===
        // Defensive: controller already checks this, but worker is separate process
        // so re-verify tenant hasn't disabled feature between job submit and execution
        $stmt = $ctx->pdo->prepare("SELECT deepdive_enabled FROM users WHERE id = :id AND role='tenant'");
        $stmt->execute(['id' => $ctx->tenantId]);
        $enabled = (bool)$stmt->fetchColumn();
        if (!$enabled) {
            throw new \RuntimeException('DeepDive is not enabled for this tenant');
        }

        // === CHECK 2: Debug files selected ===
        if (empty($ctx->debugFileIds)) {
            throw new \RuntimeException('No debug files selected for DeepDive');
        }

        // === CHECK 3 & 4: Resolve uploaded file paths and verify ownership ===
        // Build parameterized query for IN clause (one ? per file ID, plus tenant_id)
        $placeholders = implode(',', array_fill(0, count($ctx->debugFileIds), '?'));
        $stmt = $ctx->pdo->prepare("
            SELECT id, storage_path
            FROM debug_files
            WHERE id IN ($placeholders) AND tenant_id = ?
        ");
        // Parameters: all file IDs followed by tenant_id
        $params = array_merge($ctx->debugFileIds, [$ctx->tenantId]);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Verify count matches: if not, some files don't exist or don't belong to tenant
        if (count($rows) !== count($ctx->debugFileIds)) {
            throw new \RuntimeException('One or more debug files not found for this tenant');
        }

        // === CHECK 5: Verify files exist on disk ===
        // For each file from database, verify physical file exists
        foreach ($rows as $r) {
            $path = $r['storage_path'] ?? '';
            if (!$path || !file_exists($path)) {
                throw new \RuntimeException("Debug bundle missing on disk: {$r['id']}");
            }

            // Add to bundles array for downstream steps to process
            $ctx->bag['bundles'][] = [
                'debug_file_id'  => $r['id'],              // UUID for database tracking
                'upload_path'    => $path,                 // Current file path on disk
                'extracted_path' => null,                  // Will be set by DecompressStep
            ];
        }

        // Record validation success with bundle count
        $ctx->stepDetail($this->id(), count($rows) . ' bundle(s) validated');
        $ctx->completeStep($this->id());
    }
}
