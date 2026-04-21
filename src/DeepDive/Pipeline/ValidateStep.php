<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

final class ValidateStep implements StepInterface
{
    public function id(): string { return 'validate'; }

    public function run(PipelineContext $ctx): void
    {
        $ctx->startStep($this->id());

        // Tenant feature flag — defensive double-check (controller
        // already enforces this, but the worker is a separate process
        // so we re-check here).
        $stmt = $ctx->pdo->prepare("SELECT deepdive_enabled FROM users WHERE id = :id AND role='tenant'");
        $stmt->execute(['id' => $ctx->tenantId]);
        $enabled = (bool)$stmt->fetchColumn();
        if (!$enabled) {
            throw new \RuntimeException('DeepDive is not enabled for this tenant');
        }

        if (empty($ctx->debugFileIds)) {
            throw new \RuntimeException('No debug files selected for DeepDive');
        }

        // Resolve uploaded file paths.
        $placeholders = implode(',', array_fill(0, count($ctx->debugFileIds), '?'));
        $stmt = $ctx->pdo->prepare("
            SELECT id, storage_path
            FROM debug_files
            WHERE id IN ($placeholders) AND tenant_id = ?
        ");
        $params = array_merge($ctx->debugFileIds, [$ctx->tenantId]);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (count($rows) !== count($ctx->debugFileIds)) {
            throw new \RuntimeException('One or more debug files not found for this tenant');
        }

        foreach ($rows as $r) {
            $path = $r['storage_path'] ?? '';
            if (!$path || !file_exists($path)) {
                throw new \RuntimeException("Debug bundle missing on disk: {$r['id']}");
            }
            $ctx->bag['bundles'][] = [
                'debug_file_id'  => $r['id'],
                'upload_path'    => $path,
                'extracted_path' => null,
            ];
        }

        $ctx->stepDetail($this->id(), count($rows) . ' bundle(s) validated');
        $ctx->completeStep($this->id());
    }
}
