<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use RuntimeException;

class AiService
{
    private Client $client;
    private ?string $apiKey;
    private string $baseUrl = 'https://api.groq.com/openai/v1/';

    public function __construct(?string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Authorization' => "Bearer " . ($this->apiKey ?? ''),
                'Content-Type' => 'application/json',
            ],
            'timeout' => 60.0,
        ]);
    }

    /**
     * Fetch all available models from the Groq API.
     */
    public function getAvailableModels(): array
    {
        try {
            $response = $this->client->get('models');
            $data = json_decode($response->getBody()->getContents(), true);
            
            $models = [];
            foreach ($data['data'] ?? [] as $model) {
                if ($model['active'] ?? true) {
                    $models[] = [
                        'id' => $model['id'],
                        'name' => $model['id'], // Groq doesn't provide a "friendly" name in /models
                        'owned_by' => $model['owned_by'] ?? 'unknown',
                    ];
                }
            }
            return $models;
        } catch (\Exception $e) {
            throw new RuntimeException("Failed to fetch Groq models: " . $e->getMessage());
        }
    }

    /**
     * Send diagnostic data to Groq for analysis.
     * Returns findings, the full prompt used, and truncation status.
     */
    /**
     * Send diagnostic data to Groq for analysis.
     * Returns findings, the full prompt used, and truncation status.
     * @param string|null $customPrompt Optional system prompt header from the report plan.
     */
    public function analyze(array $diagnosticData, string $model, int $maxTokens, int $maxChars = 50000, string $jobId = 'unknown', ?string $customPrompt = null): array
    {
        $baseRules = $this->getForensicRules();
        $systemPrompt = (!empty($customPrompt) ? $customPrompt : $this->getDefaultHeader()) . "\n\n" . $baseRules;
        $isTruncated = false;
        
        // Assemble User Prompt (Hybrid Mode: JSON + Markdown Tables)
        $userPrompt = "SYNOLOGY FORENSIC DIAGNOSTIC REQUEST (FULL SPECTRUM)\n";
        $userPrompt .= "=================================================\n\n";
        
        foreach ($diagnosticData as $index => $fileData) {
            $userPrompt .= "### DATA SET " . ($index + 1) . "\n";
            
            // Device Identity Header (Forensic Identity Transparency)
            $userPrompt .= sprintf("- Device Identity: Model %s | Serial %s | DSM %s\n\n",
                $fileData['hardware']['model'] ?? 'Unknown',
                $fileData['hardware']['serial'] ?? 'Unknown',
                $fileData['version']['product'] ?? 'Unknown'
            );
            
            // OPTIMIZATION: Flatten dense tables to Markdown to save tokens/avoid 413
            if (isset($fileData['logs']['critical_events'])) {
                $fileData['logs']['critical_events'] = $this->flattenLogsToMarkdown($fileData['logs']['critical_events']);
            }
            if (isset($fileData['disks'])) {
                $fileData['disks'] = $this->flattenDisksToMarkdown($fileData['disks']);
            }
            if (isset($fileData['packaged_logs'])) {
                foreach ($fileData['packaged_logs'] as $logType => $content) {
                    // Packaged logs are already string-based, no changes needed
                }
            }
            
            // Generate full RAW JSON for this optimized record
            $rawJson = json_encode($fileData, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
            
            // Standard safety: Groq 413 gateway limits are often around 100k-200k total request size.
            // We enforce a strict per-file limit here.
            if (mb_strlen($rawJson) > $maxChars) {
                $isTruncated = true;
                $rawJson = mb_substr($rawJson, 0, $maxChars) . "\n\n[!!! DATA TRUNCATED: RECORD EXCEEDS SAFETY LIMIT (" . number_format($maxChars) . " chars) !!!]";
            }

            $userPrompt .= "#### RAW DIAGNOSTIC PAYLOAD\n" . $rawJson . "\n\n";
        }

        $fullPromptString = "SYSTEM PROMPT:\n{$systemPrompt}\n\nUSER PROMPT:\n{$userPrompt}";

        // Pre-Flight Local Audit (Persistent even if API fails/413s)
        $logDir = __DIR__ . '/../../storage/logs';
        if (is_dir($logDir)) {
            @file_put_contents($logDir . '/ai_prompt_' . basename((string)$jobId) . '.txt', $fullPromptString);
        }

        try {
            $response = $this->client->post('chat/completions', [
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'max_completion_tokens' => min($maxTokens, 32768),
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.1,
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            $content = $result['choices'][0]['message']['content'] ?? '{}';
            $usage = $result['usage'] ?? [];
            
            return [
                'findings' => json_decode($content, true) ?: [],
                'full_prompt' => $fullPromptString,
                'is_truncated' => $isTruncated,
                'usage' => $usage
            ];
        } catch (\Exception $e) {
            throw new RuntimeException("AI Analysis failed: " . $e->getMessage());
        }
    }

    private function flattenLogsToMarkdown(array $events): string
    {
        if (empty($events)) return "No critical events found.";
        
        $md = "| Source | Timestamp/Message |\n";
        $md .= "| :--- | :--- |\n";
        foreach (array_slice($events, 0, 100) as $event) {
            $source = $event['source'] ?? 'unknown';
            $content = str_replace(["\n", "\r", "|"], [" ", "", "\\|"], $event['content'] ?? '');
            $md .= "| {$source} | {$content} |\n";
        }
        
        if (count($events) > 100) {
            $md .= "| ... | (Truncated " . (count($events) - 100) . " additional events) |\n";
        }
        
        return $md;
    }

    private function flattenDisksToMarkdown(array $disks): string
    {
        if (empty($disks)) return "No disk telemetry detected.";
        
        $md = "| Bay | Model | Status | Temp | Size | Errors (Reset/UNC) |\n";
        $md .= "| :--- | :--- | :--- | :--- | :--- | :--- |\n";
        foreach ($disks as $id => $d) {
            $bay = $d['bay'] ?? ($d['slot'] ?? $id);
            $model = $d['model'] ?? 'Unknown';
            $status = strtoupper($d['status'] ?? 'None');
            $temp = ($d['temp'] ?? '??') . 'C';
            $size = ($d['size_gb'] ?? '0') . 'GB';
            $rFail = $d['reset_fail_status'] ?? 'normal';
            $unc = $d['unc_status'] ?? 'normal';
            
            $md .= "| {$bay} | {$model} | {$status} | {$temp} | {$size} | R:{$rFail} / U:{$unc} |\n";
        }
        return $md;
    }

    private function getDefaultHeader(): string
    {
        return "You are the Lead Forensic Support Engineer for Synology.
        Your task is to analyze diagnostic 'File Sets' and provide a definitive health audit.
        You are receiving FULL-SPECTRUM raw diagnostic telemetry, including standard text logs and deep SQLite forensic extractions (located in the 'forensic_extractions' key).";
    }

    private function getForensicRules(): string
    {
        return "STRICT RULES:
        1. PRECISION: Analyze raw forensic telemetry from SQLite extractions (e.g. SYNOSYSDB, SYNODISKHEALTHDB) as the ground truth for system health.
        2. EVIDENCE REQUIREMENT: Every finding MUST cite identifying logs, telemetry keys, or hardware identifiers found in the raw JSON payload.
        3. FORENSIC CORRELATION: Correlate error codes across different blocks (e.g., match a 'Disk I/O' block error with a 'Volume Degraded' signal in the forensic events).
        4. REDUNDANCY CHECK: Distinguish between intermittent infrastructure issues and physical media failure using the lifetime counters in the forensic disk health telemetry.
        5. ACCURACY: If the data shows no critical issues, provide an 'A' grade and explain the healthy indicators.

        OUTPUT FORMAT (Strict JSON):
        {
            \"health_score\": \"A|B|C|D|F\",
            \"summary\": \"Executive overview of system health and critical risks.\",
            \"findings\": [
                {
                    \"category\": \"Hardware|Storage|Security|System\",
                    \"severity\": \"critical|warning|info|ok\",
                    \"title\": \"Finding headline\",
                    \"description\": \"In-depth technical analysis of the issue.\",
                    \"recommendation\": \"Specific, actionable remediation steps.\",
                    \"evidence\": {
                        \"source\": \"Diagnostic Block Name\",
                        \"timestamp\": \"Relevant event time\",
                        \"raw_fragment\": \"Extracted log text or telemetry value\"
                    }
                }
            ]
        }";
    }
}
