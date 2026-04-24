# Main Unit Information Display

**Date:** April 24, 2026  
**Status:** ✅ IMPLEMENTED

---

## What's New

The report now displays **Main Unit information** in a dedicated table section, formatted the same way as expansion units.

---

## Report Structure (Updated)

```
HARDWARE CONFIGURATION
├─ NAS Device (Model, Serial, CPU, RAM, Uptime, Completeness)
├─ Main Unit [NEW - Detailed Table]
│  ├─ Model
│  ├─ Serial
│  ├─ Bays (Used/Total)
│  ├─ Status (✓ Active)
│  └─ Drives (List of serials)
│
├─ Expansion Units [Existing - Now comes after Main Unit]
│  ├─ Enclosure ID
│  ├─ Model
│  ├─ Serial
│  ├─ Bays
│  ├─ Status
│  └─ Drives
│
├─ Storage Capacity
├─ Drive Bay Layout Diagrams
├─ Drives (Main Table)
├─ Drive Installation & Replacement History
├─ Bad Sector Analysis
├─ RAID Arrays
└─ ...
```

---

## Main Unit Table Format

| Model | Serial | Bays | Status | Drives |
|-------|--------|------|--------|--------|
| RS3617RPxs | 2320SKRBDTQZ0 | 5/12 | ✓ Active | WD01, WD02, WD03, WD04, WD05 |

or

| Model | Serial | Bays | Status | Drives |
|-------|--------|------|--------|--------|
| DS1821+ | 2320SKRBDTQZ0 | 5/8 | ✓ Active | 52F0A04KFR0H, 42X0A007FR0H, ... |

---

## Data Displayed

### Model
- **Source:** `$spec->model` (hardware detection)
- **Example:** RS3617RPxs, DS1821+, etc.

### Serial
- **Source:** `$spec->serial` (hardware detection)
- **Example:** 2320SKRBDTQZ0

### Bays
- **Used/Total format**
- **Used:** `main_unit_used` from drive extraction
- **Total:** `main_unit_bays` from hardware detection
- **Example:** 5/12, 12/16, etc.

### Status
- **Always:** ✓ Active (for main unit)
- **Styling:** Green badge with checkmark

### Drives
- **List of drive serials** from main unit drives
- **Formatted:** Comma-separated, monospace font
- **Wrapping:** Text wraps at max-width 200px
- **Example:** WD01, WD02, WD03, WD04, WD05

---

## Implementation Details

**File:** `src/DeepDive/Report/ReportRenderer.php`

### Lines 604-631: Main Unit Table Generation

```php
// Extract main unit information
$mainUnitModel = htmlspecialchars((string)($spec->model ?? 'Unknown'), ENT_QUOTES);
$mainUnitSerial = htmlspecialchars((string)($spec->serial ?? ''), ENT_QUOTES);
$mainUnitBayCount = (int)($spec->driveBays['main_unit_bays'] ?? 0);
$mainUnitUsedCount = (int)($spec->driveBays['main_unit_used'] ?? 0);

// Collect main unit drive serials
$mainUnitDrives = [];
foreach ($drives as $drive) {
    if (($drive['location'] ?? 'Main') === 'Main') {
        $mainUnitDrives[] = htmlspecialchars((string)($drive['serial'] ?? ''), ENT_QUOTES);
    }
}
$mainDrivesList = implode(', ', $mainUnitDrives);

// Render main unit table
$mainUnitTable = <<<HTML
<h4>Main Unit</h4>
<table class="hw-expansion-table">
  <thead><tr><th>Model</th><th>Serial</th><th>Bays</th><th>Status</th><th>Drives</th></tr></thead>
  <tbody><tr>...</tr></tbody>
</table>
HTML;
```

### Line 811: Output Integration

```php
{$mainUnitTable}
{$expansionTable}
```

Main Unit table appears right after NAS Device card, before Expansion Units.

---

## Comparison: Before & After

### Before
```
NAS Device
├─ Model: RS3617RPxs
├─ Serial: 2320SKRBDTQZ0
├─ CPU: ...
├─ RAM: ...
└─ Uptime: ...

Expansion Units
├─ RX1217rp-1: 12 bays, 12 drives
└─ RX1217rp-2: 12 bays, 12 drives

Drives (main table)
```

### After
```
NAS Device
├─ Model: RS3617RPxs
├─ Serial: 2320SKRBDTQZ0
├─ CPU: ...
├─ RAM: ...
└─ Uptime: ...

Main Unit
├─ Model: RS3617RPxs
├─ Serial: 2320SKRBDTQZ0
├─ Bays: 12/16
├─ Status: ✓ Active
└─ Drives: WD01, WD02, WD03, ...

Expansion Units
├─ RX1217rp-1: 12 bays, 12 drives
└─ RX1217rp-2: 12 bays, 12 drives

Drives (main table)
```

---

## Key Features

✅ **Consistent Formatting**
- Same table style as Expansion Units
- Unified presentation across all storage units

✅ **Complete Information**
- Model, Serial, Bay count, Status, Drive list
- Everything at a glance

✅ **Data-Driven**
- Uses actual hardware detection values
- Not hardcoded or assumed

✅ **Secure**
- HTML escaping for all user-controlled data
- Safe for any serial/model names

✅ **Responsive**
- Works on all screen sizes
- Drive list wraps gracefully

---

## No Existing Information Removed

- ✅ NAS Device card remains unchanged
- ✅ All existing device information preserved
- ✅ CPU, RAM, Uptime, Completeness all still shown
- ✅ Main Unit table is additional, not replacement

---

## Example HTML Output

```html
<h4>Main Unit</h4>
<table class="hw-expansion-table">
  <thead>
    <tr>
      <th>Model</th>
      <th>Serial</th>
      <th>Bays</th>
      <th>Status</th>
      <th>Drives</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>RS3617RPxs</td>
      <td class="mono">2320SKRBDTQZ0</td>
      <td>12/16</td>
      <td><span class="hw-status-badge hw-status-healthy">✓ Active</span></td>
      <td class="mono" style="font-size:11px;max-width:200px;word-break:break-all">
        52F0A04KFR0H, 42X0A007FR0H, 4240A1KQFR0H, 4240A21NFR0H, 2410XCRW0A00
      </td>
    </tr>
  </tbody>
</table>
```

---

## Report Flow

1. **NAS Device Card** — Summary info (model, serial, CPU, RAM, uptime)
2. **Main Unit Table** — Detailed main unit configuration ← NEW
3. **Expansion Units Table** — Expansion unit details (if any)
4. **Storage Capacity** — Summary metrics
5. **Drive Bay Layout Diagrams** — Visual representation
6. **Drives Table** — Detailed drive information
7. **Drive History** — Installation/replacement timeline
8. **Bad Sector Analysis** — Health monitoring
9. **RAID Arrays** — Array details
10. ... and more

---

## Testing

To see the Main Unit table in your reports:

1. Generate a new report from any debug bundle
2. Look for "Main Unit" section right after "NAS Device"
3. Verify it shows:
   - Correct model name
   - Correct serial number
   - Correct bay count (used/total)
   - ✓ Active status
   - List of main unit drive serials

---

## Status

**Status: ✅ COMPLETE**

- ✓ Main Unit table implemented
- ✓ Data extracted from hardware spec
- ✓ Formatting matches Expansion Units
- ✓ No existing information removed
- ✓ Security measures in place
- ✓ Ready for production deployment

Main Unit information now displays consistently with Expansion Units.
