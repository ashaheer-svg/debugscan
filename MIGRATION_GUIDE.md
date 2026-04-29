# Migration Guide: Original → Fixed Phase 3

## Overview

This guide covers migrating from the original Phase 3 implementation to the fixed version with Z.ai integration and configurable settings.

### What Changed

| Component | Original | Fixed | Impact |
|-----------|----------|-------|--------|
| **Settings** | Hardcoded | Database-backed | ✅ Configurable per tenant |
| **Token Budget** | Hardcoded 10K | 5K-15K configurable | ✅ Flexible per deployment |
| **Model Selection** | Not supported | Z.ai integration | ✅ Higher token limits |
| **Confidence Filtering** | Hardcoded 0.40 | Configurable 0.0-1.0 | ✅ Flexible result filtering |
| **Error Handling** | Always throws | Configurable | ✅ Graceful degradation |
| **Enable/Disable** | Always runs | Toggle via settings | ✅ Save compute resources |
| **Performance Metrics** | Not tracked | Timing + stats | ✅ Better monitoring |

---

## Step-by-Step Migration

### Phase 1: Database Preparation

#### 1.1 Create Settings Table

The table is auto-created on first access via `ReportAISettings::ensureTableExists()`, but you can create it explicitly:

```sql
CREATE TABLE IF NOT EXISTS deepdive_report_ai_settings (
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

#### 1.2 Initialize Default Settings

```php
<?php
// script: initialize_ai_settings.php

require_once 'vendor/autoload.php';

use App\DeepDive\Settings\ReportAISettings;

$pdo = new PDO('mysql:host=localhost;dbname=your_db', 'user', 'pass');

// Get all tenants
$tenants = $pdo->query("SELECT DISTINCT tenant_id FROM your_tenants_table")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tenants as $tenantId) {
    $settings = new ReportAISettings($pdo, $tenantId);
    
    // Set defaults (or customize per tenant)
    $settings->setMultiple([
        'ai_enabled' => true,
        'token_budget' => 10000,           // Default
        'min_confidence' => 0.40,
        'require_ai_analysis' => false,
        'use_zai' => false,                // Enable later after setup
    ]);
    
    echo "Initialized settings for tenant: {$tenantId}\n";
}
```

**Run:**
```bash
php initialize_ai_settings.php
```

---

### Phase 2: Code Updates

#### 2.1 Update RenderStep

**Before (Original):**
```php
<?php
// src/DeepDive/Pipeline/RenderStep.php

final class RenderStep implements StepInterface
{
    public function run(PipelineContext $ctx): void
    {
        // ... initialize renderer ...
        
        $detector = new AnomalyDetector($ctx->pdo, $ctx->logger, 10000);  // ← Hardcoded
        
        // ...
        
        $ctx->bag['report']['html_path'] = $htmlPath;  // ← Not initialized
    }
}
```

**After (Fixed):**
```php
<?php
// src/DeepDive/Pipeline/RenderStepFixed.php

use App\DeepDive\Settings\ReportAISettings;

final class RenderStepFixed implements StepInterface
{
    public function run(PipelineContext $ctx): void
    {
        // Initialize report bag first
        $ctx->bag['report'] ??= [];  // ← FIX
        
        // Load settings
        $aiSettings = new ReportAISettings($ctx->pdo, $ctx->tenantId, $ctx->logger);
        
        // Check if enabled
        if (!$aiSettings->isEnabled()) {
            // Skip AI analysis
        } else {
            // Load configurable budget
            $tokenBudget = $aiSettings->getTokenBudget();
            $detector = new AnomalyDetector($ctx->pdo, $ctx->logger, $tokenBudget);
        }
        
        // ... rest of implementation ...
    }
}
```

**Migration Steps:**
1. Rename `RenderStep.php` → `RenderStep.php.bak`
2. Copy `RenderStepFixed.php` → `RenderStep.php` (or use new name)
3. Update any imports of `RenderStep` if renamed
4. Test report generation

#### 2.2 Update AIAnalysisStep (if using)

The AIAnalysisStep was already created but needs the fixes:

**Changes:**
```php
// ADD: Import ReportAISettings
use App\DeepDive\Settings\ReportAISettings;

public function run(PipelineContext $ctx): void
{
    // ADD: Load and validate settings
    $aiSettings = new ReportAISettings($ctx->pdo, $ctx->tenantId, $ctx->logger);
    
    // ADD: Check if enabled
    if (!$aiSettings->isEnabled()) {
        $ctx->completeStep($this->id());
        return;
    }
    
    // CHANGE: Use configurable budget instead of hardcoding 10000
    $tokenBudget = $aiSettings->getTokenBudget();
    $detector = new AnomalyDetector($ctx->pdo, $ctx->logger, $tokenBudget);
    
    // ADD: Use configurable confidence threshold
    $minConfidence = $aiSettings->getMinConfidence();
    if ($finding['confidence'] >= $minConfidence) {
        $rootCauses[] = $finding;
    }
    
    // ADD: Clean up reference
    unset($bundle);
    
    // CHANGE: Use consistent naming
    $ctx->bag['ai_findings'] = $allResults;  // Was: ai_results
}
```

**File:** `/sessions/brave-vigilant-cannon/mnt/ai-debugscan3/src/DeepDive/Pipeline/AIAnalysisStep.php`

---

### Phase 3: Z.ai Setup (Optional)

#### 3.1 Get Z.ai API Key

1. Register at Z.ai (https://z.ai)
2. Generate API key from settings
3. Copy key (format: `sk-...`)

#### 3.2 Initialize Z.ai Configuration

**Method 1: Programmatic**
```php
<?php
use App\DeepDive\Settings\ReportAISettings;

$settings = new ReportAISettings($pdo, $tenantId);

$settings->setMultiple([
    'use_zai' => true,
    'zai_api_key' => 'sk-your-z-ai-key',
    'model' => 'claude-opus',  // Higher token models available
    'token_budget' => 15000,   // Max budget for comprehensive analysis
]);
```

**Method 2: Admin Panel**
1. Navigate to `/admin/deepdive-report-ai/settings`
2. Check "Use Z.ai for Analysis"
3. Paste API key
4. Click "Test Connection"
5. Click "Refresh Models"
6. Select model
7. Click "Save Settings"

#### 3.3 Verify Z.ai Connection

```php
<?php
$controller = new ReportAISettingsController($pdo, $tenantId);
$result = $controller->testZaiConnection();

if ($result['success']) {
    echo "✓ Z.ai connected successfully\n";
    echo "Available models: {$result['models_available']}\n";
} else {
    echo "✗ Z.ai connection failed: {$result['message']}\n";
}
```

---

### Phase 4: Testing

#### 4.1 Unit Tests

**Test settings loading:**
```php
<?php
use App\DeepDive\Settings\ReportAISettings;

$settings = new ReportAISettings($pdo, $tenantId);

// Verify defaults loaded
assert($settings->isEnabled() === true);
assert($settings->getTokenBudget() === 10000);
assert($settings->getMinConfidence() === 0.40);

// Verify we can change them
$settings->set('token_budget', 12000);
assert($settings->getTokenBudget() === 12000);

// Verify clamping works
$settings->set('token_budget', 1000);  // Below min
assert($settings->getTokenBudget() === 5000);  // Clamped to min

$settings->set('token_budget', 20000);  // Above max
assert($settings->getTokenBudget() === 15000);  // Clamped to max
```

#### 4.2 Integration Tests

**Test RenderStepFixed:**
```php
<?php
$ctx = new PipelineContext($pdo, $tenantId, $jobId, $projectId, $logger);
$ctx->bag['bundles'] = [...];  // Load test bundles

$settings = new ReportAISettings($pdo, $tenantId);
$settings->set('ai_enabled', true);

$step = new RenderStepFixed();
$step->run($ctx);

// Verify report generated
assert(!empty($ctx->bag['report']['html_path']));
assert(file_exists($ctx->bag['report']['html_path']));

// Verify AI findings (if anomalies present)
if (!empty($ctx->bag['ai_findings'])) {
    assert(is_array($ctx->bag['ai_findings']));
}
```

**Test with Z.ai:**
```php
<?php
$settings = new ReportAISettings($pdo, $tenantId);
$settings->setMultiple([
    'use_zai' => true,
    'zai_api_key' => $zaiKey,
    'model' => 'claude-opus',
    'token_budget' => 12000,
]);

// Run same test
$step = new RenderStepFixed();
$step->run($ctx);

// Verify used Z.ai
// (Check logs or add tracking to ZaiClient)
```

#### 4.3 Generate Sample Report

```php
<?php
// script: test_report_generation.php

require_once 'vendor/autoload.php';

use App\DeepDive\Pipeline\Pipeline;
use App\DeepDive\Pipeline\RenderStepFixed;
use App\DeepDive\Settings\ReportAISettings;

$pdo = new PDO('mysql:host=localhost;dbname=your_db', 'user', 'pass');
$tenantId = 'tenant-123';
$jobId = 'job-456';
$projectId = 'project-789';

// Load settings
$settings = new ReportAISettings($pdo, $tenantId);
echo "Settings:\n";
echo "  AI Enabled: " . ($settings->isEnabled() ? 'Yes' : 'No') . "\n";
echo "  Token Budget: {$settings->getTokenBudget()}\n";
echo "  Use Z.ai: " . ($settings->useZai() ? 'Yes' : 'No') . "\n";
echo "  Model: {$settings->getModel()}\n";
echo "  Min Confidence: {$settings->getMinConfidence()}\n";

// Create and run pipeline
$pipeline = new Pipeline($pdo, $tenantId, $jobId, $projectId);
$pipeline->addStep(new RenderStepFixed());

$ctx = $pipeline->execute();

// Verify report
if (!empty($ctx->bag['report']['html_path'])) {
    echo "\n✓ Report generated: {$ctx->bag['report']['html_path']}\n";
    echo "  Size: " . filesize($ctx->bag['report']['html_path']) . " bytes\n";
} else {
    echo "\n✗ Report generation failed\n";
}

// Show AI statistics
if (!empty($ctx->bag['ai_statistics'])) {
    echo "\nAI Analysis:\n";
    echo "  Bundles analyzed: {$ctx->bag['ai_statistics']['bundles_analyzed']}\n";
    echo "  Total anomalies: {$ctx->bag['ai_statistics']['total_anomalies']}\n";
    echo "  Total chains: {$ctx->bag['ai_statistics']['total_chains']}\n";
    echo "  Total root causes: {$ctx->bag['ai_statistics']['total_root_causes']}\n";
    echo "  Tokens used: {$ctx->bag['ai_statistics']['total_tokens_used']}\n";
    echo "  Duration: {$ctx->bag['ai_statistics']['duration_seconds']:.2f}s\n";
}
```

**Run:**
```bash
php test_report_generation.php
```

---

### Phase 5: Rollout

#### 5.1 Staging Environment

1. Initialize settings table
2. Deploy fixed code
3. Run test report generation
4. Verify Z.ai integration (if enabled)
5. Verify confidence filtering
6. Check performance metrics

#### 5.2 Production Rollout

**Option A: Gradual Rollout**
1. Update 10% of tenants
2. Monitor for 1 week
3. Update 50% of tenants
4. Monitor for 1 week
5. Update remaining tenants

**Option B: Full Rollout**
1. Deploy to all tenants
2. Enable via settings per tenant
3. Monitor all logs

#### 5.3 Monitoring

**Log Metrics:**
```
- [deepdive.render] Settings loaded
- [deepdive.render] AI analysis complete: X bundle(s) analyzed in Y.YYs
- [deepdive.ai_analysis] Starting batch analysis: budget=10K, model=default
- [deepdive.ai_analysis] Bundle 123: 45 anomalies, 12 chains, 8 root causes
```

**Alert Conditions:**
- Settings validation errors
- Z.ai connection failures
- Token budget exceeded
- Analysis duration > 60 seconds per bundle
- AI failures (if required)

---

## Configuration Comparison

### Before (Hardcoded)

```php
// Token budget: hardcoded 10000
$detector = new AnomalyDetector($ctx->pdo, $ctx->logger, 10000);

// Confidence threshold: hardcoded 0.40
if ($finding['confidence'] >= 0.40) { ... }

// Error handling: always throw
if ($aiAnalysisFailed) {
    throw new \RuntimeException(...);
}

// Enable/disable: not possible
// Must comment out or remove code
```

### After (Configurable)

```php
// Token budget: configurable via settings
$settings = new ReportAISettings($pdo, $tenantId);
$budget = $settings->getTokenBudget();  // 5K-15K
$detector = new AnomalyDetector($ctx->pdo, $ctx->logger, $budget);

// Confidence threshold: configurable via settings
$threshold = $settings->getMinConfidence();  // 0.0-1.0
if ($finding['confidence'] >= $threshold) { ... }

// Error handling: configurable via settings
if ($aiAnalysisFailed && $settings->isRequired()) {
    throw new \RuntimeException(...);  // Only if required
}

// Enable/disable: simple toggle
if (!$settings->isEnabled()) {
    return;  // Skip AI, continue pipeline
}
```

---

## Troubleshooting Migration

### Issue: "Class not found: ReportAISettings"

**Solution:**
- Ensure `ReportAISettings.php` exists in `src/DeepDive/Settings/`
- Check namespace: `namespace App\DeepDive\Settings;`
- Verify composer autoloader: `composer dump-autoload`

### Issue: "UNIQUE constraint failed"

**Solution:**
```sql
-- Check for duplicate settings
SELECT tenant_id, setting_name, COUNT(*) 
FROM deepdive_report_ai_settings 
GROUP BY tenant_id, setting_name 
HAVING COUNT(*) > 1;

-- Delete duplicates (keep one)
DELETE FROM deepdive_report_ai_settings 
WHERE id NOT IN (
    SELECT MIN(id) 
    FROM deepdive_report_ai_settings 
    GROUP BY tenant_id, setting_name
);
```

### Issue: "AI analysis disabled, skipping"

**Solution:**
```php
$settings->set('ai_enabled', true);
```

### Issue: "Z.ai API error: Connection refused"

**Solution:**
1. Verify API key is correct
2. Check Z.ai service status
3. Test with curl:
   ```bash
   curl -H "Authorization: Bearer sk-..." https://api.z.ai/v1/models
   ```

---

## Rollback Plan

If you need to revert to the original implementation:

### Quick Rollback
```bash
# Restore original RenderStep
mv src/DeepDive/Pipeline/RenderStep.php.bak src/DeepDive/Pipeline/RenderStep.php

# Revert to original AIAnalysisStep (if using)
git checkout src/DeepDive/Pipeline/AIAnalysisStep.php
```

### Data Cleanup
```sql
-- Optional: Drop settings table (preserve data first)
-- DROP TABLE deepdive_report_ai_settings;
```

### Verify
```php
// Run original code
$detector = new AnomalyDetector($ctx->pdo, $ctx->logger, 10000);
// Should work as before
```

---

## Checklist

- [ ] Database table created
- [ ] Default settings initialized
- [ ] Code updated to RenderStepFixed
- [ ] AIAnalysisStep updated (if using)
- [ ] Unit tests passing
- [ ] Integration tests passing
- [ ] Z.ai configured (optional)
- [ ] Z.ai connection verified
- [ ] Sample report generated
- [ ] Performance acceptable
- [ ] Monitoring in place
- [ ] Team trained on admin settings
- [ ] Rollback plan tested
- [ ] Production deployment scheduled

---

## Support

For questions or issues:
1. Check the [PHASE_3_COMPLETE_INTEGRATION.md](PHASE_3_COMPLETE_INTEGRATION.md) for detailed documentation
2. Review logs for error messages
3. Test Z.ai connection via admin panel
4. Verify settings via database: `SELECT * FROM deepdive_report_ai_settings`

