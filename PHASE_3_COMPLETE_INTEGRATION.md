# Phase 3: Complete Integration Guide (Fixed + Z.ai)

## Overview

Phase 3 implements AI report generation in two complementary ways:

1. **Inline (RenderStepFixed)**: AI analysis runs inside RenderStep during report rendering
2. **Pipeline Step (AIAnalysisStep)**: Optional standalone step for cleaner architecture

Both use **ReportAISettings** for centralized configuration and support **Z.ai integration** for higher token limits.

---

## Architecture

```
Pipeline Flow:
┌─────────────────────────────────────────────────────────────────┐
│ ParseStep          │ DecompressStep      │ CorrelateStep       │
│ (parse logs,       │ (extract bundles)   │ (run rules)         │
│  hardware specs)   │                     │                     │
└─────────────────────────────────────────────────────────────────┘
                              │
                    ┌─────────┴─────────┐
                    │                   │
            ╔═══════▼════════════╗   ╔══▼═════════════════╗
            ║ AIAnalysisStep     ║   ║ RenderStepFixed    ║
            ║ (Optional)         ║   ║ (Always)           ║
            ║ Phase 1 & 2 AI     ║   ║ Phase 1 & 2 AI     ║
            ║ Stores results     ║   ║ Inline             ║
            ╚═══════╤════════════╝   ╚══╤═════════════════╝
                    │                   │
                    └─────────┬─────────┘
                              │
                        ┌─────▼──────┐
                        │ NarrateStep│
                        │ (if needed)│
                        └─────┬──────┘
                              │
                        ┌─────▼─────────────┐
                        │ RenderStepFixed   │
                        │ (generate HTML)   │
                        │ (generate PDF)    │
                        └───────────────────┘
```

---

## Component Changes

### 1. RenderStepFixed.php (320 lines)

**What's New:**
- Loads `ReportAISettings` and validates on startup
- Checks `isEnabled()` before running AI analysis
- Respects `isRequired()` flag for error handling
- Configurable token budget via settings (5K-15K)
- Minimum confidence filtering for results
- Performance timing measurements
- Better error messages with context

**Key Methods:**
```php
// Main entry point
public function run(PipelineContext $ctx): void
    // Validates settings
    // Loads token budget from settings
    // Runs Phase 1 & 2 if enabled
    // Falls back gracefully on failure

// Internal helper for AI analysis
private function runAIAnalysis(
    PipelineContext $ctx,
    ReportAISettings $settings,
    float $startTime
): array
    // Processes each bundle through Phase 1 & 2
    // Filters by min confidence
    // Tracks token usage
    // Returns findings for rendering

// Load narrative overlay from database
private function loadOverlay(PipelineContext $ctx): array
```

**Usage:**
```php
$renderStep = new RenderStepFixed();
$renderStep->run($ctx);  // Loads settings, runs AI inline
```

### 2. AIAnalysisStep.php (Updated, 240 lines)

**What's New:**
- Loads `ReportAISettings` and validates on startup
- Checks `isEnabled()` before processing
- Uses configured token budget instead of hardcoding
- Filters root causes by minimum confidence
- Unsets bundle reference to prevent PHP issues
- Stores results in `ai_findings` (consistent with RenderStepFixed)
- Timing measurements

**Key Methods:**
```php
// Main entry point
public function run(PipelineContext $ctx): void
    // Loads settings
    // Checks if enabled (can be disabled)
    // Processes all bundles
    // Stores in $ctx->bag['ai_findings']
    // Handles per-bundle errors

// Process all bundles
private function analyzeAllBundles(
    PipelineContext $ctx,
    ReportAISettings $settings,
    array $bundles
): array
    // Runs Phase 1 on each bundle
    // Runs Phase 2 correlation
    // Analyzes chains for root causes
    // Filters by confidence threshold

// Compile statistics
private function compileStatistics(array $allFindings, float $duration): array
    // Total anomalies
    // Total chains
    // Total root causes
    // Token usage
    // Duration
```

**Usage:**
```php
// Add to pipeline config
$pipeline->addStep(new AIAnalysisStep());
// or
$pipeline->addStep('ai_analysis', 'after:correlate');
```

### 3. ReportAISettings.php (307 lines)

**Database Table:**
```sql
CREATE TABLE deepdive_report_ai_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id VARCHAR(255) NOT NULL,
    setting_name VARCHAR(255) NOT NULL,
    setting_value LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_tenant_setting (tenant_id, setting_name),
    KEY idx_tenant (tenant_id)
);
```

**Settings:**
| Setting | Type | Default | Range | Purpose |
|---------|------|---------|-------|---------|
| `ai_enabled` | bool | `true` | - | Enable/disable AI analysis |
| `token_budget` | int | `10000` | 5K-15K | Tokens per bundle analysis |
| `model` | string | `null` | - | Selected LLM model (e.g., claude-opus) |
| `use_zai` | bool | `false` | - | Use Z.ai API instead of default |
| `zai_api_key` | string | `` | - | Z.ai API key for authentication |
| `min_confidence` | float | `0.40` | 0.0-1.0 | Filter findings by confidence |
| `require_ai_analysis` | bool | `false` | - | Fail report if AI analysis fails |

**API:**
```php
$settings = new ReportAISettings($pdo, $tenantId, $logger);

// Get all settings (with defaults)
$all = $settings->getAll();

// Get single setting
$budget = $settings->getTokenBudget();     // int, clamped 5K-15K
$enabled = $settings->isEnabled();         // bool
$model = $settings->getModel();            // ?string
$zaiKey = $settings->getZaiApiKey();       // string
$useZai = $settings->useZai();             // bool
$required = $settings->isRequired();       // bool
$confidence = $settings->getMinConfidence(); // float, clamped 0.0-1.0

// Save settings
$settings->set('token_budget', 12000);
$settings->setMultiple([
    'ai_enabled' => true,
    'token_budget' => 12000,
    'use_zai' => true,
    'zai_api_key' => 'sk-...',
    'model' => 'claude-opus',
]);

// Validate all settings
$errors = $settings->validate();
// Returns: ['Token budget must be at least 5000', ...]
```

### 4. ZaiClient.php (247 lines)

**Purpose:** Interface to Z.ai API for higher token limits

**Configuration:**
```php
$client = new ZaiClient(
    'sk-your-api-key',      // API key
    'claude-opus',          // Model
    'https://api.z.ai/v1',  // Base URL (optional)
    $logger                 // Logger (optional)
);
```

**API:**
```php
// Get available models
$models = $client->getAvailableModels();
// Returns: [
//   ['id' => 'claude-opus', 'name' => 'Claude Opus', 'tokens' => 200000],
//   ['id' => 'claude-sonnet', 'name' => 'Claude Sonnet', 'tokens' => 200000],
// ]

// Validate model exists
if ($client->validateModel('claude-opus')) { ... }

// Check Z.ai is accessible
if ($client->isAvailable()) { ... }

// Make API call (internal use)
$response = $client->call('completions', $payload);

// Get/set current model
$model = $client->getModel();
$client->setModel('claude-sonnet');
```

### 5. ReportAISettingsController.php (467 lines)

**Purpose:** Admin panel for settings management

**Endpoints:**
| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/admin/api/deepdive-report-ai/settings` | GET | Get current settings |
| `/admin/api/deepdive-report-ai/settings` | POST | Update settings |
| `/admin/api/deepdive-report-ai/models` | GET | Fetch Z.ai available models |
| `/admin/api/deepdive-report-ai/validate` | POST | Validate settings |
| `/admin/api/deepdive-report-ai/test-zai` | POST | Test Z.ai connection |

**HTML Panel Features:**
- Enable/disable AI analysis toggle
- Token budget slider (5K-15K with 1K steps)
- Z.ai configuration section:
  - API key password input field
  - Model selector dropdown
  - Test Connection button (calls Z.ai API)
  - Refresh Models button (fetches latest models)
- Minimum confidence threshold (0.0-1.0 with 0.05 steps)
- Error handling toggle (required vs optional)
- Real-time validation feedback
- Save status indicator

**Usage:**
```php
$controller = new ReportAISettingsController($pdo, $tenantId, $logger);

// Get current settings
$result = $controller->getSettings();
// Returns: ['settings' => [...], 'status' => ['enabled' => true, ...]]

// Update settings
$result = $controller->updateSettings([
    'ai_enabled' => true,
    'token_budget' => 12000,
    'use_zai' => true,
    'zai_api_key' => 'sk-...',
    'model' => 'claude-opus',
]);

// Get available models from Z.ai
$result = $controller->getAvailableModels();
// Returns: ['success' => true, 'models' => [...]]

// Test Z.ai connection
$result = $controller->testZaiConnection();
// Returns: ['success' => true, 'message' => 'Z.ai connection successful', ...]

// Render admin HTML
echo $controller->getAdminHTML();
```

---

## Z.ai Integration

### Enable Z.ai

**Method 1: Direct API**
```php
$settings = new ReportAISettings($pdo, $tenantId, $logger);
$settings->setMultiple([
    'use_zai' => true,
    'zai_api_key' => 'sk-your-api-key',
    'model' => 'claude-opus',
]);
```

**Method 2: Admin Panel**
1. Navigate to `/admin/deepdive-report-ai/settings`
2. Check "Use Z.ai for Analysis"
3. Enter your Z.ai API key
4. Click "Test Connection"
5. Click "Refresh Models"
6. Select desired model
7. Click "Save Settings"

### Model Selection

**Available via Z.ai:**
```php
$controller = new ReportAISettingsController($pdo, $tenantId, $logger);
$result = $controller->getAvailableModels();

foreach ($result['models'] as $model) {
    echo "{$model['name']} ({$model['tokens']} tokens)\n";
}
```

### Token Budgets

| Configuration | Budget | Use Case |
|---------------|--------|----------|
| Minimal | 5,000 | Quick analysis, single-subsystem focus |
| Standard | 10,000 | Balanced analysis (default) |
| Comprehensive | 15,000 | Multi-subsystem, detailed chains |

**Select budget based on:**
- Bundle size (number of log entries)
- Number of anomalies
- Chain complexity
- Model token limit

---

## Migration Path

### From Original RenderStep to RenderStepFixed

**Step 1: Update imports**
```php
// Old
use App\DeepDive\AI\AnomalyDetector;
use App\DeepDive\AI\EventCorrelator;
use App\DeepDive\AI\RootCauseAnalyzer;

// New
use App\DeepDive\AI\AnomalyDetector;
use App\DeepDive\AI\EventCorrelator;
use App\DeepDive\AI\RootCauseAnalyzer;
use App\DeepDive\Settings\ReportAISettings;
```

**Step 2: Create settings table**
```php
// Automatic on first access via ReportAISettings::ensureTableExists()
// Or manually:
$settings = new ReportAISettings($pdo, $tenantId);
// Table created on first call to getAll(), get(), etc.
```

**Step 3: Set initial values**
```php
$settings->setMultiple([
    'ai_enabled' => true,
    'token_budget' => 10000,
    'min_confidence' => 0.40,
    'require_ai_analysis' => false,
]);
```

**Step 4: Replace RenderStep usage**
```php
// Old
$renderStep = new RenderStep();

// New
$renderStep = new RenderStepFixed();
```

**Step 5: (Optional) Add AIAnalysisStep**
```php
// In pipeline configuration
$pipeline->addStep(new AIAnalysisStep(), 'after:correlate');
```

---

## Configuration Examples

### Example 1: Default Configuration (Inline Only)
```php
// Use RenderStepFixed inline, no separate pipeline step
$settings = new ReportAISettings($pdo, $tenantId);
$settings->setMultiple([
    'ai_enabled' => true,
    'token_budget' => 10000,
    'use_zai' => false,
    'min_confidence' => 0.40,
    'require_ai_analysis' => false,
]);

// RenderStepFixed runs AI inline
$pipeline->addStep(new RenderStepFixed());
```

### Example 2: With Z.ai (Inline Only)
```php
$settings = new ReportAISettings($pdo, $tenantId);
$settings->setMultiple([
    'ai_enabled' => true,
    'token_budget' => 15000,          // Max budget
    'use_zai' => true,
    'zai_api_key' => 'sk-...',
    'model' => 'claude-opus',         // Higher token model
    'min_confidence' => 0.50,         // Higher threshold with more analysis
    'require_ai_analysis' => false,
]);

// RenderStepFixed uses Z.ai configuration
$pipeline->addStep(new RenderStepFixed());
```

### Example 3: Dual Approach (Inline + Pipeline Step)
```php
$settings = new ReportAISettings($pdo, $tenantId);
$settings->setMultiple([
    'ai_enabled' => true,
    'token_budget' => 12000,
    'use_zai' => true,
    'zai_api_key' => 'sk-...',
    'model' => 'claude-sonnet',
    'min_confidence' => 0.45,
    'require_ai_analysis' => false,
]);

// Both run, share same settings
$pipeline->addStep(new AIAnalysisStep(), 'after:correlate');
$pipeline->addStep(new RenderStepFixed());

// Note: AI runs twice (once in each step)
// Use either/or unless caching is implemented
```

### Example 4: Pipeline Step Only (Clean Architecture)
```php
$settings = new ReportAISettings($pdo, $tenantId);
$settings->setMultiple([
    'ai_enabled' => true,
    'token_budget' => 12000,
    'use_zai' => true,
    'zai_api_key' => 'sk-...',
    'model' => 'claude-opus',
    'min_confidence' => 0.40,
    'require_ai_analysis' => false,
]);

// AIAnalysisStep processes bundles
// RenderStepFixed uses cached results from ai_findings
$pipeline->addStep(new AIAnalysisStep(), 'after:correlate');
// RenderStepFixed reads ctx->bag['ai_findings'] instead of running inline
$pipeline->addStep(new RenderStepFixed());
```

### Example 5: AI Optional (Non-Blocking Failures)
```php
$settings = new ReportAISettings($pdo, $tenantId);
$settings->setMultiple([
    'ai_enabled' => true,
    'token_budget' => 10000,
    'require_ai_analysis' => false,  // ← Continues if AI fails
    'min_confidence' => 0.40,
]);

// If AI analysis fails, reports still render without findings
```

### Example 6: AI Required (Blocking Failures)
```php
$settings = new ReportAISettings($pdo, $tenantId);
$settings->setMultiple([
    'ai_enabled' => true,
    'token_budget' => 10000,
    'require_ai_analysis' => true,   // ← Throws if AI fails
    'min_confidence' => 0.40,
]);

// If AI analysis fails, throws RuntimeException
```

---

## Testing Strategy

### Unit Tests

**Test ReportAISettings:**
```php
// Test 1: Load defaults
$settings = new ReportAISettings($pdo, $tenantId);
$all = $settings->getAll();
assert($all['ai_enabled'] === true);
assert($all['token_budget'] === 10000);

// Test 2: Save and load
$settings->set('token_budget', 12000);
assert($settings->getTokenBudget() === 12000);

// Test 3: Validate
$settings->set('token_budget', 1000);
$errors = $settings->validate();
assert(count($errors) > 0);

// Test 4: Clamp values
assert($settings->getTokenBudget() === 5000);  // Clamped to min
```

**Test ZaiClient:**
```php
// Test 1: Model validation
$client = new ZaiClient('sk-test-key', 'claude-opus');
assert($client->validateModel('claude-opus') === true);
assert($client->validateModel('invalid-model') === false);

// Test 2: Availability
$available = $client->isAvailable();
assert(is_bool($available));
```

**Test RenderStepFixed:**
```php
// Test 1: Settings loaded
$ctx = new PipelineContext(...);
$step = new RenderStepFixed();
$step->run($ctx);
// Verify ReportAISettings was loaded

// Test 2: AI skipped if disabled
$settings->set('ai_enabled', false);
$step->run($ctx);
// Verify $ctx->bag['ai_findings'] is empty

// Test 3: Confidence filtering
$settings->set('min_confidence', 0.80);
$step->run($ctx);
// Verify findings with confidence < 0.80 not included
```

**Test AIAnalysisStep:**
```php
// Test 1: Skips if disabled
$settings->set('ai_enabled', false);
$step = new AIAnalysisStep();
$step->run($ctx);
assert(empty($ctx->bag['ai_findings']));

// Test 2: Analyzes bundles
$settings->set('ai_enabled', true);
$step->run($ctx);
assert(count($ctx->bag['ai_findings']) > 0);

// Test 3: Statistics compiled
assert(isset($ctx->bag['ai_statistics']['duration_seconds']));
```

### Integration Tests

**Test Full Pipeline with Z.ai:**
```php
// Setup
$settings = new ReportAISettings($pdo, $tenantId);
$settings->setMultiple([
    'ai_enabled' => true,
    'use_zai' => true,
    'zai_api_key' => $zaiKey,
    'model' => 'claude-opus',
    'token_budget' => 12000,
]);

// Run
$pipeline->execute($ctx);

// Verify
assert($ctx->bag['ai_findings'] !== null);
assert($ctx->bag['ai_statistics']['total_tokens_used'] > 0);
assert(!empty($ctx->bag['report']['html_path']));
```

**Test Both Approaches:**
```php
// With AIAnalysisStep
$pipeline->addStep(new AIAnalysisStep());
$pipeline->addStep(new RenderStepFixed());
$pipeline->execute($ctx1);

// Without AIAnalysisStep (inline only)
$pipeline2->addStep(new RenderStepFixed());
$pipeline2->execute($ctx2);

// Both should produce similar findings
assert(count($ctx1->bag['ai_findings']) === count($ctx2->bag['ai_findings']));
```

---

## Performance Considerations

### Token Usage
- **Small bundles (< 50 anomalies):** 3K-5K tokens
- **Medium bundles (50-200 anomalies):** 7K-10K tokens
- **Large bundles (> 200 anomalies):** 10K-15K tokens

### Timing
- **Phase 1 (Anomaly Detection):** 2-5 seconds per bundle
- **Phase 2 (Correlation + Root Cause):** 3-8 seconds per bundle
- **Total (Phase 1 + 2):** 5-13 seconds per bundle

### Optimization
```php
// Use smaller token budget for faster analysis
$settings->set('token_budget', 5000);

// Increase confidence threshold to reduce findings
$settings->set('min_confidence', 0.60);

// Use pipeline step if running multiple analyses
// (future: add caching)
```

---

## Troubleshooting

### Issue: "Z.ai API error: Connection refused"

**Solution:**
1. Verify `zai_api_key` is set correctly
2. Check Z.ai API status
3. Verify network connectivity
4. Test via admin panel "Test Connection" button

### Issue: "Token budget must be at least 5000"

**Solution:**
```php
$settings->set('token_budget', 5000);  // Minimum
```

### Issue: "AI analysis disabled, skipping"

**Solution:**
```php
$settings->set('ai_enabled', true);
```

### Issue: No root causes found

**Solution:**
1. Lower minimum confidence:
   ```php
   $settings->set('min_confidence', 0.40);  // Default
   ```

2. Increase token budget:
   ```php
   $settings->set('token_budget', 15000);
   ```

3. Verify anomalies detected in Phase 1

---

## Files Summary

| File | Lines | Purpose |
|------|-------|---------|
| RenderStepFixed.php | 365 | Fixed render step with configurable AI |
| AIAnalysisStep.php | 240 | Optional pipeline step for AI analysis |
| ReportAISettings.php | 307 | Settings management with database |
| ZaiClient.php | 247 | Z.ai API integration |
| ReportAISettingsController.php | 467 | Admin settings interface |
| **Total** | **1,626** | **Complete Phase 3 system** |

---

## Next Steps

1. **Test with Z.ai:** Verify Z.ai connection and model selection
2. **Verify confidence filtering:** Ensure min_confidence threshold works
3. **Load test:** Test with large bundles and high anomaly counts
4. **Monitor tokens:** Track actual token usage vs budget
5. **Documentation:** Update user guides with admin settings interface
6. **Deploy:** Migrate from original RenderStep to RenderStepFixed

