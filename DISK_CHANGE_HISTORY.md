# Disk Change History Analysis

**System:** RS3617RPxs + 2x RX1217rp Expansion Units  
**Log Coverage:** Oct 2018 - Mar 2026 (7+ years)  
**Analysis Date:** April 24, 2026

---

## Executive Summary

Your system has **significant drive replacement history** with **5 documented replacements** across the main unit and expansion units. All replacements show drives being swapped out at the same slot for maintenance, upgrades, or failures.

---

## Recent Drive Changes (Last 2 Years)

### Most Recent: March 10, 2026
```
RX1217rp-1 Slot 2: WS23LDH7 plugged in
  Previous drive: ZC11M9S0 (removed)
  Time since last change: ~2 years
  Status: Active
```

### April 29, 2025
```
RS3617RPxs (Main) Slot 5: WS23LD71 plugged in
  Previous drive: ZC17TJHN (removed)
  Reason: Drive replacement
```

### March 5, 2024
```
RS3617RPxs (Main) Slot 2: ZC1BAL3S plugged in
  Previous drive: ZC12RK95 (removed)
  ⚠️ NOTE: This is the drive currently showing 10 bad sectors
  Status: Currently installed
```

---

## Complete Drive Replacement History

### Slot-by-Slot Timeline

#### Main Unit (RS3617RPxs)

**Slot 1 (Bay 1):**
```
2019/02/11 - ZC124E4Y installed (ST4000NM0115-1V4107)
2019/02/04 - Replaced ZC12RF2Y
```

**Slot 2 (Bay 2):**
```
2024/03/05 - ZC1BAL3S installed (ST4000NM0035-1V4107) ⚠️ [10 bad sectors]
1970/01/01 - Replaced ZC12RK95
```

**Slot 5 (Bay 5):**
```
2025/04/29 - WS23LD71 installed (ST4000NM002A)
1970/01/01 - Replaced ZC17TJHN
```

**Other Main Slots:** No replacements detected

#### Expansion Unit 1 (RX1217rp-1)

**Slot 2:**
```
2026/03/10 - WS23LDH7 installed (ST4000NM002A) ✓ [NEWEST]
1970/01/01 - Replaced ZC11M9S0
1970/01/01 - Replaced ZC125295
```

**Slot 3:**
```
2023/11/29 - ZC11XPD8 installed (ST4000NM0035-1V4107)
2020/07/24 - Replaced ZC13NZ2G
```

**Other Expansion 1 Slots:** No replacements detected

#### Expansion Unit 2 (RX1217rp-2)
```
✓ No drive replacements - All original drives since installation (2024)
```

---

## Drive Age Analysis

### Current Drives in System (36 total)

**Newest Drives (< 1 year):**
- 2026/03/10: WS23LDH7 (RX1217rp-1 Slot 2) - 14 days old
- 2025/04/29: WS23LD71 (RS3617RPxs Slot 5) - 11 months old

**1-2 Years Old:**
- 2024/03/05: ZC1BAL3S (RS3617RPxs Slot 2) - 1.0 years ⚠️ [10 bad sectors]

**2-7 Years Old:**
- All 12 RX1217rp-2 drives: ~2 years (purchased together 2024)
- Multiple RX1217rp-1 drives: 3-6 years old

**Oldest Remaining:**
- Most main unit drives: 5-7 years old (installed 2018-2019)

---

## Problem Drives & Replacements

### Drive ZC1BAL3S - Current Issue

**Current Status:**
- **Serial:** ZC1BAL3S
- **Device:** /dev/sdb
- **Location:** RS3617RPxs (Main), Slot 2
- **Model:** ST4000NM0035-1V4107
- **Installed:** March 5, 2024 (1.0 year ago)
- **Bad Sectors:** 10 (stable, not growing)
- **Status:** ⚠️ Monitor - recommend replacement within 6-12 months

**History:**
```
Replaced drive ZC12RK95 (which was failing)
Current drive is relatively new but already showing wear
Bad sectors are old/stable (not actively growing)
Safe to continue operating with monitoring
```

### Recently Replaced Drives

**Latest Replacement (Most Recent):**
- **Old drive:** ZC11M9S0 → **New drive:** WS23LDH7
- **Location:** RX1217rp-1, Slot 2
- **Date:** March 10, 2026 (14 days ago)
- **Reason:** Likely preventive replacement or wear-out
- **Status:** New drive healthy

**Previous Replacement (1 year ago):**
- **Old drive:** ZC17TJHN → **New drive:** WS23LD71
- **Location:** RS3617RPxs, Slot 5
- **Date:** April 29, 2025
- **Status:** Performing well

---

## Error & Issue History

### Error Events in Last 30 Days
```
None detected in log (clean operation)
```

### Historical Issues (2026)
```
Most recent SMART test: March 4, 2026
Status: All tests passed
No failures detected in March
```

### Bad Sector Growth Events
```
Tracked in diskprediction data (See SMART_HEALTH_DETECTION_ISSUE.md)
6 drives with reallocated sectors:
  - WS23LDK4: 55 sectors (STABLE)
  - WS23LDJ2: 22 sectors (STABLE)
  - ZC1BAL3S: 10 sectors (STABLE) ← Recently installed 2024/03/05
  - Others: 1-4 sectors (HEALTHY)
```

---

## Recommendations

### Immediate Actions
- ✓ Monitor ZC1BAL3S (10 bad sectors) - currently stable
- ✓ Monitor WS23LDK4 (55 bad sectors) - stable but approaching threshold
- ✓ Continue regular SMART tests (every 30 days)

### Short Term (1-3 months)
- Plan replacement for WS23LDK4 when 100 sectors reached (currently 55)
- No other drives in critical condition

### Medium Term (6-12 months)
- Expect ZC1BAL3S to reach higher sector count (currently at 1 year with 10 sectors)
- Plan preventive replacement before reaching 50+ sectors threshold

---

## System Maturity Assessment

**Installation Date:** October 2018 (5.4 years old)

**Hardware Status:**
- Main unit: Heavily used, 5+ year old drives (near end of life)
- Expansion 1: Mixed age, one very recent replacement
- Expansion 2: Good condition, newer drives (2024)

**Replacement Pattern:**
- Reactive replacements when drives fail
- Latest replacement shows proactive maintenance (WS23LDH7)
- No major failures - only gradual wear

**Overall Assessment:** System is aging well with preventive maintenance happening. Monitor high-sector drives closely.

---

## Data Sources

- **disk_log.csv:** 2,588 events spanning 7+ years (Oct 2018 - Mar 2026)
- **disk_testlog.csv:** 1,792 SMART test events
- **diskprediction/:** 39 daily snapshots (Feb 10 - Mar 20, 2026)

---

## Next Steps

1. **Continue monitoring** the 6 drives with bad sectors
2. **Plan replacement** for WS23LDK4 within 6 months
3. **Keep spares** for RX1217rp-1 Slot 2 (recent failure history)
4. **Schedule preventive** replacement for oldest main-unit drives (ZC17ZSKN, ZC184H3P)

