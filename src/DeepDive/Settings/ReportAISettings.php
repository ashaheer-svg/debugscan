<?php

declare(strict_types=1);

namespace App\DeepDive\Settings;

use PDO;
use Psr\Log\LoggerInterface;

/**
 * Report AI Settings: Separate configuration for DeepDive report AI
 *
 * PURPOSE:
 * Manages AI settings specific to DeepDive report generation.
 * Completely separate from other features' AI settings.
 *
 * SETTINGS STORED:
 * - AI enable/disable flag
 * - Token budget per bundle
 * - Model selection (Z.ai model)
 * - API key for Z.ai
 * - Confidence thresholds
 * - Error handling policy
 *
 * DATABASE TABLE:
 * deepdive_report_ai_settings
 * ├─ id (PK)
 * ├─ tenant_id (FK)
 * ├─ setting_name (unique per tenant)
 * ├─ setting_value (JSON encoded)
 * ├─ created_at
 * └─ updated_at
 *
 * @package App\DeepDive\Settings
 */
final class ReportAISettings
{
    private PDO $pdo;
    private ?LoggerInterface $logger;
    private string $tenantId;

    // Default values
    private const DEFAULTS = [
        'ai_enabled'           => true,
        'token_budget'         => 10000,
        'model'                => null,  // Will use default model if null
        'require_ai_analysis'  => false, // AI is optional
        'min_confidence'       => 0.40,
        'use_zai'              => false,
        'zai_api_key'          => '',
    ];

    /**
     * Initialize settings manager
     *
     * @param PDO $pdo Database connection
     * @param string $tenantId Tenant ID
     * @param ?LoggerInterface $logger Optional logger
     */
    public function __construct(PDO $pdo, string $tenantId, ?LoggerInterface $logger = null)
    {
        $this->pdo = $pdo;
        $this->tenantId = $tenantId;
        $this->logger = $logger;
    }

    /**
     * Get all settings for this tenant
     *
     * @return array All settings with defaults applied
     */
    public function getAll(): array
    {
        $this->ensureTableExists();

        try {
            $stmt = $this->pdo->prepare("
                SELECT setting_name, setting_value
                FROM deepdive_report_ai_settings
                WHERE tenant_id = :tenant_id
            ");
            $stmt->execute(['tenant_id' => $this->tenantId]);

            $settings = self::DEFAULTS;

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $name = $row['setting_name'];
                $value = json_decode($row['setting_value'], true);

                if ($value !== null) {
                    $settings[$name] = $value;
                }
            }

            return $settings;

        } catch (\Throwable $e) {
            $this->log('warning', 'Failed to load settings: ' . $e->getMessage());
            return self::DEFAULTS;
        }
    }

    /**
     * Get single setting
     *
     * @param string $name Setting name
     * @param mixed $default Default if not found
     *
     * @return mixed Setting value
     */
    public function get(string $name, $default = null)
    {
        $all = $this->getAll();
        return $all[$name] ?? $default ?? self::DEFAULTS[$name] ?? null;
    }

    /**
     * Save single setting
     *
     * @param string $name Setting name
     * @param mixed $value Setting value
     *
     * @return void
     *
     * @throws \RuntimeException On database error
     */
    public function set(string $name, $value): void
    {
        $this->ensureTableExists();

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO deepdive_report_ai_settings
                (tenant_id, setting_name, setting_value, updated_at)
                VALUES (:tenant_id, :setting_name, :setting_value, NOW())
                ON DUPLICATE KEY UPDATE
                    setting_value = :setting_value,
                    updated_at = NOW()
            ");

            $stmt->execute([
                'tenant_id'     => $this->tenantId,
                'setting_name'  => $name,
                'setting_value' => json_encode($value),
            ]);

            $this->log('debug', "Setting {$name} updated");

        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to save setting {$name}: " . $e->getMessage());
        }
    }

    /**
     * Save multiple settings
     *
     * @param array $settings Key-value pairs to save
     *
     * @return void
     */
    public function setMultiple(array $settings): void
    {
        foreach ($settings as $name => $value) {
            $this->set($name, $value);
        }
    }

    /**
     * Check if AI is enabled
     *
     * @return bool True if enabled
     */
    public function isEnabled(): bool
    {
        return (bool)$this->get('ai_enabled', true);
    }

    /**
     * Get token budget per bundle
     *
     * @return int Token budget (5000-15000)
     */
    public function getTokenBudget(): int
    {
        $budget = (int)$this->get('token_budget', 10000);
        return max(5000, min(15000, $budget)); // Clamp between 5K-15K
    }

    /**
     * Get selected model
     *
     * @return ?string Model ID or null
     */
    public function getModel(): ?string
    {
        return $this->get('model');
    }

    /**
     * Get Z.ai API key
     *
     * @return string API key (empty if not configured)
     */
    public function getZaiApiKey(): string
    {
        return (string)$this->get('zai_api_key', '');
    }

    /**
     * Is Z.ai enabled?
     *
     * @return bool True if Z.ai should be used
     */
    public function useZai(): bool
    {
        return (bool)$this->get('use_zai', false);
    }

    /**
     * Is AI analysis required?
     *
     * @return bool True if analysis must succeed
     */
    public function isRequired(): bool
    {
        return (bool)$this->get('require_ai_analysis', false);
    }

    /**
     * Get minimum confidence threshold for display
     *
     * @return float Confidence 0.0-1.0
     */
    public function getMinConfidence(): float
    {
        $conf = (float)$this->get('min_confidence', 0.40);
        return max(0.0, min(1.0, $conf));
    }

    /**
     * Validate settings are correct
     *
     * @return array<string> Validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];
        $settings = $this->getAll();

        if ($settings['token_budget'] < 5000) {
            $errors[] = 'Token budget must be at least 5000';
        }

        if ($settings['token_budget'] > 15000) {
            $errors[] = 'Token budget must not exceed 15000';
        }

        if ($settings['use_zai'] && empty($settings['zai_api_key'])) {
            $errors[] = 'Z.ai API key is required when Z.ai is enabled';
        }

        if ($settings['min_confidence'] < 0 || $settings['min_confidence'] > 1) {
            $errors[] = 'Confidence must be between 0 and 1';
        }

        return $errors;
    }

    /**
     * Ensure database table exists
     *
     * @return void
     */
    private function ensureTableExists(): void
    {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS deepdive_report_ai_settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id VARCHAR(255) NOT NULL,
                    setting_name VARCHAR(255) NOT NULL,
                    setting_value LONGTEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_tenant_setting (tenant_id, setting_name),
                    KEY idx_tenant (tenant_id)
                )
            ");
        } catch (\Throwable $e) {
            $this->log('warning', 'Failed to ensure table exists: ' . $e->getMessage());
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
            $this->logger->log($level, '[report-ai-settings] ' . $message);
        }
    }
}
