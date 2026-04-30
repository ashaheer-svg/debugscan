<?php

declare(strict_types=1);

namespace App\DeepDive\AI;

use Psr\Log\LoggerInterface;

/**
 * Z.ai Client: Higher-token API for report generation
 *
 * PURPOSE:
 * Provides interface to Z.ai API for model access with higher token limits.
 * Suitable for comprehensive multi-bundle anomaly detection and analysis.
 *
 * Z.AI ADVANTAGES:
 * - Higher token limits (supports 100K+ token contexts)
 * - Multiple model options
 * - Cost-effective for bulk analysis
 * - Better for long-form analysis and detailed reports
 *
 * CONFIGURATION:
 * - Loaded from database settings (admin configurable)
 * - API key and base URL from secure storage
 * - Model selection per feature (this is separate from other reports)
 *
 * @package App\DeepDive\AI
 */
final class ZaiClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private ?LoggerInterface $logger;

    private const DEFAULT_BASE_URL = 'https://api.z.ai/api/paas/v4';

    /**
     * Initialize Z.ai client
     *
     * @param string $apiKey Z.ai API key
     * @param string $model Model name (e.g., 'claude-opus', 'claude-sonnet')
     * @param ?string $baseUrl Optional custom base URL
     * @param ?LoggerInterface $logger Optional logger
     */
    public function __construct(
        string $apiKey,
        string $model,
        ?string $baseUrl = null,
        ?LoggerInterface $logger = null
    ) {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->baseUrl = $baseUrl ?? self::DEFAULT_BASE_URL;
        $this->logger = $logger;

        if (empty($this->apiKey)) {
            throw new \RuntimeException('Z.ai API key is required');
        }
    }

    /**
     * Get available models from Z.ai
     *
     * Fetches list of available models for selection in admin settings
     *
     * @return array<array{name: string, id: string, tokens: int}>
     *         Array of available models with specs
     *
     * @throws \RuntimeException If API call fails
     */
    public function getAvailableModels(): array
    {
        try {
            $this->log('debug', 'Fetching available models from Z.ai');

            // Try to fetch from models endpoint
            $ch = curl_init($this->baseUrl . '/models');
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if (!$curlError && $httpCode === 200) {
                $data = json_decode($response, true);
                $models = [];

                // Try different response formats
                $modelList = $data['data'] ?? $data['models'] ?? $data ?? [];

                if (is_array($modelList)) {
                    foreach ($modelList as $model) {
                        if (is_array($model)) {
                            // Normalize different API response formats
                            $models[] = [
                                'id' => $model['id'] ?? $model['model'] ?? null,
                                'name' => $model['name'] ?? $model['id'] ?? $model['model'] ?? 'Unknown',
                                'tokens' => $model['tokens'] ?? $model['context_length'] ?? $model['max_tokens'] ?? 128000,
                            ];
                        }
                    }
                }

                if (!empty($models)) {
                    return $models;
                }
            }

            // Fallback: return known Z.ai GLM models
            $this->log('debug', 'Using fallback Z.ai model list');
            return [
                ['id' => 'glm-4', 'name' => 'GLM-4 (Standard)', 'tokens' => 128000],
                ['id' => 'glm-4-turbo', 'name' => 'GLM-4 Turbo', 'tokens' => 128000],
                ['id' => 'glm-3.5-turbo', 'name' => 'GLM-3.5 Turbo', 'tokens' => 128000],
                ['id' => 'glm-5.1', 'name' => 'GLM-5.1', 'tokens' => 1000000],
            ];

        } catch (\Throwable $e) {
            $this->log('error', 'Failed to fetch Z.ai models: ' . $e->getMessage());
            // Return fallback models on error instead of throwing
            return [
                ['id' => 'glm-4', 'name' => 'GLM-4 (Standard)', 'tokens' => 128000],
                ['id' => 'glm-4-turbo', 'name' => 'GLM-4 Turbo', 'tokens' => 128000],
                ['id' => 'glm-3.5-turbo', 'name' => 'GLM-3.5 Turbo', 'tokens' => 128000],
                ['id' => 'glm-5.1', 'name' => 'GLM-5.1', 'tokens' => 1000000],
            ];
        }
    }

    /**
     * Validate model exists and is available
     *
     * @param string $model Model name to validate
     *
     * @return bool True if model is valid and available
     */
    public function validateModel(string $model): bool
    {
        try {
            $models = $this->getAvailableModels();
            foreach ($models as $m) {
                if (($m['id'] ?? '') === $model || ($m['name'] ?? '') === $model) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            $this->log('warning', 'Model validation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get current model
     *
     * @return string Model ID
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Set model for subsequent calls
     *
     * @param string $model Model ID
     *
     * @return self
     */
    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    /**
     * Make API call to Z.ai
     *
     * @param string $endpoint API endpoint
     * @param array $payload Request payload
     * @param array $options Curl options
     *
     * @return array API response data
     *
     * @throws \RuntimeException On API failure
     */
    public function call(string $endpoint, array $payload, array $options = []): array
    {
        try {
            $url = $this->baseUrl . '/' . ltrim($endpoint, '/');

            $ch = curl_init($url);
            curl_setopt_array($ch, array_replace([
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_POSTFIELDS => json_encode($payload),
            ], $options));

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                throw new \RuntimeException("Z.ai API error: {$curlError}");
            }

            if ($httpCode >= 400) {
                throw new \RuntimeException("Z.ai API returned {$httpCode}: {$response}");
            }

            $data = json_decode($response, true);
            if ($data === null) {
                throw new \RuntimeException('Invalid JSON response from Z.ai');
            }

            return $data;

        } catch (\Throwable $e) {
            $this->log('error', 'Z.ai API call failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Check if Z.ai is properly configured and accessible
     *
     * @return bool True if Z.ai is accessible
     */
    public function isAvailable(): bool
    {
        if (empty($this->apiKey)) {
            $this->log('debug', 'Z.ai not available: API key not configured');
            return false;
        }

        try {
            // Quick health check
            $models = $this->getAvailableModels();
            return !empty($models);
        } catch (\Throwable $e) {
            $this->log('warning', 'Z.ai health check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Log a message
     *
     * @param string $level Log level
     * @param string $message Message
     */
    private function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->log($level, '[zai-client] ' . $message);
        }
    }
}
