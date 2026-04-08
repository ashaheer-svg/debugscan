<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use RuntimeException;

class AiService
{
    private Client $client;
    private string $apiKey;
    private string $baseUrl = 'https://api.groq.com/openai/v1/';

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
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
     */
    public function analyze(array $diagnosticData, string $model, int $maxTokens): array
    {
        $systemPrompt = $this->getSystemPrompt();
        $userPrompt = "Analyze the following Synology diagnostic data and provide a forensic report in strict JSON format:\n\n" . json_encode($diagnosticData, JSON_PRETTY_PRINT);

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
            
            return json_decode($content, true);
        } catch (\Exception $e) {
            throw new RuntimeException("AI Analysis failed: " . $e->getMessage());
        }
    }

    private function getSystemPrompt(): string
    {
        // This is a condensed version of the prompt in Spec.md
        return "You are an expert Synology Forensic Support Engineer. 
        Analyze the provided diagnostic JSON to identify hardware failures, RAID issues, storage bottlenecks, and system errors.
        Output MUST be a JSON object with the following structure:
        {
            \"health_score\": \"A-F\",
            \"summary\": \"Brief overview of the system status\",
            \"findings\": [
                {
                    \"category\": \"Hardware|Storage|System|Network\",
                    \"severity\": \"critical|warning|info|ok\",
                    \"title\": \"Finding title\",
                    \"description\": \"Detailed explanation\",
                    \"recommendation\": \"Actionable steps to resolve\",
                    \"evidence\": {}
                }
            ]
        }";
    }
}
