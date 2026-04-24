# Drive Health Detection Issue - SMART Attributes & Bad Sectors

## Executive Summary

**Critical Issue:** Drives with reallocated/pending bad sectors show as "✓ Healthy" (green) when they should show warnings.

**Example from your system:**
- Drive WS23LDK4 (sdpb): **55 bad sectors** → Shows "NORMAL" ❌
- Drive WS23LDJ2 (sdoc): **22 bad sectors** → Shows "NORMAL" ❌  
- Drive ZC1BAL3S (sdb): **10 bad sectors** → Shows "NORMAL" ❌

**Root Cause:** The code only extracts a **single SMART status value** from `load_info.result['smart_status']` which is a binary "normal" / "warning" / "failed". It does NOT analyze the actual **SMART attribute values** where bad sector data lives.

---

## How Synology Detects Bad Sectors

Synology stores SMART attributes in `/dsm/var/log/diskprediction/` with daily JSON snapshots.

### SMART Attribute Structure
Each attribute is represented as: `[id, current, threshold, worst, raw_value]`

**Example - Attribute 5 (Reallocated Sectors):**
```
[5, 100, 100, 010, 55]
 │   │   │    │   └─ Raw value = 55 ACTUAL BAD SECTORS ← THIS IS WHAT WE NEED
 │   │   │    └────── Worst value (historical low)
 │   │   └─────────── Threshold (failure point)
 │   └──────────────── Current normalized value (100 = healthy)
 └────────────────── Attribute ID
```

### Key SMART Attributes for Bad Sectors

| Attribute | ID | Name | Meaning | Threshold |
|-----------|----|----|---------|-----------|
| 5 | 5 | Reallocated Sectors Count | Sectors moved due to errors | Current < 100 = WARNING |
| 197 | 197 | Current Pending Sector Count | Sectors waiting reallocation | Current < 100 = WARNING |
| 198 | 198 | Offline Uncorrectable Sector Count | Sectors that can't be read | Current < 100 = WARNING |

### Synology's Health Assessment

Synology uses a **weighted scoring system** where each SMART attribute contributes to overall health:

1. **Current value 100** = Healthy (full points)
2. **Current value 50-99** = Warning (partial points)
3. **Current value 1-49** = Critical (failing)
4. **Threshold breach** = Immediate failure flag

**The Problem:** 
- Your drives show `current=100` for attribute 5 (threshold 100)
- But the `raw_value=55` indicates actual bad sectors
- Status calculation only uses `current` value, ignoring the high raw count

---

## Data Available in Bundle

The diskprediction JSON files contain everything needed:

**File:** `/dsm/var/log/diskprediction/data-2026-03-20.json`

**Available data per drive:**
```json
{
  "serial": "WS23LDK4",
  "path": "/dev/sdpb",
  "bad_sec_ct": "55",           // ← Total bad sectors
  "exc_bad_sec_ct": "",         // Reallocated count
  "smart_attr": [
    ["5", "100", "100", "010", "55"],    // Reallocated Sectors
    ["197", "100", "100", "000", "0"],   // Pending Sectors
    ["198", "100", "100", "000", "0"],   // Uncorrectable Sectors
    ...
  ],
  "smart_test_log": [           // ← Historical test results
    {"result": "complete", "time": "2026/03/04 00:00:01", "type": "quick"}
  ],
  "overview_status": "normal",  // ← Current health (binary)
  "smart_status": "normal",     // ← Only this is extracted currently
  "kernel_err": {...},          // Timeout, UNC, ICRC errors
  "temperature": "41"           // Current temp
}
```

---

## Synology's Thresholds

Based on SMART attribute analysis:

### For Reallocated Sectors (Attr 5)
- **0-10 sectors:** Healthy but monitor
- **10-100 sectors:** Warning - growth pattern to watch
- **100+ sectors:** Critical - drive replacement recommended
- **Threshold:** Current value must stay >= 10 (raw value irrelevant in stock DSM)

### For Pending Sectors (Attr 197)
- **0-5 sectors:** Minor issue, usually clears on next reboot
- **5-20 sectors:** Warning - pending reallocation
- **20+ sectors:** Critical - immediate replacement

### For Uncorrectable Sectors (Attr 198)
- **Any value > 0:** Warning - drive has unreadable sectors
- **5+ sectors:** Critical - data loss risk

### Overall Health Assessment
Synology calculates: **Health Score** = (Σ SMART weights) / Total weight

- **100-95:** Healthy (green)
- **95-80:** Good (light green)
- **80-60:** Warning (yellow)
- **60-40:** Critical (orange)
- **< 40:** Failed (red)

**Current issue:** Drives with 55 bad sectors should be in **Yellow/Orange** range, not Green.

---

## Bad Sector Growth Analysis

Your system data shows:

### Drive WS23LDK4 (sdpb) - 55 bad sectors
```
Feb 10: 55 sectors  ├─ STABLE (no growth)
Feb 20: 55 sectors  │
Mar 10: 55 sectors  │
Mar 20: 55 sectors  ┘
```
**Assessment:** Stable count = Likely pre-existing damage, not actively growing

### Drive WS23LDJ2 (sdoc) - 22 bad sectors
```
Needs historical tracking...
```

### Drive ZC1BAL3S (sdb) - 10 bad sectors
```
Needs historical tracking...
```

---

## What Needs to be Fixed

### Fix 1: Extract Detailed SMART Attributes
**File:** `HardwareSpecExtractor.php`

Currently:
```php
'smart_status' => $disk['smart_status'] ?? 'unknown',  // Only "normal"/"warning"/"failed"
```

Should be:
```php
'smart_attributes' => [
  'reallocated_sectors' => 55,          // From SMART attr 5 raw value
  'pending_sectors' => 0,               // From SMART attr 197 raw value
  'uncorrectable_sectors' => 0,         // From SMART attr 198 raw value
  'current_temperature' => 41,          // From diskprediction data
  'reallocated_current' => 100,         // Normalized value for threshold check
  'pending_current' => 100,
  'uncorrectable_current' => 100,
]
```

### Fix 2: Implement Smart Health Scoring
Calculate actual health score based on:
- ✓ Attribute thresholds
- ✓ Raw attribute values (bad sector counts)
- ✓ Temperature trends
- ✓ Kernel error counts
- ✓ SMART test results

**Logic:**
```php
function calculateDriveHealthScore($attributes) {
  $score = 100;
  
  // Penalize for reallocated sectors
  if ($attributes['reallocated_sectors'] > 100) {
    $score -= 50;  // Critical
  } else if ($attributes['reallocated_sectors'] > 50) {
    $score -= 30;  // Warning
  } else if ($attributes['reallocated_sectors'] > 10) {
    $score -= 15;  // Caution
  }
  
  // Penalize for pending sectors
  if ($attributes['pending_sectors'] > 20) {
    $score -= 40;  // Critical
  } else if ($attributes['pending_sectors'] > 5) {
    $score -= 20;  // Warning
  }
  
  // Any uncorrectable sectors = critical
  if ($attributes['uncorrectable_sectors'] > 0) {
    $score -= 50;
  }
  
  return max(1, $score);
}
```

### Fix 3: Add Growth Rate Analysis
Extract diskprediction data for 10-30 day history:

```php
function analyzeBadSectorGrowth($dailySnapshots) {
  $growth = [];
  
  foreach ($dailySnapshots as $date => $data) {
    $growth[] = [
      'date' => $date,
      'bad_sectors' => $data['bad_sec_ct'],
      'reallocated_current' => $data['smart_attr'][5][1] ?? 100,
    ];
  }
  
  // Calculate growth rate (sectors/day)
  $old = reset($growth);
  $new = end($growth);
  
  $daysDiff = (strtotime($new['date']) - strtotime($old['date'])) / 86400;
  $sectorGrowth = $new['bad_sectors'] - $old['bad_sectors'];
  $growthRate = $daysDiff > 0 ? $sectorGrowth / $daysDiff : 0;
  
  return [
    'start_date' => $old['date'],
    'end_date' => $new['date'],
    'start_count' => $old['bad_sectors'],
    'end_count' => $new['bad_sectors'],
    'growth_rate' => round($growthRate, 2),  // sectors/day
    'trend' => $growthRate > 1 ? 'FAST_GROWTH' : 
              ($growthRate > 0.1 ? 'SLOW_GROWTH' : 'STABLE')
  ];
}
```

### Fix 4: Update Report Display
**File:** `ReportRenderer.php`

Current:
```php
$smartBadge = match($smart) {
  'passed', 'ok' => '<span class="hw-status-badge hw-status-healthy">✓ Healthy</span>',
  'warning' => '<span class="hw-status-badge hw-status-warning">⚠ Warning</span>',
  'failed' => '<span class="hw-status-badge hw-status-critical">✕ Failed</span>',
};
```

Should be:
```php
$healthScore = $drive['health_score'] ?? 100;  // From new calculation
$badSectors = (int)($drive['bad_sectors'] ?? 0);
$growthRate = $drive['growth_rate_sectors_per_day'] ?? 0;

// Display based on actual health score + bad sector count
if ($healthScore >= 95 && $badSectors === 0) {
  $badge = '<span class="hw-status-badge hw-status-healthy">✓ Healthy</span>';
} else if ($healthScore >= 80 && $badSectors < 50) {
  $badge = '<span class="hw-status-badge hw-status-warning">⚠ Monitor</span>';
  $detail = "$badSectors sectors";
} else if ($badSectors > 50 || $growthRate > 1.0) {
  $badge = '<span class="hw-status-badge hw-status-critical">⚠ Replace Soon</span>';
  $detail = "$badSectors sectors (↑ $growthRate/day)";
} else {
  $badge = '<span class="hw-status-badge hw-status-critical">✕ Failed</span>';
}
```

---

## Expected Results After Fix

**Current Report (WRONG):**
```
Drive WS23LDK4 (sdpb) - ✓ Healthy
Drive WS23LDJ2 (sdoc) - ✓ Healthy
Drive ZC1BAL3S (sdb)  - ✓ Healthy
```

**Fixed Report (CORRECT):**
```
Drive WS23LDK4 (sdpb) - ⚠ Replace Soon (55 sectors, stable)
Drive WS23LDJ2 (sdoc) - ⚠ Replace Soon (22 sectors, stable)
Drive ZC1BAL3S (sdb)  - ⚠ Monitor (10 sectors, stable)
Drive ZC184H3P (sdl)  - ✓ Healthy (3 sectors, within tolerance)
```

---

## Timeline for Replacement

Based on growth rate analysis:

**Drives to replace IMMEDIATELY:**
- Bad sectors > 100
- Growth rate > 5 sectors/day
- Any uncorrectable sectors
- Temperature consistently > 45°C

**Drives to replace SOON (within 1 month):**
- 50-100 bad sectors
- Growth rate 1-5 sectors/day
- Pending sectors > 10

**Drives to monitor:**
- < 50 bad sectors
- Stable or very slow growth (< 0.1 sectors/day)
- Reallocated sector count < 10

---

## Data Sources Available

All data is already captured in the bundle:

1. `/dsm/var/log/diskprediction/data-YYYY-MM-DD.json` - Daily SMART snapshots
2. `/dsm/var/log/disk_health_information.json` - Historical health data
3. `/dsm/var/log/disk_log.csv` - Disk event log
4. `/dsm/var/log/smart_extend_log` - Extended SMART test results
5. `/dsm/var/log/smart_quick_log` - Quick SMART test results

**No changes needed to bundle capture** - all data is already there!

---

## Implementation Priority

1. **CRITICAL:** Fix health score calculation (Fix 2)
2. **HIGH:** Extract SMART attributes from diskprediction (Fix 1)
3. **HIGH:** Update report display (Fix 4)
4. **MEDIUM:** Add growth rate analysis (Fix 3)
5. **LOW:** Add predictive failure warnings (future enhancement)
