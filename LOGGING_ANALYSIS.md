# DeepDive Logging & Power Data Extraction Analysis

## Executive Summary

**ROOT CAUSE**: The logging system and power data extraction are disconnected:
1. **No pipeline step logging** - Pipeline steps don't call the audit logger
2. **Silent errors** - Exceptions in PowerSupplyParser are caught but not logged to audit logger
3. **No visibility** - Missing files/parsing failures go to error_log(), not audit trail
4. **Zero logs** - Result: logs directory appears empty even when jobs complete

---

## Issue #1: Audit Logger Not Used by Pipeline Steps

### Current State
- `ParseStep.php` line 173: Errors logged to `error_log()` instead of `$ctx->bag['audit_logger']`
- No pipeline step calls `logStepStart()`, `logFileExtracted()`, or `logDataParsing()`
- Audit logger initialized in Daemon but never used by the 8 pipeline steps

### Impact
- **Zero event data** in audit logs even though jobs complete
- Logs contain only metadata (job_id, timestamps) with no details
- No visibility into what happened during pipeline execution

### Evidence
```php
// ParseStep.php:173
error_log("PowerSupplyParser error for {$base}: " . $e->getMessage());
// Should be:
$auditLogger->logError("PowerSupplyParser error...", 'power_parser', [...]);
```

---

## Issue #2: Power Data Extraction Fails Silently

### Current State
```php
// ParseStep.php:156-175
try {
    $psuResult = $powerParser->parse($base, ...);
    // ... store power data
} catch (\Throwable $e) {
    error_log("PowerSupplyParser error...");  // ← Only logs to PHP error_log
    $bundle['power_data'] = null;               // ← Silent failure
}
```

### Why Power Data Count Shows "0"
- `PowerSupplyParser::parse()` can fail in multiple ways:
  1. **File not found** - `locateFile()` returns null, returns empty array
  2. **Parse exception** - Caught and logged to error_log (invisible)
  3. **Empty data** - Parser returns valid but empty results

- When exception occurs, no data is added to `$powerData` array
- Result: `count($powerData) === 0` on line 210

### Current Error Logging
```
✗ Location: error_log() (PHP error log)
✗ Visibility: Not in audit logs
✗ Tracking: No job correlation
✗ Analysis: Lost when logs rotate
```

---

## Issue #3: No File Discovery Logging

### PowerSupplyParser File Search
```php
// PowerSupplyParser.php:268-274
$dmiFile = $this->locateFile($extractedPath, [
    'dsm/result/dmidecode.result',
    'result/dmidecode.result',
    'dmidecode.result',
    '*/result/dmidecode.result',
    '*/dmidecode.result',
]);
```

### Problem
- **No logging** when file is found/not found
- **No visibility** into search process
- **Silent returns** when patterns don't match

### Impact
- Can't diagnose why power data extraction shows "0 analyses"
- No audit trail of file search attempts
- Impossible to verify if files exist in bundle

---

## Required Fixes

### Fix #1: Integrate Audit Logger into Pipeline Steps

**Location**: Each pipeline step (8 total)

```php
// In each step's run() method
$auditLogger = $ctx->bag['audit_logger'] ?? null;

// At step start
if ($auditLogger) {
    $auditLogger->logStepStart('step_id', 'Starting step...');
}

// During processing
if ($auditLogger) {
    $auditLogger->logFileExtracted($file, $size, $category);
    $auditLogger->logDataParsing($parser, $source, $count);
}

// At step complete
if ($auditLogger) {
    $auditLogger->logStepComplete('step_id', 'Complete', ['metric' => $value]);
}
```

### Fix #2: Log Power Data Extraction Properly

**Location**: `ParseStep.php` lines 155-175

```php
try {
    $psuResult = $powerParser->parse($base, ['hardware' => $hardwareSpec]);
    
    if ($auditLogger) {
        $auditLogger->logDataParsing(
            'PowerSupplyParser',
            'DMI/IPMI power data',
            count($psuResult['data'] ?? [])
        );
    }
    
    $powerData[] = [...];
} catch (\Throwable $e) {
    if ($auditLogger) {
        $auditLogger->logError(
            'PowerSupplyParser failed: ' . $e->getMessage(),
            'power_parser_exception',
            ['file' => $base, 'exception' => get_class($e)]
        );
    }
    $bundle['power_data'] = null;
}
```

### Fix #3: Add File Discovery Logging

**Location**: `PowerSupplyParser.php` `locateFile()` and parsing methods

```php
// Before searching
if ($auditLogger) {
    $auditLogger->logDebug('Searching for dmidecode.result', [...patterns...]);
}

// After finding
if ($dmiFile) {
    if ($auditLogger) {
        $auditLogger->logFileExtracted($dmiFile, filesize($dmiFile), 'power_data');
    }
} else {
    if ($auditLogger) {
        $auditLogger->logWarning('dmidecode.result not found', [...patterns...]);
    }
}
```

### Fix #4: Ensure Audit Logger is Available to All Steps

**Currently**: Stored in `$ctx->bag['audit_logger']` but not initialized by steps

**Change**: Pass as constructor parameter or make available via context

```php
// In PipelineContext or each step
private ?PipelineLogger $auditLogger = null;

public function setAuditLogger(PipelineLogger $logger): self {
    $this->auditLogger = $logger;
    return $this;
}
```

---

## Testing Strategy

### After Fixes

1. **Run a test job**
   ```
   POST /deepdive/start/{projectId}
   ```

2. **Wait for completion**, then check:
   ```
   GET /api/deepdive/logs/list
   ```

3. **Verify logs generated**:
   ```bash
   ls -la storage/logs/deepdive/{jobId}/
   # Should show: audit.json, report.html, manifest.json
   ```

4. **Validate audit events**:
   ```bash
   curl http://localhost/api/deepdive/logs/{jobId} | jq '.data.entries | length'
   # Should show > 0 (was 0 before)
   ```

5. **Check power data logged**:
   ```bash
   curl http://localhost/api/deepdive/logs/{jobId} | jq '.data.entries[] | select(.data.parser=="PowerSupplyParser")'
   # Should show power parser events
   ```

---

## Implementation Priority

1. **Phase 1**: Add audit logger to ParseStep (critical for power data)
2. **Phase 2**: Add audit logger to remaining 7 pipeline steps
3. **Phase 3**: Add detailed logging to PowerSupplyParser file discovery
4. **Phase 4**: Add version registry logging for deployment verification

---

## Expected Result After Fixes

### Before
```
GET /deepdive/logs
→ No logs found (0 total)
```

### After
```
GET /deepdive/logs
→ List of completed jobs with:
  - Audit JSON with 50+ events
  - HTML report showing pipeline execution
  - Manifest with file metadata
  - Power data extraction details
```

