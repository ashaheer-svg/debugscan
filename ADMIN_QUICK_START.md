# Admin Quick Start: DeepDive Report AI Settings

## Accessing the Admin Panel

Navigate to: `/admin/deepdive-report-ai/settings`

---

## Settings Overview

### 1. Enable AI Analysis

**Toggle:** "Enable AI Analysis"

- **ON** (default): Anomaly detection and root cause analysis run automatically
- **OFF**: AI features disabled, reports render faster

**Use Case:**
- Enable for comprehensive analysis
- Disable for quick reports or troubleshooting

---

### 2. Token Budget Per Bundle

**Range:** 5,000 - 15,000 tokens (default: 10,000)

| Budget | Analysis Depth | Use Case |
|--------|---|---|
| 5,000 | Quick & focused | Fast turnaround, known issues |
| 10,000 | Balanced (default) | Standard production use |
| 15,000 | Comprehensive | Complex bundles, multi-issue diagnosis |

**How to Use:**
1. Drag slider to desired value
2. Or type number directly
3. Must be multiple of 1,000

**Guidance:**
- Start with **10,000** (balanced)
- Increase to **15,000** if findings are incomplete
- Decrease to **5,000** if budgets are constrained

---

### 3. Use Z.ai for Analysis

**Toggle:** "Use Z.ai for Analysis"

- **OFF** (default): Use built-in models
- **ON**: Use Z.ai API for higher token limits

**Setup:**
1. Check toggle
2. Enter your Z.ai API key (see below)
3. Click "Test Connection"
4. Click "Refresh Models"
5. Select model
6. Click "Save Settings"

---

### 4. Z.ai API Key

**Field:** "Z.ai API Key" (password input)

**Getting your key:**
1. Go to https://z.ai
2. Sign in (or create account)
3. Navigate to Settings → API Keys
4. Generate new key
5. Copy key (starts with `sk-`)

**Entering the key:**
1. Click password field
2. Paste your Z.ai API key
3. Click "Test Connection" to verify

**Security:**
- Key is stored encrypted in database
- Only visible to admins
- Never shown in logs or error messages

---

### 5. Model Selection

**Dropdown:** "Model Selection"

**Available models** (fetched from Z.ai):
- **Claude Opus** (Recommended) - Highest capability, ~200K tokens
- **Claude Sonnet** - Balanced capability/cost, ~200K tokens
- Other models available via Z.ai

**How to select:**
1. Check "Use Z.ai for Analysis"
2. Enter API key
3. Click "Refresh Models" (fetches latest available)
4. Choose model from dropdown
5. Click "Save Settings"

**Recommendation:**
- Start with **Claude Opus** for best results
- Use **Claude Sonnet** for cost optimization

---

### 6. Minimum Confidence Threshold

**Range:** 0.00 - 1.00 (default: 0.40)

| Threshold | Findings | Use Case |
|-----------|----------|----------|
| 0.40 | Many (exploratory) | Initial investigation, all leads |
| 0.50 | Moderate (balanced) | Production (default recommendation) |
| 0.60 | Fewer (confident) | Critical analysis only |
| 0.80 | Very few (high confidence) | When certainty required |

**How to use:**
1. Drag slider or type value
2. Use 0.05 increments

**Guidance:**
- **Development/Testing:** 0.40 (see all findings)
- **Production:** 0.50 (balanced)
- **Critical systems:** 0.60-0.80 (high confidence only)

---

### 7. Require AI Analysis Success

**Toggle:** "Require AI Analysis Success"

- **OFF** (default): Reports continue if AI fails (graceful degradation)
- **ON**: Reports fail if AI analysis fails (strict)

**Use Case:**
- OFF: Ensure reports always generate
- ON: Ensure reports only with complete AI analysis

**Behavior:**
```
OFF: AI fails → Report renders without findings ✓
ON:  AI fails → Report generation fails ✗
```

---

## Common Configurations

### Configuration 1: Development (Full Analysis)

```
✓ Enable AI Analysis
  Token Budget: 15,000
  Use Z.ai: OFF (optional)
  Model: (auto)
  Min Confidence: 0.40
  Require AI: OFF
```

**Result:** All findings shown, good for development

---

### Configuration 2: Production (Balanced)

```
✓ Enable AI Analysis
  Token Budget: 10,000 (default)
  Use Z.ai: ON (if budget available)
  Model: Claude Opus
  Min Confidence: 0.50
  Require AI: OFF
```

**Result:** Balanced findings, efficient token use

---

### Configuration 3: High-Confidence Only

```
✓ Enable AI Analysis
  Token Budget: 10,000
  Use Z.ai: ON (recommend)
  Model: Claude Opus
  Min Confidence: 0.70
  Require AI: ON
```

**Result:** Only high-confidence findings, strict reporting

---

### Configuration 4: Quick Reports (Cost Optimized)

```
✓ Enable AI Analysis
  Token Budget: 5,000
  Use Z.ai: OFF
  Model: (auto)
  Min Confidence: 0.60
  Require AI: OFF
```

**Result:** Fast, cost-effective analysis

---

## Testing Settings

### Test Connection (Z.ai)

**Button:** "Test Connection" (in Z.ai section)

**What it does:**
1. Verifies API key is valid
2. Connects to Z.ai API
3. Fetches available models
4. Returns status

**Expected result:**
```
✓ Z.ai connection successful
  Models available: 3
```

**If it fails:**
- ✗ "Z.ai API key is invalid"
  → Check API key spelling
  → Generate new key at z.ai
  
- ✗ "Z.ai API is not accessible"
  → Check internet connection
  → Check Z.ai service status
  → Try again in a few minutes

---

### Refresh Models

**Button:** "Refresh Models" (in Z.ai section)

**What it does:**
1. Fetches latest models from Z.ai
2. Updates dropdown with available options
3. Shows model capabilities (token limit, etc.)

**When to use:**
- After entering API key
- After Z.ai adds new models
- If dropdown seems empty

---

## Saving Settings

**Button:** "Save Settings"

**What happens:**
1. Form validates all values
2. Z.ai model validated (if selected)
3. Settings saved to database
4. Status shown: "✓ Saved" (green)

**If validation fails:**
```
✗ Error: Token budget must be at least 5000
✗ Error: Confidence must be between 0 and 1
✗ Error: Z.ai API key is required when Z.ai is enabled
```

**Fix and try again.**

---

## Monitoring

### Where to Check

**Logs:** `/var/log/deepdive/deepdive.log`

```
[deepdive.render] Settings loaded: ai_enabled=true, budget=10000
[deepdive.render] AI analysis complete: 2 bundle(s) analyzed in 8.34s
[deepdive.ai_analysis] Bundle abc123: 12 anomalies, 3 chains, 2 root causes
```

**Dashboard:** (if available)
- Reports per hour
- Average token usage
- AI success rate
- Analysis duration

---

## Troubleshooting

### Problem: "Settings invalid: Token budget must be at least 5000"

**Solution:** Increase token budget to at least 5,000

---

### Problem: "Z.ai API key is required when Z.ai is enabled"

**Solution:** 
1. Uncheck "Use Z.ai" (to disable)
2. OR enter valid Z.ai API key

---

### Problem: "Selected model is not available"

**Solution:**
1. Click "Refresh Models"
2. Wait for models to load
3. Select a model from dropdown
4. Save

---

### Problem: AI Analysis disabled, skipping

**Solution:** Check "Enable AI Analysis" toggle and save

---

### Problem: Reports not being generated

**Check:**
1. Is "Enable AI Analysis" checked?
2. Is API key correct (if using Z.ai)?
3. Is token budget ≥ 5,000?
4. Check logs for errors

---

## Advanced

### Database Query

Check current settings:
```sql
SELECT setting_name, setting_value 
FROM deepdive_report_ai_settings 
WHERE tenant_id = 'your-tenant-id'
ORDER BY setting_name;
```

### Reset to Defaults

```php
<?php
use App\DeepDive\Settings\ReportAISettings;

$settings = new ReportAISettings($pdo, $tenantId);
$settings->setMultiple([
    'ai_enabled' => true,
    'token_budget' => 10000,
    'model' => null,
    'use_zai' => false,
    'zai_api_key' => '',
    'min_confidence' => 0.40,
    'require_ai_analysis' => false,
]);
```

---

## Support

For help:
1. Check [PHASE_3_COMPLETE_INTEGRATION.md](PHASE_3_COMPLETE_INTEGRATION.md) for detailed docs
2. Review logs: `/var/log/deepdive/deepdive.log`
3. Test Z.ai connection via "Test Connection" button
4. Contact team: [support email]

