# Power Anomaly Detection - Deployment Guide

## Problem Identified

Your production system at `/var/www/ai-debugscan3/` **does not have the code changes** needed for power anomaly detection to work.

The changes were made in the development environment, but haven't been deployed to your running system.

---

## Files to Deploy

### 1. PowerSupplyParser.php
**Location on your system**: `/var/www/ai-debugscan3/src/Parsers/PowerSupplyParser.php`

**Key Changes**:
- Lines 140-155: Added fallback log scanning when primary power data unavailable
- Lines 524-568: Fixed assessPowerHealth() to return correct status based on data availability
- Lines 685-750: Added detectPowerEventsFallback() method for log scanning

**What It Does**:
- Detects when there's insufficient power data
- Returns 'caution' with 'insufficient_data' flag instead of false 'healthy'
- Scans logs if dmidecode/IPMI files unavailable

### 2. AnomalyDetector.php
**Location on your system**: `/var/www/ai-debugscan3/src/DeepDive/AI/AnomalyDetector.php`

**Key Changes**:
- Lines 180-198: Updated caution status handling
- Checks assessment_status flag to avoid false positives
- Only creates anomalies based on actual power data

**What It Does**:
- Skips false 'caution' anomalies from insufficient data
- Creates real anomalies when actual power issues found

---

## Deployment Steps

### Step 1: Backup Current Files
```bash
cd /var/www/ai-debugscan3/

# Backup originals
cp src/Parsers/PowerSupplyParser.php src/Parsers/PowerSupplyParser.php.backup
cp src/DeepDive/AI/AnomalyDetector.php src/DeepDive/AI/AnomalyDetector.php.backup
```

### Step 2: Get Updated Files

The updated files are located in this development environment:
- `/sessions/brave-vigilant-cannon/mnt/ai-debugscan3/PowerSupplyParser.php.UPDATED`
- `/sessions/brave-vigilant-cannon/mnt/ai-debugscan3/AnomalyDetector.php.UPDATED`

**Option A: Copy from dev environment** (if accessible)
```bash
cp PowerSupplyParser.php.UPDATED /var/www/ai-debugscan3/src/Parsers/PowerSupplyParser.php
cp AnomalyDetector.php.UPDATED /var/www/ai-debugscan3/src/DeepDive/AI/AnomalyDetector.php
```

**Option B: Manual merge** (if only source code available)
See "Changes Details" below

### Step 3: Verify Files
```bash
# Check that changes are in place
grep -c "detectPowerEventsFallback" /var/www/ai-debugscan3/src/Parsers/PowerSupplyParser.php
# Should return: 1

grep -c "hasAnyPowerData" /var/www/ai-debugscan3/src/Parsers/PowerSupplyParser.php
# Should return: 1

grep -c "insufficient_data" /var/www/ai-debugscan3/src/DeepDive/AI/AnomalyDetector.php
# Should return: 2 or more
```

### Step 4: Clear Cache (if applicable)
```bash
# If your application has cache:
php artisan cache:clear  # Laravel
# Or restart your application server
```

### Step 5: Test Deployment
Generate a new report with a bundle containing power supply data:
- Should show: **Anomalies: 1+** (not 0)
- Should show: **Risk Level: CRITICAL or HIGH** (not LOW)

---

## Change Details

### PowerSupplyParser.php - Lines 140-155

**Find this**:
```php
        // 4.5. Extract model for PSU configuration detection
        $model = $this->getModelFromContext($context);

        // 5. Perform health assessment and risk analysis
        $assessment = $this->assessPowerHealth($data, $model);
        $data['health_assessment'] = $assessment;

        return [
            'data' => $data,
            'citations' => $citations
        ];
```

**Replace with**:
```php
        // 4.5. Extract model for PSU configuration detection
        $model = $this->getModelFromContext($context);

        // 5. FALLBACK: If no primary power data available, scan logs for power-related errors
        $hasAnyPowerData = !empty($data['power_supplies'] ?? [])
            || !empty($data['voltage_readings'] ?? [])
            || !empty($data['current_readings'] ?? [])
            || !empty($data['power_events'] ?? [])
            || !empty($data['system_power_status'] ?? []);

        if (!$hasAnyPowerData) {
            // Try to detect power issues from kernel/system logs
            $logEvents = $this->detectPowerEventsFallback($extractedPath);
            if ($logEvents['data']) {
                $data['power_events'] = $logEvents['data'];
                $citations = array_merge($citations, $logEvents['citations']);
            }
        }

        // 6. Perform health assessment and risk analysis
        $assessment = $this->assessPowerHealth($data, $model);
        $data['health_assessment'] = $assessment;

        return [
            'data' => $data,
            'citations' => $citations
        ];
```

### PowerSupplyParser.php - Lines 524-568

**Find this**:
```php
    private function assessPowerHealth(array $data, ?string $model = null): array
    {
        $assessment = [
            'overall_status' => 'healthy',
            'redundancy_status' => 'none',
            'risk_factors' => [],
            'requires_attention' => false,
            'model' => $model,
            'expected_psu_count' => null,
            'actual_psu_count' => 0
        ];
```

**Replace with**:
```php
    private function assessPowerHealth(array $data, ?string $model = null): array
    {
        // Check if we have any power supply data to assess
        $hasPowerSupplyData = !empty($data['power_supplies'] ?? [])
            || !empty($data['voltage_readings'] ?? [])
            || !empty($data['current_readings'] ?? [])
            || !empty($data['power_events'] ?? [])
            || !empty($data['system_power_status'] ?? []);

        // Default status: 'healthy' if we have data to assess, 'caution' if insufficient data
        $defaultStatus = $hasPowerSupplyData ? 'healthy' : 'caution';

        $assessment = [
            'overall_status' => $defaultStatus,
            'redundancy_status' => 'none',
            'risk_factors' => [],
            'requires_attention' => !$hasPowerSupplyData,  // Flag as requiring attention if we have no data
            'model' => $model,
            'expected_psu_count' => null,
            'actual_psu_count' => 0,
            'has_power_data' => $hasPowerSupplyData,  // Track whether assessment is based on actual data
            'assessment_status' => $hasPowerSupplyData ? 'based_on_data' : 'insufficient_data'
        ];

        // If we have no power supply data and no other power information, add a caution message
        if (!$hasPowerSupplyData) {
            $assessment['risk_factors'][] = 'Power supply monitoring data not available - cannot fully assess power health';
        }
```

### PowerSupplyParser.php - Add New Method at End (before closing brace)

Add this method before the last `}` of the class:

```php
    /**
     * FALLBACK: Detect power events from kernel/system logs when IPMI data unavailable
     *
     * Scans kern.log, messages, and other logs for power-related errors:
     * - Power supply failures
     * - Voltage issues
     * - Power cycling events
     * - Thermal/power correlation
     *
     * @param string $extractedPath Bundle directory
     *
     * @return array{data: array, citations: array}
     */
    private function detectPowerEventsFallback(string $extractedPath): array
    {
        $events = [];
        $citations = [];

        $logFiles = [
            'dsm/log/kern.log',
            'dsm/log/messages',
            'dsm/log/scemd',
            'var/log/kern.log',
            'var/log/messages',
        ];

        $powerKeywords = [
            'power\s+(?:supply|failure|fail|issue)',
            'psu\s+(?:fail|error|critical)',
            'voltage\s+(?:out|low|high|critical)',
            'power\s+(?:loss|lost|outage|cycle)',
            'supply\s+(?:error|fault|fail)',
        ];

        foreach ($logFiles as $relPath) {
            $filePath = $extractedPath . '/' . $relPath;
            if (!file_exists($filePath)) continue;

            $handle = fopen($filePath, 'r');
            if (!$handle) continue;

            $lineNum = 0;
            while (($line = fgets($handle)) !== false && $lineNum < 10000) {
                $lineNum++;

                // Check for power-related keywords
                $hasPowerKeyword = false;
                foreach ($powerKeywords as $keyword) {
                    if (preg_match('/' . $keyword . '/i', $line)) {
                        $hasPowerKeyword = true;
                        break;
                    }
                }

                if (!$hasPowerKeyword) continue;

                // Extract timestamp if present
                $timestamp = date('Y-m-d H:i:s'); // Fallback
                if (preg_match('/(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/', $line, $m)) {
                    $timestamp = $m[1];
                }

                $events[] = [
                    'timestamp'   => $timestamp,
                    'event_type'  => 'power_event_log',
                    'severity'    => preg_match('/(critical|error|fail)/i', $line) ? 'critical' : 'warning',
                    'description' => trim(substr($line, 0, 200)),
                ];
            }
            fclose($handle);

            if (!empty($events)) {
                $citations[] = [
                    'file'      => $relPath,
                    'lines'     => '1-' . $lineNum,
                    'timestamp' => date('Y-m-d H:i:s', filemtime($filePath) ?: time()),
                    'source'    => 'fallback_log_scan'
                ];
            }
        }

        return [
            'data'      => $events,
            'citations' => $citations
        ];
    }
}
```

### AnomalyDetector.php - Lines 180-198

**Find this**:
```php
                } elseif ($health['overall_status'] === 'caution') {
                    $anomalies[] = [
                        'timestamp'    => date('Y-m-d H:i:s'),
                        'type'         => 'PSU_HEALTH_CAUTION',
                        'severity'     => 'MEDIUM',
                        'confidence'   => 0.75,
                        'message'      => 'Power supply health assessment: CAUTION - ' . implode(', ', $health['risk_factors'] ?? []),
                    ];
                    $issues[] = 'Power supply caution';
                }
```

**Replace with**:
```php
                } elseif ($health['overall_status'] === 'caution') {
                    // Only report caution if it's based on actual data issues
                    // Skip if it's just indicating insufficient data
                    $assessmentStatus = $health['assessment_status'] ?? 'based_on_data';
                    if ($assessmentStatus !== 'insufficient_data') {
                        $anomalies[] = [
                            'timestamp'    => date('Y-m-d H:i:s'),
                            'type'         => 'PSU_HEALTH_CAUTION',
                            'severity'     => 'MEDIUM',
                            'confidence'   => 0.75,
                            'message'      => 'Power supply health assessment: CAUTION - ' . implode(', ', $health['risk_factors'] ?? []),
                        ];
                        $issues[] = 'Power supply caution';
                    } else {
                        $this->log('debug', 'Power supply data not available in bundle - cannot assess health');
                    }
                }
```

---

## Verification Checklist

After deployment, verify:

- [ ] Files copied to correct locations
- [ ] Changed code verified with grep commands
- [ ] Application cache cleared (if applicable)
- [ ] New report generated with power data bundle
- [ ] Report shows Anomalies > 0 (not 0)
- [ ] Report shows Risk Level: CRITICAL/HIGH (not LOW)
- [ ] Power issues properly detected and reported

---

## Rollback Plan

If issues occur, restore the backups:
```bash
cp src/Parsers/PowerSupplyParser.php.backup src/Parsers/PowerSupplyParser.php
cp src/DeepDive/AI/AnomalyDetector.php.backup src/DeepDive/AI/AnomalyDetector.php
```

---

## Support

If you encounter any issues:
1. Check file locations are correct
2. Verify grep commands show changes are in place
3. Check application logs for errors
4. Clear all caches and restart application server
5. Test with a known bundle containing power supply data

The updated files are in this workspace for your reference.
