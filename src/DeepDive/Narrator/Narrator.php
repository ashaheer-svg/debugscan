<?php

declare(strict_types=1);

namespace App\DeepDive\Narrator;

use App\DeepDive\Correlation\Incident;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Narrator: Generate AI-powered narratives and actions for incidents
 *
 * PURPOSE:
 * Uses LLM (Groq API) to produce human-readable narratives for incidents
 * Generates concrete, actionable remediation steps for each incident
 * Grounds responses in rule metadata and redacted evidence
 *
 * INTEGRATION:
 * Called by NarrateStep during pipeline execution
 * Optional and graceful (soft failures don't block pipeline)
 * Results persisted to deepdive_incidents table
 * Applied as overlay in RenderStep for richer reports
 *
 * SAFETY DESIGN:
 * PII redaction before sending to LLM
 * Only rule metadata and redacted excerpts sent (no raw bundle content)
 * Redaction map maintained for potential de-redaction
 *
 * RESPONSE FORMAT:
 * Expected JSON:
 * {
 *   "narrative": "Human-readable explanation",
 *   "recommended_actions": ["Action 1", "Action 2", ...],
 *   "tokens_used": 1234
 * }
 * Any other format treated as failure (fallback to rule text)
 *
 * MODEL & PROVIDER:
 * Default model: llama-3.3-70b-versatile via Groq API
 * Configurable via constructor
 * API key required (env: GROQ_API_KEY)
 * 30-second timeout per incident
 *
 * ERROR HANDLING:
 * Network failures: Logged, returns null
 * Malformed responses: Logged, returns null
 * Missing API key: isConfigured() returns false, skips narration
 * Per-incident failures don't block other incidents
 *
 * BOUNDS:
 * Max 6 citations per incident (prevent huge prompts)
 * 280-char excerpt limit per citation
 * Prevents token explosion on verbose bundles
 *
 * INDEPENDENCE:
 * DeepDive-private service (not wired into main scan pipeline)
 * Can be ripped out without breaking other systems
 *
 * @package App\DeepDive\Narrator
 */
final class Narrator
{
    private const MODEL_DEFAULT   = 'llama-3.3-70b-versatile';
    private const MAX_CITATIONS   = 6;       // per incident in the prompt
    private const EXCERPT_CHAR_CAP = 280;
    private const RESPONSE_TIMEOUT = 30;

    public function __construct(
        private readonly string          $apiKey,
        private readonly string          $model     = self::MODEL_DEFAULT,
        private readonly ?Client         $client    = null,
        private readonly LoggerInterface $logger    = new NullLogger(),
    ) {}

    public function isConfigured(): bool { return $this->apiKey !== ''; }

    /**
     * Produce (narrative, recommended_actions) for the incident. Returns
     * null on any failure (missing key, timeout, malformed JSON) — callers
     * MUST tolerate null and render the report without AI prose.
     *
     * The returned `tokens_used` is total_tokens from the Groq response,
     * 0 if the provider didn't report usage. NarrateStep sums it across
     * incidents to fill deepdive_jobs.narrator_tokens_used.
     *
     * @return array{narrative:string, recommended_actions:list<string>, redaction_map:array<string,string>, tokens_used:int}|null
     */
    public function narrate(Incident $incident): ?array
    {
        if (!$this->isConfigured()) return null;

        $redactor   = new PiiRedactor();
        $payload    = $this->buildPayload($incident, $redactor);
        $prompt     = $this->buildUserPrompt($payload);
        $system     = $this->systemPrompt();

        try {
            [$raw, $tokens] = $this->callModel($system, $prompt);
        } catch (GuzzleException | \RuntimeException $e) {
            $this->logger->warning('[deepdive.narrator] call failed: ' . $e->getMessage(), [
                'incident_id' => $incident->id,
            ]);
            return null;
        }

        $parsed = $this->parseResponse($raw);
        if ($parsed === null) {
            $this->logger->warning('[deepdive.narrator] response not parseable', [
                'incident_id' => $incident->id,
                'raw_preview' => mb_substr($raw, 0, 240),
            ]);
            return null;
        }

        return [
            'narrative'           => $parsed['narrative'],
            'recommended_actions' => $parsed['recommended_actions'],
            'redaction_map'       => $redactor->mapping(),
            'tokens_used'         => $tokens,
        ];
    }

    /** @return array<string,mixed> */
    private function buildPayload(Incident $inc, PiiRedactor $redactor): array
    {
        $root = $inc->rootCause;
        $findings = [];
        foreach ($inc->findings as $f) {
            $citations = [];
            foreach (array_slice($f->citations, 0, self::MAX_CITATIONS) as $c) {
                $citations[] = [
                    'file'      => basename((string)($c['file'] ?? '')),
                    'line'      => (int)($c['line_number'] ?? 0),
                    'timestamp' => $c['timestamp'] ?? null,
                    'excerpt'   => $redactor->redact(mb_substr((string)($c['excerpt'] ?? ''), 0, self::EXCERPT_CHAR_CAP)),
                ];
            }
            $findings[] = [
                'rule_id'       => $f->ruleId,
                'title'         => $f->title,
                'severity'      => $f->severity,
                'actionability' => $f->actionability,
                'entities'      => $redactor->redactArray($f->entities),
                'citations'     => $citations,
            ];
        }
        return [
            'incident' => [
                'id'             => $inc->id,
                'priority'       => $inc->priority,
                'actionability'  => $inc->actionability,
                'title'          => $inc->title,
                'cause_chain'    => $inc->causeChain,
                'shared_entities'=> $redactor->redactArray($inc->sharedEntities),
                'root_rule_id'   => $root?->ruleId,
            ],
            'findings' => $findings,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<TXT
You are the narrator for a Synology DSM diagnostic engine called DeepDive.
You will receive one CORRELATED INCIDENT consisting of rule-based findings
and their evidence citations. Your job is to produce a short, factual
narrative and a list of concrete actions.

Strict rules:
1. Reply ONLY with a single JSON object in this exact shape:
   {"narrative": "<string>", "recommended_actions": ["<string>", ...]}
2. The narrative is 2–4 plain-language sentences. No marketing tone.
   Reference the evidence factually. If the cause_chain has more than one
   rule, explain the progression from root cause to symptom.
3. recommended_actions is 2–5 imperative one-sentence steps. Order them
   from most important to least. Each action must be concrete and
   verifiable (e.g. "Run 'smartctl -t long /dev/sda'", not "Check the disk").
4. Use only information present in the payload. Do not invent disk models,
   DSM versions, part numbers, or SKUs.
5. Assume the reader is a competent but non-expert NAS administrator.
6. Never include raw IP addresses or hostnames — placeholders like [IP_1]
   are already in the payload and should be preserved as-is.
7. No Markdown fences, no preamble, no postamble — JSON only.
TXT;
    }

    /** @param array<string,mixed> $payload */
    private function buildUserPrompt(array $payload): string
    {
        return "CORRELATED INCIDENT PAYLOAD:\n" .
               json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /** @return array{0:string,1:int} content, total_tokens */
    private function callModel(string $system, string $user): array
    {
        $client = $this->client ?? new Client([
            'base_uri' => 'https://api.groq.com/openai/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => self::RESPONSE_TIMEOUT,
        ]);

        $response = $client->post('chat/completions', [
            'json' => [
                'model'       => $this->model,
                'temperature' => 0.2,
                'max_tokens'  => 900,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
            ],
        ]);

        $body = json_decode((string)$response->getBody(), true);
        if (!is_array($body)) throw new \RuntimeException('Non-JSON response body');
        $content = $body['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || $content === '') {
            throw new \RuntimeException('Empty content in AI response');
        }
        $tokens = (int)($body['usage']['total_tokens'] ?? 0);
        return [$content, $tokens];
    }

    /** @return array{narrative:string, recommended_actions:list<string>}|null */
    private function parseResponse(string $raw): ?array
    {
        // Strip accidental ``` fences that some models emit despite response_format=json_object.
        $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw) ?? $raw;
        $raw = trim($raw);

        $data = json_decode($raw, true);
        if (!is_array($data)) return null;

        $narrative = $data['narrative'] ?? null;
        $actions   = $data['recommended_actions'] ?? null;
        if (!is_string($narrative) || $narrative === '') return null;
        if (!is_array($actions) || $actions === [])    return null;

        $actions = array_values(array_filter(
            array_map(static fn($a) => is_string($a) ? trim($a) : '', $actions),
            static fn(string $a): bool => $a !== ''
        ));
        if ($actions === []) return null;

        // Hard caps so a verbose model can't bloat the report.
        $narrative = mb_substr($narrative, 0, 1200);
        $actions   = array_slice($actions, 0, 5);
        foreach ($actions as $i => $a) $actions[$i] = mb_substr($a, 0, 240);

        return ['narrative' => $narrative, 'recommended_actions' => $actions];
    }
}
