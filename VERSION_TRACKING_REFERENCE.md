# Version Tracking Reference

**Purpose**: Quick reference for component versions and deployment verification

---

## Current Component Versions

### Core Pipeline (3.1.0)
```
Core Application:        DeepDive 3.1.0
Pipeline Logger:         PipelineLogger 1.0.0
Version Registry:        VersionRegistry 1.0.0
Log Exporter:            LogExporter 1.0.0
```

### Parsers (3.1.0)
```
Power Supply Parser:     PowerSupplyParser 3.1.0 ✅ (robust file discovery + fallback)
Hardware Spec:           HardwareSpecExtractor 1.5.0
Bundle Locator:          BundleLocator 1.0.0
```

### Validation (1.0.0)
```
File Availability:       FileAvailabilityValidator 1.0.0 ✅ (new)
```

### Pipeline Steps (3.1.0)
```
Decompress Step:         DecompressStep 1.0.0
Parse Step:              ParseStep 2.1.0 ✅ (integrated validator)
Evaluate Step:           EvaluateStep 1.0.0
Correlate Step:          CorrelateStep 1.0.0
Render Step:             RenderStep 3.1.0 ✅ (power anomaly detection)
Render Step (Fixed):     RenderStepFixed 3.1.0 ✅ (power anomaly detection)
```

### AI Analysis (1.0.0)
```
Anomaly Detector:        AnomalyDetector 1.0.0
Event Correlator:        EventCorrelator 1.0.0
Root Cause Analyzer:     RootCauseAnalyzer 1.0.0
Log Preprocessor:        LogPreprocessor 1.0.0
```

---

## Version Update Checklist

When deploying code changes:

### 1. Update VersionRegistry

Edit `src/DeepDive/Logging/VersionRegistry.php`:

```php
private static array $versions = [
    'PowerSupplyParser' => '3.1.0',     // ← Update if parser changed
    'FileAvailabilityValidator' => '1.0.0',  // ← Update if validator changed
    'ParseStep' => '2.1.0',             // ← Update if parsing logic changed
    'RenderStep' => '3.1.0',            // ← Update if rendering changed
    // ...
];
```

### 2. Update Report Version String

Edit `src/DeepDive/Pipeline/RenderStep.php`:

```php
'report_version' => '3.1.0',  // ← Update in report metadata
```

### 3. Verify in Code Comments

Add version comments near important classes:

```php
/**
 * PowerSupplyParser: Power supply analysis
 * @version 3.1.0
 * @since 2026-04-30
 * 
 * CHANGES:
 * - Added locateFile() for robust file discovery
 * - Updated all parsers to search multiple paths
 * - Handles DSM 6/7 extraction layout variations
 */
class PowerSupplyParser
```

### 4. Check Logs After Deployment

After deploying, check that versions are logged correctly:

```bash
# Get component versions from latest log
cat logs/JOBID/audit.json | jq '.component_versions'

# Should show:
{
  "PowerSupplyParser": {
    "version": "3.1.0",
    "registered_at": "2026-04-30T...",
    "metadata": {...}
  },
  ...
}
```

---

## Semantic Versioning Guide

Use semantic versioning: `MAJOR.MINOR.PATCH`

### MAJOR (Breaking Changes)
Update when:
- File format changes
- API changes
- Data structure changes
- Requires data migration

Example: `3.0.0` → `4.0.0`

### MINOR (New Features)
Update when:
- New detection capabilities added
- New parameters added
- Backward compatible enhancements

Example: `3.0.0` → `3.1.0` (added power anomaly detection)

### PATCH (Bug Fixes)
Update when:
- Bug fixes
- Performance improvements
- Minor adjustments
- No new features

Example: `3.1.0` → `3.1.1`

---

## Current Deployment Status

### Recently Deployed (This Session)

✅ **PowerSupplyParser 3.1.0**
- Files: `src/Parsers/PowerSupplyParser.php`
- Changes: Robust file discovery with `locateFile()` helper
- Why: Fixes "0 power analysis(s)" when file paths vary
- Commit: `24ca24f`

✅ **FileAvailabilityValidator 1.0.0**
- Files: `src/DeepDive/Parsers/FileAvailabilityValidator.php`
- Changes: Pre-flight validation, graceful degradation
- Why: Provides transparency about data completeness
- Commit: `24ca24f`

✅ **ParseStep 2.1.0**
- Files: `src/DeepDive/Pipeline/ParseStep.php`
- Changes: Integrated FileAvailabilityValidator
- Why: Validates files before parsing
- Commit: `24ca24f`

✅ **RenderStep 3.1.0**
- Files: `src/DeepDive/Pipeline/RenderStep.php`, `RenderStepFixed.php`
- Changes: Updated report version to 3.1.0
- Why: Reflects new power anomaly detection
- Previous: `3.0.0`

---

## Verification Commands

### Check Versions Registered

```bash
php artisan tinker
> use App\DeepDive\Logging\VersionRegistry;
> VersionRegistry::summary()
```

Expected output:
```
=== Component Versions ===

Pipeline:
  PipelineLogger: 1.0.0
  VersionRegistry: 1.0.0
  LogExporter: 1.0.0

Parsers:
  PowerSupplyParser: 3.1.0
  HardwareSpecExtractor: 1.5.0
  ...
```

### Check Logs After Job Completion

```bash
# View latest job logs
find logs/deepdive -type f -name "audit.json" -newer /tmp/timestamp | head -1 | xargs cat | jq '.component_versions'

# Check if specific version was logged
cat logs/{JOBID}/audit.json | jq '.component_versions | keys[]'

# Expected: Should show all components registered during execution
```

### Verify Deployment Success

```bash
# After deploying code, run a test job and check:

# 1. PowerSupplyParser found files correctly
cat logs/{JOBID}/audit.json | jq '.entries[] | select(.message | contains("File Extracted"))'

# 2. FileAvailabilityValidator ran
cat logs/{JOBID}/audit.json | jq '.entries[] | select(.level=="VALIDATION")'

# 3. Correct versions registered
cat logs/{JOBID}/audit.json | jq '.component_versions | to_entries[] | {component: .key, version: .value.version}'

# Should show:
# { "component": "PowerSupplyParser", "version": "3.1.0" }
# { "component": "FileAvailabilityValidator", "version": "1.0.0" }
```

---

## Troubleshooting Version Mismatches

### Problem: Log shows old version (e.g., PowerSupplyParser 3.0.0)

**Cause**: Code wasn't deployed correctly

**Solution**:
1. Verify file was copied to correct location:
   ```bash
   grep -n "version 3.1.0\|report_version.*3\.1\.0" /var/www/ai-debugscan3/src/Parsers/PowerSupplyParser.php
   ```

2. Check application cache:
   ```bash
   php artisan cache:clear
   php artisan config:cache
   ```

3. Restart application server:
   ```bash
   systemctl restart php-fpm  # or your app server
   ```

4. Verify again by running test job

### Problem: Logging not appearing in logs

**Cause**: Logger not integrated into pipeline

**Solution**:
1. Check PipelineContext has logger initialized:
   ```php
   if (!isset($ctx->logger)) {
       echo "Logger not initialized in context";
   }
   ```

2. Verify LogExporter is saving files:
   ```bash
   ls -la logs/deepdive/
   ```

3. Check file permissions on log directory:
   ```bash
   stat logs/deepdive/
   ```

### Problem: Component version not showing

**Cause**: Component not registered in VersionRegistry

**Solution**:
1. Add registration call in initialization:
   ```php
   $logger->registerComponentVersion('ComponentName', '1.0.0');
   ```

2. Verify it's in VersionRegistry::$versions:
   ```php
   VersionRegistry::register('ComponentName', '1.0.0');
   ```

---

## Deployment Checklist

Before deploying code changes:

- [ ] Update version number in VersionRegistry
- [ ] Update report_version if output format changed
- [ ] Add version comment to modified class
- [ ] Commit with clear message including version
- [ ] Run test job with new code
- [ ] Check logs show new version
- [ ] Verify power analysis runs (if power related)
- [ ] Verify file extraction (if parser related)
- [ ] Check HTML report renders correctly
- [ ] Verify no errors in JSON log

---

## Release Notes Format

When releasing new version:

```markdown
## Version X.Y.Z - YYYY-MM-DD

### Components Updated
- PowerSupplyParser: 3.0.0 → 3.1.0
- FileAvailabilityValidator: (new) 1.0.0

### Changes
- Added robust file discovery for power supply data
- Added pre-flight file validation
- Updated report format for power anomaly detection

### Deployment
Files to deploy:
- src/Parsers/PowerSupplyParser.php
- src/DeepDive/Parsers/FileAvailabilityValidator.php
- src/DeepDive/Pipeline/ParseStep.php

Steps:
1. Copy files to /var/www/ai-debugscan3/
2. Clear application cache
3. Run test job
4. Verify log shows correct versions

### Verification
Check logs for:
- PowerSupplyParser: 3.1.0
- FileAvailabilityValidator: 1.0.0
- Files extracted: >100 count
- Validation passed: yes
```

---

## Files With Version Numbers

Update these files when version changes:

1. `src/DeepDive/Logging/VersionRegistry.php` - Master version list
2. `src/DeepDive/Pipeline/RenderStep.php` - Report version string
3. `CHANGELOG.md` - Release notes
4. Class docblocks - Component version comment
5. `DEPLOYMENT_GUIDE.md` - Version numbers for verification
