<?php

declare(strict_types=1);

namespace App\DeepDive\Admin;

use App\DeepDive\Settings\ReportAISettings;
use App\DeepDive\AI\ZaiClient;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Report AI Settings Controller: Admin interface for DeepDive report AI
 *
 * PURPOSE:
 * Provides admin panel endpoints for:
 * - Viewing current settings
 * - Fetching available Z.ai models
 * - Updating model selection
 * - Managing API keys
 * - Configuring token budgets and thresholds
 *
 * ROUTES:
 * GET  /admin/api/deepdive-report-ai/settings
 * POST /admin/api/deepdive-report-ai/settings
 * GET  /admin/api/deepdive-report-ai/models
 * POST /admin/api/deepdive-report-ai/validate
 *
 * @package App\DeepDive\Admin
 */
final class ReportAISettingsController
{
    private ReportAISettings $settings;
    private ?ZaiClient $zai;
    private PDO $pdo;
    private ?LoggerInterface $logger;
    private string $tenantId;

    /**
     * Initialize controller
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
        $this->settings = new ReportAISettings($pdo, $tenantId, $logger);
        $this->zai = null;

        // Initialize Z.ai client if configured
        $this->initializeZai();
    }

    /**
     * Get current settings
     *
     * @return array Current settings
     */
    public function getSettings(): array
    {
        return [
            'settings' => $this->settings->getAll(),
            'status'   => [
                'enabled'   => $this->settings->isEnabled(),
                'using_zai' => $this->settings->useZai(),
                'zai_configured' => !empty($this->settings->getZaiApiKey()),
            ],
        ];
    }

    /**
     * Update settings
     *
     * @param array $data Settings to update
     *
     * @return array Result with status
     */
    public function updateSettings(array $data): array
    {
        try {
            // Validate input
            $validKeys = [
                'ai_enabled', 'token_budget', 'model', 'require_ai_analysis',
                'min_confidence', 'use_zai', 'zai_api_key'
            ];

            $updates = array_intersect_key($data, array_flip($validKeys));

            // Save settings
            $this->settings->setMultiple($updates);

            // Validate
            $errors = $this->settings->validate();

            if (!empty($errors)) {
                return [
                    'success' => false,
                    'errors'  => $errors,
                    'message' => 'Settings updated but validation failed',
                ];
            }

            // If Z.ai enabled and model selected, verify model exists
            if ($updates['use_zai'] ?? false) {
                $this->initializeZai();
                if ($this->zai && !empty($updates['model'] ?? null)) {
                    if (!$this->zai->validateModel($updates['model'])) {
                        return [
                            'success' => false,
                            'errors'  => ['Selected model is not available'],
                        ];
                    }
                }
            }

            $this->log('info', 'Settings updated successfully');

            return [
                'success' => true,
                'message' => 'Settings updated successfully',
                'settings' => $this->settings->getAll(),
            ];

        } catch (\Throwable $e) {
            $this->log('error', 'Failed to update settings: ' . $e->getMessage());
            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Get available models from Z.ai
     *
     * @return array Available models or error
     */
    public function getAvailableModels(): array
    {
        try {
            if (!$this->zai) {
                return [
                    'success' => false,
                    'error'   => 'Z.ai not configured',
                ];
            }

            if (!$this->zai->isAvailable()) {
                return [
                    'success' => false,
                    'error'   => 'Z.ai API is not accessible',
                ];
            }

            $models = $this->zai->getAvailableModels();

            return [
                'success' => true,
                'models'  => $models,
                'current' => $this->settings->getModel(),
            ];

        } catch (\Throwable $e) {
            $this->log('error', 'Failed to fetch models: ' . $e->getMessage());
            return [
                'success' => false,
                'error'   => 'Failed to fetch models: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Validate all settings
     *
     * @return array Validation result
     */
    public function validateSettings(): array
    {
        $errors = $this->settings->validate();

        if (!empty($errors)) {
            return [
                'valid'  => false,
                'errors' => $errors,
            ];
        }

        return [
            'valid' => true,
            'message' => 'All settings are valid',
        ];
    }

    /**
     * Test Z.ai connection
     *
     * @return array Test result
     */
    public function testZaiConnection(): array
    {
        try {
            if (!$this->zai) {
                return [
                    'success' => false,
                    'message' => 'Z.ai not configured',
                ];
            }

            if ($this->zai->isAvailable()) {
                $models = $this->zai->getAvailableModels();
                return [
                    'success' => true,
                    'message' => 'Z.ai connection successful',
                    'models_available' => count($models),
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Z.ai connection failed',
                ];
            }

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Z.ai connection test failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get admin panel HTML
     *
     * @return string HTML for admin settings page
     */
    public function getAdminHTML(): string
    {
        $settings = $this->settings->getAll();
        $isZaiEnabled = $settings['use_zai'] ?? false;
        $currentModel = $settings['model'] ?? '';

        ob_start();
        ?>
        <div class="deepdive-report-ai-settings">
            <h2>DeepDive Report AI Settings</h2>
            <p style="color: #666; font-size: 14px;">
                Separate configuration for anomaly detection and root cause analysis in DeepDive reports.
                These settings do not affect other AI features.
            </p>

            <form id="ai-settings-form" style="margin-top: 20px;">

                <!-- Enable/Disable Toggle -->
                <div style="margin-bottom: 20px; padding: 15px; background: #f5f5f5; border-radius: 4px;">
                    <label>
                        <input type="checkbox" name="ai_enabled" <?php echo $settings['ai_enabled'] ? 'checked' : ''; ?>>
                        <strong>Enable AI Analysis</strong>
                    </label>
                    <p style="color: #666; font-size: 13px; margin: 5px 0 0 20px;">
                        Enable anomaly detection and root cause analysis in reports
                    </p>
                </div>

                <!-- Token Budget -->
                <div style="margin-bottom: 20px;">
                    <label><strong>Token Budget Per Bundle</strong></label>
                    <input type="number" name="token_budget"
                           value="<?php echo htmlspecialchars((string)$settings['token_budget']); ?>"
                           min="5000" max="15000" step="1000"
                           style="width: 150px; padding: 8px; margin-top: 5px;">
                    <p style="color: #666; font-size: 13px; margin: 5px 0 0 0;">
                        5,000 (minimal) to 15,000 (comprehensive) tokens per bundle
                    </p>
                </div>

                <!-- Z.ai Configuration -->
                <div style="margin-bottom: 20px; padding: 15px; background: #f0f9ff; border-left: 3px solid #3b82f6; border-radius: 4px;">
                    <label>
                        <input type="checkbox" name="use_zai" <?php echo $isZaiEnabled ? 'checked' : ''; ?>>
                        <strong>Use Z.ai for Analysis</strong>
                    </label>
                    <p style="color: #666; font-size: 13px; margin: 5px 0 0 20px;">
                        Use Z.ai API for higher token limits and better model options
                    </p>

                    <div style="margin-top: 15px; margin-left: 20px; display: <?php echo $isZaiEnabled ? 'block' : 'none'; ?>" id="zai-config">
                        <div style="margin-bottom: 15px;">
                            <label><strong>Z.ai API Key</strong></label>
                            <input type="password" name="zai_api_key"
                                   placeholder="sk-..."
                                   style="width: 300px; padding: 8px; margin-top: 5px;">
                            <p style="color: #666; font-size: 12px; margin: 5px 0 0 0;">
                                <?php echo !empty($settings['zai_api_key']) ? '✓ API key is set' : 'Enter your Z.ai API key'; ?>
                            </p>
                            <button type="button" class="test-zai-btn" style="margin-left: 0; margin-top: 10px; padding: 8px 15px; cursor: pointer;">
                                Test Connection
                            </button>
                            <p style="color: #999; font-size: 12px; margin: 5px 0 0 15px;">
                                Save settings first before testing
                            </p>
                        </div>

                        <div style="margin-bottom: 15px;">
                            <label><strong>Model Selection</strong></label>
                            <select name="model" style="width: 300px; padding: 8px; margin-top: 5px;">
                                <option value="">-- Select Model --</option>
                                <?php if (!empty($currentModel)): ?>
                                    <option value="<?php echo htmlspecialchars($currentModel); ?>" selected>
                                        <?php echo htmlspecialchars($currentModel); ?> (Current)
                                    </option>
                                <?php endif; ?>
                            </select>
                            <button type="button" class="refresh-models-btn" style="margin-left: 10px; padding: 8px 15px; cursor: pointer;">
                                Fetch Available Models
                            </button>
                            <p style="color: #999; font-size: 12px; margin: 5px 0 0 0;">
                                Click "Fetch Available Models" to sync with Z.ai
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Confidence Threshold -->
                <div style="margin-bottom: 20px;">
                    <label><strong>Minimum Confidence for Display</strong></label>
                    <input type="number" name="min_confidence"
                           value="<?php echo htmlspecialchars((string)$settings['min_confidence']); ?>"
                           min="0" max="1" step="0.05"
                           style="width: 100px; padding: 8px; margin-top: 5px;">
                    <p style="color: #666; font-size: 13px; margin: 5px 0 0 0;">
                        0.40 (exploratory) to 1.00 (very high confidence only)
                    </p>
                </div>

                <!-- Error Handling -->
                <div style="margin-bottom: 20px;">
                    <label>
                        <input type="checkbox" name="require_ai_analysis" <?php echo $settings['require_ai_analysis'] ? 'checked' : ''; ?>>
                        <strong>Require AI Analysis Success</strong>
                    </label>
                    <p style="color: #666; font-size: 13px; margin: 5px 0 0 20px;">
                        If checked, reports will fail if AI analysis fails.
                        If unchecked, reports will render without AI findings if analysis fails.
                    </p>
                </div>

                <!-- Save Button -->
                <div style="margin-top: 30px;">
                    <button type="submit" style="padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px;">
                        Save Settings
                    </button>
                    <span id="save-status" style="margin-left: 15px; color: #666;"></span>
                </div>
            </form>

            <script>
            // Get CSRF token from page (try multiple sources)
            function getCsrfToken() {
                // First try window.CSRF_TOKEN set by template
                if (window.CSRF_TOKEN && window.CSRF_TOKEN.value) {
                    return window.CSRF_TOKEN.value;
                }

                // Try input field with csrf in name
                const inputField = document.querySelector('input[name*="csrf"]');
                if (inputField) {
                    return inputField.value;
                }

                // Try meta tag
                const metaToken = document.querySelector('meta[name*="csrf"]');
                if (metaToken) {
                    return metaToken.getAttribute('content');
                }

                // Try from cookie (Slim typically names it like _Token or similar)
                const cookies = document.cookie.split(';');
                for (let cookie of cookies) {
                    const [name, value] = cookie.trim().split('=');
                    if (name.includes('csrf') || name.includes('token')) {
                        return decodeURIComponent(value);
                    }
                }

                return null;
            }

            document.getElementById('ai-settings-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(e.target);
                const data = Object.fromEntries(formData);

                // Remove CSRF token from data if present
                delete data.__csrf;

                data.ai_enabled = !!data.ai_enabled;
                data.use_zai = !!data.use_zai;
                data.require_ai_analysis = !!data.require_ai_analysis;
                data.token_budget = parseInt(data.token_budget);
                data.min_confidence = parseFloat(data.min_confidence);

                const status = document.getElementById('save-status');
                status.textContent = 'Saving...';

                try {
                    const csrfToken = getCsrfToken();
                    if (!csrfToken) {
                        status.textContent = '✗ Error: CSRF token not found';
                        status.style.color = '#ef4444';
                        console.error('CSRF token not found in page');
                        return;
                    }

                    const headers = {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken};

                    const response = await fetch('/admin/api/deepdive-report-ai/settings', {
                        method: 'POST',
                        headers: headers,
                        body: JSON.stringify(data)
                    });

                    if (!response.ok) {
                        const text = await response.text();
                        status.textContent = '✗ Error: HTTP ' + response.status;
                        status.style.color = '#ef4444';
                        console.error('HTTP Error:', response.status, text);
                        return;
                    }

                    const result = await response.json();
                    status.textContent = result.success ? '✓ Saved' : '✗ Error: ' + (result.error || result.errors?.[0]);
                    status.style.color = result.success ? '#10b981' : '#ef4444';
                } catch (error) {
                    status.textContent = '✗ Error: ' + error.message;
                    status.style.color = '#ef4444';
                    console.error('Fetch error:', error);
                }
            });

            document.querySelector('[name="use_zai"]').addEventListener('change', (e) => {
                document.getElementById('zai-config').style.display = e.target.checked ? 'block' : 'none';
            });

            document.querySelector('.test-zai-btn').addEventListener('click', async (e) => {
                const btn = e.target;
                btn.disabled = true;
                btn.textContent = 'Testing...';
                try {
                    const csrfToken = getCsrfToken();
                    const headers = {};
                    if (csrfToken) {
                        headers['X-CSRF-Token'] = csrfToken;
                    }

                    const response = await fetch('/admin/api/deepdive-report-ai/test-zai', {
                        method: 'POST',
                        headers: headers
                    });
                    const result = await response.json();
                    alert(result.message);
                } catch (error) {
                    alert('Error: ' + error.message);
                } finally {
                    btn.disabled = false;
                    btn.textContent = 'Test Connection';
                }
            });

            document.querySelector('.refresh-models-btn').addEventListener('click', async (e) => {
                const btn = e.target;
                btn.disabled = true;
                btn.textContent = 'Fetching...';
                try {
                    const response = await fetch('/admin/api/deepdive-report-ai/models');
                    const result = await response.json();
                    if (result.success) {
                        const select = document.querySelector('[name="model"]');
                        // Clear existing options (except first placeholder)
                        while (select.options.length > 1) {
                            select.remove(1);
                        }
                        result.models.forEach(m => {
                            const option = document.createElement('option');
                            option.value = m.id;
                            option.textContent = m.name + ' (' + m.tokens + ' tokens)';
                            select.appendChild(option);
                        });
                        alert('Models refreshed');
                    } else {
                        alert('Error: ' + result.error);
                    }
                } catch (error) {
                    alert('Error: ' + error.message);
                } finally {
                    btn.disabled = false;
                    btn.textContent = 'Refresh Models';
                }
            });
            </script>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Initialize Z.ai client
     *
     * @return void
     */
    private function initializeZai(): void
    {
        try {
            if ($this->settings->useZai()) {
                $apiKey = $this->settings->getZaiApiKey();
                $model = $this->settings->getModel();

                if (!empty($apiKey) && !empty($model)) {
                    $this->zai = new ZaiClient($apiKey, $model, null, $this->logger);
                }
            }
        } catch (\Throwable $e) {
            $this->log('warning', 'Failed to initialize Z.ai: ' . $e->getMessage());
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
            $this->logger->log($level, '[report-ai-settings-ctrl] ' . $message);
        }
    }
}
