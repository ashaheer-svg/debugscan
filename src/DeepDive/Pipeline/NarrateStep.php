<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use App\DeepDive\Correlation\Incident;
use App\DeepDive\Narrator\Narrator;

/**
 * For each incident produced by CorrelateStep, asks the narrator to
 * generate a plain-language narrative + recommended_actions and writes
 * them into the deepdive_incidents row.
 *
 * This step is OPTIONAL by design:
 *   - If GROQ_API_KEY is unset, the step is skipped cleanly.
 *   - If any individual incident fails narration, the step still marks
 *     itself complete — the report will fall back to the rule's own
 *     remediation text for the un-narrated incidents.
 *
 * We never let narration failure kill the pipeline. Rule-based findings
 * are the source of truth; AI prose is a layer on top.
 */
final class NarrateStep implements StepInterface
{
    public function id(): string { return 'narrate'; }

    public function run(PipelineContext $ctx): void
    {
        $ctx->startStep($this->id());

        $incidents = $ctx->bag['incidents'] ?? [];
        if (!is_array($incidents) || $incidents === []) {
            $ctx->skipStep($this->id(), 'No incidents to narrate');
            return;
        }

        $apiKey = (string)(getenv('GROQ_API_KEY') ?: ($_ENV['GROQ_API_KEY'] ?? ''));
        if ($apiKey === '') {
            $ctx->skipStep($this->id(), 'GROQ_API_KEY not set — skipping AI narration');
            return;
        }

        $narrator = new Narrator($apiKey, logger: $ctx->logger);
        if (!$narrator->isConfigured()) {
            $ctx->skipStep($this->id(), 'Narrator refused to configure');
            return;
        }

        $ok     = 0;
        $failed = 0;
        $tokens = 0;
        $upd = $ctx->pdo->prepare("
            UPDATE deepdive_incidents
            SET narrative = :narr,
                recommended_actions = :acts::jsonb
            WHERE id = :id AND tenant_id = :tid
        ");

        foreach ($incidents as $inc) {
            if (!$inc instanceof Incident) { $failed++; continue; }
            try {
                $result = $narrator->narrate($inc);
            } catch (\Throwable $e) {
                $ctx->logger->warning('[deepdive.narrate] unexpected: ' . $e->getMessage());
                $failed++;
                continue;
            }
            if ($result === null) { $failed++; continue; }

            $tokens += (int)($result['tokens_used'] ?? 0);

            try {
                $upd->execute([
                    'id'   => $inc->id,
                    'tid'  => $ctx->tenantId,
                    'narr' => $result['narrative'],
                    'acts' => json_encode($result['recommended_actions'], JSON_UNESCAPED_SLASHES),
                ]);
                $ok++;
            } catch (\Throwable $e) {
                $ctx->logger->warning('[deepdive.narrate] db update failed: ' . $e->getMessage(), [
                    'incident_id' => $inc->id,
                ]);
                $failed++;
            }
        }

        // Persist narrator token spend on the job row. Column is nullable
        // in case the migration hasn't been applied yet — swallow errors.
        if ($tokens > 0) {
            try {
                $ctx->pdo->prepare(
                    "UPDATE deepdive_jobs SET narrator_tokens_used = :n WHERE id = :id"
                )->execute(['n' => $tokens, 'id' => $ctx->jobId]);
            } catch (\Throwable $e) {
                $ctx->logger->warning('[deepdive.narrate] token write failed: ' . $e->getMessage());
            }
        }

        $ctx->bag['narrator_tokens_used'] = $tokens;
        $ctx->stepDetail($this->id(), "{$ok} narrated, {$failed} fell back to rule text, {$tokens} tokens");
        $ctx->completeStep($this->id());
    }
}
