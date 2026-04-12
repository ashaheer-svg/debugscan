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
    public function analyze(array $diagnosticData, string $model, int $maxTokens, int $maxChars = 50000): array
    {
        $systemPrompt = $this->getSystemPrompt();
        $isTruncated = false;
        
        // Assemble User Prompt (Full Raw JSON Mode)
        $userPrompt = "SYNOLOGY FORENSIC DIAGNOSTIC REQUEST (FULL SPECTRUM)\n";
        $userPrompt .= "=================================================\n\n";
        
        foreach ($diagnosticData as $index => $fileData) {
            $userPrompt .= "### DATA SET " . ($index + 1) . "\n";
            
            // Generate full RAW JSON for this specific file record
            $rawJson = json_encode($fileData, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
            
            // Implement dynamic character transparency limit
            if (strlen($rawJson) > $maxChars) {
                $isTruncated = true;
                $rawJson = substr($rawJson, 0, $maxChars) . "\n\n[!!! FORENSIC DATA TRUNCATED AT " . number_format($maxChars) . " CHARACTERS TO PRESERVE AI CONTEXT WINDOW !!!]";
            }

            $userPrompt .= "#### RAW DIAGNOSTIC PAYLOAD\n" . $rawJson . "\n\n";
        }

        $fullPromptString = "SYSTEM PROMPT:\n{$systemPrompt}\n\nUSER PROMPT:\n{$userPrompt}";

        try {
            $response = $this->client->post('chat/completions', [
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'max_completion_tokens' => $maxTokens,
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.1,
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            $content = $result['choices'][0]['message']['content'] ?? '{}';
            
            return [
                'findings' => json_decode($content, true),
                'full_prompt' => $fullPromptString,
                'is_truncated' => $isTruncated
            ];
        } catch (\Exception $e) {
            throw new RuntimeException("AI Analysis failed: " . $e->getMessage());
        }
    }

    private function getSystemPrompt(): string
    {
        return "You are the Lead Forensic Support Engineer for Synology.
        Your task is to analyze diagnostic 'File Sets' and provide a definitive health audit.
        You are receiving FULL-SPECTRUM raw diagnostic telemetry, including standard text logs and deep SQLite forensic extractions (located in the 'forensic_extractions' key).

        STRICT RULES:
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
