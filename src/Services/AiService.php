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
     * Returns both findings and the full prompt used (for transparency).
     */
    public function analyze(array $diagnosticData, string $model, int $maxTokens): array
    {
        $systemPrompt = $this->getSystemPrompt();
        
        // Assemble User Prompt
        $userPrompt = "SYNOLOGY FORENSIC DIAGNOSTIC REQUEST\n";
        $userPrompt .= "====================================\n\n";
        
        foreach ($diagnosticData as $index => $fileData) {
            $userPrompt .= "### DATA SET " . ($index + 1) . "\n";
            
            // 1. Hardware Identity
            $hw = $fileData['hardware'] ?? [];
            $userPrompt .= "#### DEVICE IDENTITY\n";
            $userPrompt .= sprintf("- Model: %s | Serial: %s | DSM: %s\n", 
                $fileData['version']['model'] ?? 'Unknown',
                $fileData['version']['serial'] ?? 'Unknown',
                $fileData['version']['version'] ?? 'Unknown'
            );
            
            // 2. Physical Layout & RAID
            $userPrompt .= "#### STORAGE & VOLUME ARCHITECTURE\n";
            $userPrompt .= "Pools/Volumes: " . json_encode($fileData['volumes'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            $userPrompt .= "Disk Bay Map: " . json_encode($fileData['disks'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            $userPrompt .= "RAID Config: " . json_encode($fileData['raid'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";

            // 3. Data Integrity & Scrubbing
            if (isset($fileData['btrfs'])) {
                $userPrompt .= "#### DATA INTEGRITY SIGNALS\n";
                $userPrompt .= json_encode($fileData['btrfs'], JSON_PRETTY_PRINT) . "\n\n";
            }

            // 4. Foreman Forensic Signal (SQL Extractions)
            if (isset($fileData['packaged_logs'])) {
                $userPrompt .= "#### FORENSIC EVIDENCE BLOCKS (Primary Signals)\n";
                $userPrompt .= $fileData['packaged_logs']['system'] . "\n\n";
                $userPrompt .= $fileData['packaged_logs']['disk_health'] . "\n\n";
                $userPrompt .= $fileData['packaged_logs']['connections'] . "\n\n";
                $userPrompt .= $fileData['packaged_logs']['disk_ops'] . "\n\n";
            }

            // 5. System Health & Load (Secondary Signals)
            $userPrompt .= "#### SECONDARY DIAGNOSTIC SIGNALS\n";
            $userPrompt .= "Network State: " . json_encode($fileData['network'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            $userPrompt .= "Disk IO & Pressure: " . json_encode($fileData['disk_io'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            $userPrompt .= "System Load: " . json_encode($fileData['system_load'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
            
            if (!isset($fileData['packaged_logs'])) {
                $userPrompt .= "#### RAW TELEMETRY FALLBACK\n" . json_encode($fileData, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
            }
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
                'full_prompt' => $fullPromptString
            ];
        } catch (\Exception $e) {
            throw new RuntimeException("AI Analysis failed: " . $e->getMessage());
        }
    }

    private function getSystemPrompt(): string
    {
        return "You are the Lead Forensic Support Engineer for Synology.
        Your task is to analyze diagnostic 'File Sets' and provide a definitive health audit.

        STRICT RULES:
        1. EVIDENCE REQUIREMENT: Every finding MUST cite identifying logs (Database tags like [SYNOSYSDB] or [SYNOCONNDB]) or specific hardware telemetry.
        2. FORENSIC CORRELATION: Correlate error codes across different blocks (e.g., match a 'Disk I/O' block error with a 'Volume Degraded' system event).
        3. REDUNDANCY CHECK: Distinguish between intermittent cable issues (indicated by PHYRdyChg/BadCRC) and physical media failure (Bad Sectors/UNC).
        4. ACCURACY: If the data shows no critical issues, provide an 'A' grade and explain the healthy indicators.

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
