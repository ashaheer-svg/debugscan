# Synology Debug File — Hardware & Data Extraction Reference

**Version:** 3.4 — 2026-04-04
**Scope:** DSM 6.x, DSM 7.x, with provisions for future 8.x
**Purpose:** Comprehensive guide for extracting hardware identity, storage topology, RAID/LVM state, and NAS health indicators from Synology `.dat` debug ZIP archives, for use as input to AI-assisted diagnosis.

---

### Version History

| Version | Date | Summary of Changes |
|---|---|---|
| 3.5 | 2026-04-10 | Added Section 3.7 (System Location Extraction). Documentation for extracting user-defined physical location from SNMP configurations (`/etc/snmp/snmpd.conf`). |
| 3.4 | 2026-04-04 | Full consistency audit. Fixed contradiction: Section 3.3.2 incorrectly claimed no superblock cache in DSM 7.x — corrected to match Section 2.3.3 (present on enterprise rack units). Fixed contradiction: Section 3.2.3 incorrectly claimed per-disk runtime files are DSM 7.x only — corrected with full availability table showing presence from DSM 6.2.x; advanced status files (unc_status, reset_fail_status, etc.) are DSM 7.0+ only. Updated Section 5.1 storage stack partition table to distinguish sata-style vs sda-style models. Updated Section 5.6 expansion integration to correctly describe both naming styles and mark sata expansion naming as unconfirmed. Simplified Section 2.2.1 expansion naming to reference Section 9. Fixed Section 3.2.1 to mark `satae` expansion naming as unconfirmed. Updated both quick reference tables (2.6, 3.6) to include per-disk bay/container files, diskmaps entries, and corrected version availability notes. Updated Section 4.3 drive health table to reference Section 9 for bay/unit attribution. Updated Section 5.2 to clarify [8/5] notation is model-specific. |
| 3.3 | 2026-04-04 | Added Section 9 (Drive Bay Detection & Expansion Unit Topology). Documents `container` file (which unit) and `id` file (physical bay number), `diskmaps_curr/boot.result` format and parser, physical bay ≠ device number on some rack models (RS1221rp+), expansion drive naming patterns for sda-style models, `syno_disks_group` clarified as non-expansion indicator. Includes per-version availability table and full code pattern. Based on analysis of 9 debug files including DS1821+, RS1221rp+ (×2), RS3617rpxs (×2), RS818+-j, RS2212+, DS916+-j, DS215+-j. Also identified 3 new previously unseen files: DS1821+ (DSM 7.3.2), RS1221rp+ (DSM 7.3, two units). |
| 3.2 | 2026-04-04 | Added Section 7 (Network Issues): interface health detection, APIPA/link-down detection, per-interface error counters, ethtool link speed/duplex anomalies, network config, SMB/NFS, UPS, HA, Active Insight. Added Section 8 (Performance Issues and Optimizations): Synology extended top.result format, I/O wait thresholds, memory/swap analysis, disk I/O stats, D-state process log, Btrfs COW and data scrubbing, diskprediction JSON, resource-hungry process identification, 18-row fact-based recommendations table. |
| 3.1 | 2026-04-04 | Corrections from analysis of 5 additional debug files (RS3617rpxs DSM 7.2.1/7.3.1, DS215+-j DSM 6.0.3, DS916+-j DSM 7.0.1, RS2212+ DSM 6.2.2). Added section 1.3 Critical Detection Rules. Corrected drive naming (not version-tied), disk log format detection (not version-tied), partition layout variants (5-partition on older rack units), superblock cache scope (present in some DSM 7.x). Added firmware-upgrade log mixing and fresh-install / sparse-file caveats throughout. |
| 3.0 | 2026-04-04 | Full rewrite. Separated into dedicated DSM 6.x and DSM 7.x extraction guides. Added NAS Health Snapshot section with severity thresholds. Added per-disk runtime health files (DSM 7.x). Added SSD cache detection. Added storage stack reference. Added DSM 8.x future-proofing notes. Based on analysis of RS818+-j (DSM 6.2.4), RS1221rp+ (DSM 7.3), and DS1821+ (DSM 7.3.2) debug files. |
| 2.1 | 2026-03-01 | Added drive capacity extraction from `proc/partitions`. Added RAID capacity extraction from `mdstat`. Added extraction checklist. Universal single-guide format covering all DSM versions. |
| 2.0 | 2025-xx-xx | Initial structured guide. Basic hardware identity and RAID extraction. |

---

## Table of Contents

1. [Debug File Structure & Version Detection](#1-debug-file-structure--version-detection)
   - 1.3 [Critical Detection Rules — Drive Naming, Log Format, Fresh Install](#13-critical-detection-rules--do-not-assume-from-version-alone)
2. [DSM 6.x Extraction Guide](#2-dsm-6x-extraction-guide)
3. [DSM 7.x Extraction Guide](#3-dsm-7x-extraction-guide)
4. [NAS Health Snapshot — All Indicators](#4-nas-health-snapshot--all-indicators)
5. [Storage Stack Reference](#5-storage-stack-reference)
6. [Future DSM 8.x Adaptation Notes](#6-future-dsm-8x-adaptation-notes)
7. [Network Issues](#7-network-issues)
8. [Performance Issues and Optimizations](#8-performance-issues-and-optimizations)
9. [Drive Bay Detection & Expansion Unit Topology](#9-drive-bay-detection--expansion-unit-topology)
10. [System Location Extraction](#10-system-location-extraction)

---

## 1. Debug File Structure & Version Detection

### 1.1 What a Synology Debug File Is

A Synology `.dat` debug file is a ZIP archive generated from the DSM web interface (Control Panel → Info Center → Generate Debug File). It captures the state of the NAS at the moment of collection:

- System configuration files from `/etc` and `/etc.defaults`
- Output of diagnostic commands captured as `.result` files
- Kernel data from `/proc`
- Log files from `/var/log`
- Runtime state from `/run`
- Package status
- SMART, RAID superblock, and LVM data

The archive does **not** contain user data. All paths inside the ZIP are prefixed with `dsm/` (e.g., `dsm/etc/VERSION`).

### 1.2 Detecting DSM Major Version

**Always read this file first.** It determines which paths and parsing strategies apply throughout the rest of extraction.

**File:** `dsm/etc/VERSION` or `dsm/etc.defaults/VERSION`

**Key fields:**
```
majorversion="7"          # Major version: 6 or 7 (use this for branching)
minorversion="3"
productversion="7.3"      # Human-readable version string
buildnumber="81180"       # Build number for exact identification
builddate="2025/10/03"    # Release date of this DSM build
```

**DSM 6.x example** (RS818+-j):
```
majorversion="6"
productversion="6.2.4"
buildnumber="25556"
smallfixnumber="7"
builddate="2023/05/02"
```

**DSM 7.x example** (RS1221rp+):
```
majorversion="7"
productversion="7.3"
buildnumber="81180"
builddate="2025/10/03"
```

**Parser logic:**
```python
major = int(kv.get("majorversion", kv.get("major", "0")))
# major == 6  →  use DSM 6.x extraction paths
# major == 7  →  use DSM 7.x extraction paths
# major >= 8  →  attempt DSM 7.x paths, fall back to adaptive discovery
```

---

### 1.3 Critical Detection Rules — Do Not Assume From Version Alone

Analysis of real-world debug files across multiple hardware generations has revealed that several structural properties **cannot be reliably inferred from the DSM major version number alone**. Always detect these dynamically by inspecting the actual file content.

#### 1.3.1 Drive Naming: Detect From proc/partitions, Not From DSM Version

The `sata1`/`sata2` naming scheme (with `p` partition suffixes) is **only used on newer consumer and prosumer desktop/tower models** (e.g., DS1821+, RS1221rp+). Enterprise rack units and older hardware use `sda`/`sdb` naming even when running DSM 7.x.

Confirmed examples:
- RS3617rpxs on **DSM 7.2.1** → `sda`, `sdb`, ... `sdl` (old-style naming)
- RS3617rpxs on **DSM 7.3.1** → `sda`, `sdb`, ... `sdl` (old-style naming)
- RS2212+ on **DSM 6.2.2** → `sda`, `sdb`, ... `sdj`
- RS1221rp+ on **DSM 7.3** → `sata1`, `sata2`, ... `sata5` (new-style)

**Always detect drive naming by reading `dsm/proc/partitions` and checking for the presence of `sataN` vs `sdX` entries:**

```python
lines = partitions_text.splitlines()
has_sata = any(re.match(r'\s+8\s+\d+\s+\d{6,}\s+sata\d+$', l) for l in lines)
has_sdx  = any(re.match(r'\s+8\s+\d+\s+\d{6,}\s+sd[a-z]+$', l) for l in lines)
drive_style = "sata"  if has_sata else "sdx"
```

Similarly, expansion drives always use major device number **128** regardless of DSM version or model. Detect them by major number, not by name prefix.

#### 1.3.2 Disk Log Format: Detect by File Presence, Not DSM Version

The switch from HTML to CSV disk log format occurred mid-way through the DSM 7.x generation and is tied to the specific DSM build, not just the major version:

| DSM Version | Build Range | Disk Log Format |
|---|---|---|
| DSM 6.0.x | ≤ ~8800 | **No disk log** (file absent entirely) |
| DSM 6.1.x – 6.2.x | ~15000–25600 | `disk_log.html` (HTML table) |
| DSM 7.0.x | ~42000–49000 | `disk_log.html` (still HTML) |
| DSM 7.1.x | ~50000–64000 | `disk_log.html` (still HTML) |
| DSM 7.2.x | ~69000+ | `disk_log.csv` (CSV with `,\t` delimiter) |
| DSM 7.3.x | ~81000+ | `disk_log.csv` (CSV with `,\t` delimiter) |

**Always check which file is present before parsing:**

```python
csv_path  = find_path(zf, ["dsm/var/log/disk_log.csv"])
html_path = find_path(zf, ["dsm/var/log/disk_log.html"])

if csv_path:
    fmt = "csv"
elif html_path:
    fmt = "html"
else:
    fmt = "none"   # Very old DSM or freshly installed unit — log file not yet created
```

#### 1.3.3 Firmware Upgrade Log Continuity

When a NAS is upgraded from an older DSM to a newer one (e.g., DSM 6.x → 7.x, or DSM 7.0 → 7.2), the disk log format changes at the upgrade point but **historical entries before the upgrade remain in the old-format file**. After the upgrade, new events are written to the new-format file.

This means a NAS that was upgraded from DSM 6.x/7.0.x to DSM 7.2+ may contain **both** `disk_log.html` and `disk_log.csv` simultaneously. The HTML file holds pre-upgrade history; the CSV file holds post-upgrade events. Both should be read and merged by timestamp for a complete picture.

```python
# Read both if present; merge by timestamp for full history
events_html = parse_html_log(html_path) if html_path else []
events_csv  = parse_csv_log(csv_path)  if csv_path  else []
all_events  = sorted(events_html + events_csv, key=lambda e: e["time"])
```

#### 1.3.4 Fresh Install and Sparse File Sets

A NAS that has been recently installed or factory reset will have very few log entries — or none at all — in the disk and system logs. This is normal and should not be interpreted as missing data. Indicators of a freshly installed or recently reset system:

- `dsm/proc/uptime` first field is small (< 86400 = less than 1 day)
- `disk_log.html` or `disk_log.csv` absent or contains only `bootup_in` / `disk_refresh` events with no history
- `dsm/var/log/messages` contains very few lines
- `dsm/var/log/lastimproper.log` absent (no prior shutdowns)
- `dsm/package_status.list` may be missing (packages not yet installed)

Very old DSM builds (6.0.x, build < ~15000) are structurally sparse by design — many diagnostic files that were added in DSM 6.1+ and 6.2+ simply do not exist. A sparse file set on an old device is expected, not indicative of a problem.

**Minimum viable data set for diagnosis:**
- `dsm/etc/VERSION` — always present
- `dsm/proc/partitions` — always present
- `dsm/proc/mdstat` — always present
- `dsm/proc/meminfo` — always present

If these four are present, basic hardware identity and RAID state can always be determined regardless of DSM version or install age.

---

## 2. DSM 6.x Extraction Guide

*Reference device: RS818+-j (Synology Avoton), DSM 6.2.4 build 25556, 4 internal + 4 expansion drives.*

> **DSM 6.0.x caveat:** Debug files from DSM 6.0.x (build ≤ ~8800) are structurally sparse. Many files documented in this section — including `disk_log`, `load_info.result`, `lv.result`, `superblock_cache`, and `package_status.list` — may be absent. Always handle missing files gracefully and fall back to the four always-present files (VERSION, partitions, mdstat, meminfo). DSM 6.1.x and 6.2.x are the fully-featured baseline for this guide.

### 2.1 Hardware Identity

#### 2.1.1 Model and Unique ID

In DSM 6.x, there is no `syno_hw_version` file. The hardware model is embedded in the `unique` field of `synoinfo.conf`.

**File:** `dsm/etc/synoinfo.conf` (also available at `dsm/etc.defaults/synoinfo.conf`)

**Key field:**
```
unique="synology_avoton_rs818+"
```

The unique string follows the format `synology_<cpu_family>_<model>`. Parse the model from the final segment after the last underscore. Note that `+` or `j` suffixes are part of the model name (e.g., `rs818+`, `ds1819+`, `rs418j`).

```python
parts = unique.split("_")
model = parts[-1].upper()   # → "RS818+"
cpu_family = parts[1] if len(parts) > 2 else "unknown"  # → "avoton"
```

#### 2.1.2 NAS Serial Number

**File:** `dsm/etc/synoinfo.conf` (also check `dsm/etc.defaults/synoinfo.conf`)

**Key field:**
```
serialno="1650LWN001234"
```

The serial number is a unique 13-character identifier for the NAS hardware. Use this for authoritative device tracking.

#### 2.1.2 DSM Version

**File:** `dsm/etc/VERSION`

Extract: `productversion`, `buildnumber`, `smallfixnumber`, `builddate`.

#### 2.1.3 RAM

**File:** `dsm/proc/meminfo`

```
MemTotal:        8164604 kB    → 8 GB RAM
SwapTotal:       6995884 kB    → ~7 GB swap
MemFree:         4057784 kB    → free at capture time
```

Convert: `MemTotal / 1024 / 1024` → GB (round to nearest 2/4/8/16/32).

#### 2.1.4 CPU / System Info

**File:** `dsm/proc/cpuinfo`
- Fields: `model name`, `cpu cores`, `cpu MHz`

**File:** `dsm/proc/uptime`
- Format: `<seconds_up> <seconds_idle>`. Divide first field by 86400 for days.

---

### 2.2 Drive Inventory (DSM 6.x)

#### 2.2.1 Drive Naming Convention

DSM 6.x — and all enterprise/rack models regardless of DSM version — use the kernel's native SCSI disk naming. See section 1.3.1 for detection rules; do not assume naming from DSM version alone.

| Drive Location | Naming | Major # | Example |
|---|---|---|---|
| Internal drives | `sda`, `sdb`, `sdc`, ... | 8 | `sda`, `sdb` |
| Expansion unit drives | Non-sequential letter prefix — varies by model and controller | 128 | `sdea`, `sdma`, `sdqa` |

Expansion drives always use major device number **128**, regardless of naming prefix or model. The specific device name prefix for expansion drives is hardware-dependent and cannot be predicted reliably — use the `container` file (Section 9) as the authoritative source for unit membership. Always detect expansion drives by major number **128**, not by name pattern. See Section 9.5 for confirmed naming patterns across known models.

```python
expansion = [l.split()[-1] for l in partitions.splitlines()
             if re.match(r'\s+128\s+\d+\s+\d{7,}\s+\w+$', l)]  # whole-disk entries only
```

**File:** `dsm/proc/partitions`

```
major minor  #blocks  name

   8        0 3907018584 sda          ← internal drive 1 (~3.6 TB)
   8        1    2490240 sda1         ← system partition (md0)
   8        2    2097152 sda2         ← swap partition (md1)
   8        3 3902197584 sda3         ← data partition (md2)
   8       16 3907018584 sdb
   ...
 128       32 3907018584 sdea         ← expansion drive 1 (major 128 = expansion)
 128       33    2490240 sdea1
 128       34    2097152 sdea2
 128       35 3902197584 sdea3
   9        0    2490176 md0          ← RAID array: system
   9        1    2097088 md1          ← RAID array: swap
   9        2 27315375808 md2         ← RAID array: data
 253        0      12288 dm-0         ← LVM physical extent metadata
 253        1 27314356224 dm-1        ← LVM logical volume (data volume)
```

**Partition layout — Standard (DSM 6.1.x+, most models):**
- Partition 1 (`sda1`): ~2.5 GB — system RAID (md0), contains DSM OS
- Partition 2 (`sda2`): ~2 GB — swap RAID (md1)
- Partition 3 (`sda3`): remainder — data RAID (md2)

**Partition layout — Legacy 5-partition (older rack units, e.g. RS2212+ on DSM 6.2.2):**

Some older Synology rack models used a 5-partition layout instead of 3:
- Partition 1 (`sda1`): ~2.5 GB — system RAID (md0)
- Partition 2 (`sda2`): ~2 GB — swap RAID (md1)
- Partitions 3–4: small utility partitions
- Partition 5 (`sda5`): remainder — data RAID (md2)

Detect which layout is in use by checking which partition feeds md2 in `proc/mdstat`:
```python
# e.g. "md2 : active raid5 sda5[0] sdb5[1]..." → data partition is p5
# e.g. "md2 : active raid5 sda3[0] sdb3[1]..." → data partition is p3
m = re.search(r'md2\s*:.*?\s+(\w+\d+[p]?\d+)\[', mdstat_text)
data_partition_suffix = re.search(r'(\d+)$', m.group(1)).group(1) if m else "3"
```

**Important:** Expansion drives participate in md2 (data) only. md0 and md1 contain only internal drive partitions.

**Calculating drive capacity from proc/partitions:**
```python
# Whole-disk entry has minor = 0 (internal) or minor = major_for_each (expansion)
# Use #blocks column (1 KB units) → bytes = blocks * 1024
capacity_tb = blocks * 1024 / 1e12
```

#### 2.2.2 Disk Event Log (DSM 6.x)

DSM 6.1.x and 6.2.x log disk events in HTML table format. **DSM 6.0.x has no disk log at all.** Always check for file presence before parsing (see section 1.3.2 for the full format detection table and firmware-upgrade mixing rules).

**File:** `dsm/var/log/disk_log.html` *(absent in DSM 6.0.x and on freshly installed units with no disk history yet)*

**Format:** HTML table with columns:

| Column | Description |
|---|---|
| `level` | `debug`, `info`, `warning`, `err` |
| `time` | `YYYY/MM/DD HH:MM:SS` |
| `path` | Device path, e.g., `/dev/sdea` |
| `model` | Drive model string, e.g., `ST4000VN006-3CW104` |
| `serial` | Drive serial number |
| `container` | Enclosure name, e.g., `RS818+`, `RX418-1` |
| `slot` | 1-based slot number within its container |
| `msg` | Event type: `bootup_in`, `plugin`, `disk_refresh`, `other_kernel_err`, `drive_fw_upgrade_success`, etc. |
| `errtype` | Error sub-type: `serror`, `rw`, `smart_fail`, etc. |
| `raw` | Raw error code (decimal) |
| `eunit` | Expansion unit name (if applicable) |
| `note` | Human-readable error detail, e.g., `RecovComm PHYRdyChg` |

**Parsing:** Use regex since DSM 6.x HTML does not use a standard parser-friendly structure:
```python
rows = re.findall(r"<tr[^>]*>(.*?)</tr>", html, re.DOTALL | re.IGNORECASE)
cells = re.findall(r"<t[dh][^>]*>(.*?)</t[dh]>", row, re.DOTALL | re.IGNORECASE)
```

**Error types to flag:**
- `other_kernel_err` with `errtype=serror` and note containing `PHYRdyChg` → SATA link reset events (often benign for expansion units on power-on, but persistent ones indicate cable/controller issues)
- `timeout` with `errtype=rw` → I/O timeout (serious)
- `smart_fail` → SMART self-test failure (serious)
- `drive_fail` → Drive failure event (critical)

**Example — expansion drives logging PHYRdyChg on plugin (normal):**
```
level=err  time=2024/08/24 15:12:23  path=/dev/sdea  model=ST4000VN006-3CW104
serial=WW631P8V  container=RX418-1  slot=1  msg=other_kernel_err
errtype=serror  note=RecovComm PHYRdyChg
```
This error on expansion unit drives immediately after a `plugin` event is typical; it represents the SATA PHY re-negotiation during hot-plug.

#### 2.2.3 Extended Drive List from load_info.result

**File:** `dsm/result/load_info.result`

This JSON file provides the richest single-file drive inventory. In DSM 6.x, each disk entry contains:

```json
{
  "id": "sda",
  "device": "/dev/sda",
  "model": "ST4000VN008-2DR166",
  "serial": "ZDH3KC1J",
  "vendor": "Seagate",
  "firm": "SC60",
  "temp": 25,
  "status": "normal",
  "adv_status": "normal",
  "smart_status": "normal",
  "overview_status": "normal",
  "size_total": "4000787030016",
  "slot_id": 1,
  "tray_status": "join",
  "unc": 0,
  "disk_code": "ironwolf",
  "isSsd": false,
  "container": {
    "str": "RS818+",
    "type": "internal",
    "order": 0
  },
  "used_by": "reuse_1",
  "has_system": true
}
```

Key fields for health assessment:
- `status`: `normal` / `warning` / `error` / `crashed`
- `adv_status`: Advanced status (SSD wear, etc.)
- `smart_status`: `normal` / `failing` / `failed`
- `unc`: Uncorrectable error count (non-zero = serious)
- `temp`: Temperature in Celsius
- `tray_status`: `join` (in RAID) / `spare` / `none`
- `exceed_bad_sector_thr`: `true` = bad sector threshold exceeded
- `firmware_status`: `-` (up to date) or version string

For expansion units, `container.type` will be `"expansion"` and `container.str` will be the expansion unit model (e.g., `"RX418-1"`).

---

### 2.3 RAID Configuration (DSM 6.x)

#### 2.3.1 Live RAID State

**File:** `dsm/proc/mdstat`

```
Personalities : [linear] [raid0] [raid1] [raid10] [raid6] [raid5] [raid4]
md2 : active raid5 sda3[0] sdea3[6] sdeb3[7] sdec3[8] sded3[9] sdd3[3] sdc3[5] sdb3[4]
      27315375808 blocks super 1.2 level 5, 64k chunk, algorithm 2 [8/8] [UUUUUUUU]

md1 : active raid1 sda2[0] sdb2[1] sdc2[2] sdd2[3]
      2097088 blocks [4/4] [UUUU]

md0 : active raid1 sda1[0] sdb1[1] sdc1[2] sdd1[3]
      2490176 blocks [4/4] [UUUU]
```

**Parsing mdstat:**
- `[N/M]` → N = configured slots, M = active members. If N > M, array is degraded.
- `[UUUUUUUU]` → each character is a drive slot: `U` = up, `_` = missing/failed.
- `blocks` → total raw RAID capacity in 1 KB blocks.
- Member notation: `sda3[0]` means device `sda3` is at RAID slot index 0.
- `(F)` suffix on a member means it is marked Faulty.
- `(S)` suffix means Spare.
- A `recovery =` line indicates active rebuild in progress.

**Calculating usable capacity for RAID5:**
```
usable_kb = (members - 1) * per_drive_data_partition_kb
# Or directly: md2_blocks = 27315375808 KB → 27315375808 / 1024 / 1024 / 1024 ≈ 25.4 TiB
```

#### 2.3.2 RAID Superblock Detail

**Files:** `dsm/result/md_examine/<array>_<device>.log`

One file per device per array member, generated from `mdadm --examine`. Example filename: `md2_sda3.log`.

The naming convention for disks with all partitions on one physical drive: `md2_md1_md0_sda.log` means a single examine of drive sda covering all of its arrays.

**Key fields in md_examine logs:**
```
          Magic : a92b4efc
        Version : 1.2
    Feature Map : 0x0
     Array UUID : 12345678:abcdef01:23456789:cdef0123
           Name : storage:2  (local to storage)
  Creation Time : Mon Aug 22 00:00:00 2022
     Raid Level : raid5
   Raid Devices : 8
 Avail Dev Size : 3902197584 sectors (1.81 TiB 2.00 TB)
     Array Size : 27315375808 KiB (26.03 TiB 28.67 TB)
    Data Offset : 2048 sectors
   Super Offset : 8 sectors
          State : clean
    Device UUID : <per-device UUID>
    Update Time : <timestamp of last update>
       Checksum : <sha256 checksum>
         Events : 18
   Device Role : Active device 0
   Array State : AAAAAAAA ('A' == active, '.' == missing, 'R' == replacing)
```

Flags that indicate problems:
- `State : dirty` → array was not cleanly unmounted (potential data integrity risk)
- `State : rebuilding` or `State : recovering`
- `Array State` containing `.` → missing member(s)
- `Events` count discrepancy across members of the same array → a drive missed updates (may have stale data)

#### 2.3.3 Superblock Cache

**Files:** `dsm/run/synostorage/raid_superblock_cache/<device_partition>`

Example entries: `sda1`, `sda2`, `sda3`, `sdea3`, etc.

These are the cached in-memory copies of the RAID superblocks maintained by Synology's storage manager. They contain the same fields as md_examine but in Synology's internal format. Compare against md_examine data if investigating superblock corruption.

> **Scope note:** Despite originally being a DSM 6.x feature, the superblock cache directory is also present in some DSM 7.x devices — specifically enterprise rack units using `sda`-style drive naming (e.g., RS3617rpxs on DSM 7.2.1 and 7.3.1). It is absent on newer consumer/prosumer DSM 7.x models (RS1221rp+, DS1821+) that use `sata`-style naming. Always check for its presence rather than assuming by version.

#### 2.3.4 Block Device Enumeration (DSM 6.x)

**File:** `dsm/result/synoblock_enum.result`

This is a Synology-proprietary device enumeration that provides additional metadata beyond proc/partitions:

- Links physical slot numbers to device paths
- Identifies expansion unit membership (`Is EBox Cross: TRUE`)
- Reports port type and connection status

---

### 2.4 LVM / Volume Configuration (DSM 6.x)

#### 2.4.1 Logical Volume Details

**File:** `dsm/result/lv.result`

Output of `lvdisplay` or equivalent. Key fields:
```
--- Logical volume ---
LV Path                /dev/vg1/volume_1
LV Name                volume_1
VG Name                vg1
LV UUID                <uuid>
LV Size                24.93 TiB
Current LE             6530048
Segments               1
Allocation             inherit
Read ahead sectors     auto
Block device           253:1
```

Important: `LV Size` is the usable volume size presented to the filesystem. This may be less than the raw RAID size (`md2 blocks`) due to LVM metadata and alignment overhead.

#### 2.4.2 Device Mapper Details

**File:** `dsm/result/dm.result`

Output of `dmsetup ls` / `dmsetup info`. Maps dm-N device numbers to their function:
- `dm-0` → typically a thin pool or metadata device (very small, ~12 MB)
- `dm-1` → the LVM logical volume presented to the filesystem

Cross-reference with `proc/partitions` dm-N sizes to confirm which is which.

---

### 2.5 Filesystem State (DSM 6.x)

#### 2.5.1 Btrfs Health

DSM 6.x may use btrfs on supported models. Check for result files:

**Files:** `dsm/var/log/btrfs/*.result`

Example: `dsm/var/log/btrfs/btrfs_shares.result`

Contains output of `btrfs scrub status`, `btrfs device stats`, and `btrfs filesystem show`. Key error counters to check: `write_io_errs`, `read_io_errs`, `corruption_errs`, `generation_errs`.

#### 2.5.2 Tune2fs / Ext4 Health

**File:** `dsm/var/log/tune2fs/dev.md0.result`

Contains ext4 superblock information: last mount time, last check time, mount count, maximum mount count, filesystem state (`clean`/`dirty`).

#### 2.5.3 Volume Status

**File:** `dsm/result/load_info.result` → `data.volumes[]` array

Key fields per volume:
```json
{
  "id": "volume_1",
  "status": "normal",
  "size_total": "27314356224",
  "size_used": "12345678901",
  "fs_type": "btrfs"
}
```

---

### 2.6 Key DSM 6.x Files — Quick Reference

| Data Category | File Path | Notes |
|---|---|---|
| DSM version | `dsm/etc/VERSION` | Parse key=value format |
| Hardware model | `dsm/etc/synoinfo.conf` | `unique=` field |
| NAS Serial Number | `dsm/etc/synoinfo.conf` | `serialno=` field |
| RAM | `dsm/proc/meminfo` | `MemTotal` field |
| CPU | `dsm/proc/cpuinfo` | `model name`, `cpu cores` |
| Uptime | `dsm/proc/uptime` | Seconds since boot |
| Load average | `dsm/proc/loadavg` | 1/5/15 min averages |
| Partitions | `dsm/proc/partitions` | Drive naming: sda/sdea; internal=major 8, expansion=major 128 |
| RAID state | `dsm/proc/mdstat` | Live RAID status |
| RAID superblocks | `dsm/result/md_examine/*.log` | Per-member detail |
| Superblock cache | `dsm/run/synostorage/raid_superblock_cache/*` | DSM 6.x + some DSM 7.x enterprise rack units (see 2.3.3) |
| Block enum | `dsm/result/synoblock_enum.result` | Slot ↔ device mapping |
| Disk event log | `dsm/var/log/disk_log.html` | HTML table format; absent in DSM 6.0.x and fresh installs |
| Full drive info | `dsm/result/load_info.result` | Richest single source |
| Per-disk identity & bay | `dsm/run/synostorage/disks/<disk>/container`, `/id`, `/model`, `/serial` | Present from DSM 6.2.x; see Section 9 |
| LVM volumes | `dsm/result/lv.result` | Logical volume detail |
| Device mapper | `dsm/result/dm.result` | dm-N mapping |
| Btrfs health | `dsm/var/log/btrfs/*.result` | Per-volume scrub/stats |
| Ext4 health | `dsm/var/log/tune2fs/*.result` | Superblock info |
| System log | `dsm/var/log/messages` | Kernel + daemon msgs |
| Kernel ring buffer | `dsm/var/log/dmesg` | Boot + hardware events |
| Synology system log | `dsm/var/log/synolog/synosys.log` | DSM-level events |
| Improper shutdown | `dsm/var/log/lastimproper.log` | Presence = power loss / panic |
| Disk test log | `dsm/var/log/disk_testlog.html` | SMART test results |
| Packages | `dsm/package_status.list` | JSON array of packages |

---

## 3. DSM 7.x Extraction Guide

*Reference devices: RS1221rp+ (DSM 7.3 build 81180, 5x HAT5300-4T), DS1821+ (DSM 7.3.2 build 86009, 5x HDWG480 + 1x HAT3310-8T).*

### 3.1 Hardware Identity

#### 3.1.1 Model Name

In DSM 7.x, the hardware model is a dedicated kernel proc file.

**File:** `dsm/proc/sys/kernel/syno_hw_version`

Content is a single line, the exact model string:
```
RS1221rp+
```
or
```
DS1821+
```

No parsing needed beyond `.strip()`.

#### 3.1.2 NAS Serial Number

**File:** `dsm/etc/synoinfo.conf`

**Key field:** 
```
serialno="2140SLN123456"
```

Same as DSM 6.x, the `serialno` key remains the authoritative source for the device serial number.

#### 3.1.2 DSM Version

**File:** `dsm/etc.defaults/VERSION` (preferred in DSM 7.x; `dsm/etc/VERSION` also present)

```
majorversion="7"
minorversion="3"
micro="0"
productversion="7.3"
buildnumber="81180"
builddate="2025/10/03"
```

DSM 7.2 uses `buildnumber` in the ~69000 range; DSM 7.3 is ~81000+; DSM 7.3.2 is ~86000+.

#### 3.1.3 RAM

**File:** `dsm/proc/meminfo`

DSM 7.x adds `MemAvailable` to the standard Linux meminfo:
```
MemTotal:       16351084 kB    → 16 GB
MemFree:        14388228 kB
MemAvailable:   15237784 kB    → Available for new allocations (more accurate than MemFree)
SwapTotal:      11911084 kB    → ~11.4 GB swap
```

`MemAvailable` is the preferred field for "how much memory is actually free" as it accounts for reclaimable page cache.

#### 3.1.4 Load Average

**File:** `dsm/proc/loadavg`

Format: `<1min> <5min> <15min> <running/total> <last_pid>`

Example: `8.10 8.84 8.70 3/435 12345`

Sustained load average above the CPU core count indicates the system is CPU or I/O bound. IO-bound load (high system load with lots of disk activity) will be correlated with disk timeout events in the disk log.

---

### 3.2 Drive Inventory (DSM 7.x)

#### 3.2.1 Drive Naming Convention

**Important:** Drive naming in DSM 7.x depends on the hardware model, not the DSM version alone. Newer consumer and prosumer models use Synology's `sata` naming; enterprise rack units continue to use `sda` naming even on DSM 7.x. Always detect from `proc/partitions` as described in section 1.3.1.

**`sata`-style naming** (newer consumer/prosumer models — DS/RS 2021+):

| Drive Location | Naming | Major # | Example |
|---|---|---|---|
| Internal SATA drives | `sata1`, `sata2`, `sata3`, ... | 8 | `sata1`, `sata2` |
| M.2 NVMe drives | `nvme0n1`, `nvme1n1`, ... | varies | `nvme0n1` |
| USB drives | `usb1`, `usb2`, ... | varies | `usb1` |

Partitions use a `p` suffix: `sata1p1`, `sata1p2`, `sata1p3`.

**`sda`-style naming** (enterprise rack units on any DSM version — RS3617rpxs, RS2212+, etc.):

These models use `sda`, `sdb`, ... naming and the standard `sda1`/`sda2`/`sda3` (or `sda5`) partition convention even when running DSM 7.2 or 7.3. Their partition 1 size is also the older ~2.5 GB (not 8 GB), because the larger system partition was introduced alongside the `sata` naming scheme on newer hardware.

**File:** `dsm/proc/partitions`

```
major minor  #blocks  name

   8        0 3907018584 sata1           ← Drive 1 (~3.63 TB)
   8        1    8388608 sata1p1         ← System partition (8 GB)
   8        2    2097152 sata1p2         ← Swap partition (2 GB)
   8        3 3896295248 sata1p3         ← Data partition (~3.6 TB)
   8       16 3907018584 sata2
   ...
   9        0    8388544 md0             ← System RAID array
   9        1    2097088 md1             ← Swap RAID array
   9        2 15585176832 md2            ← Data RAID array
 248        0      12288 dm-0            ← SSD cache metadata or LVM thin pool metadata
 248        1 15584985088 dm-1           ← LVM logical volume or cache origin
 248        2 15584985088 dm-2           ← SSD cache or volume (may be same size as dm-1)
```

**Key differences from DSM 6.x:**
- Partition 1 (`sata1p1`) is now **8 GB** (was ~2.5 GB in DSM 6.x) — larger DSM system partition
- Drive names are `sataN` not `sdX`
- Expansion units (if present) appear as major device number **128** entries in proc/partitions — the exact naming (possibly `satae1`, `satae2`, ...) is unconfirmed in available debug files; detect by major 128, not by name
- Multiple dm-N devices may appear when SSD caching (flashcache) is configured

**Identifying SSD cache configuration:**
If dm-2 appears with the same block count as dm-1, flashcache (SSD tiered caching) is active. Check `dsm/result/dmsetup-table.result` for the cache target type:
```
vg1-volume_1: 0 30494457600 cache ... cachedev_0 ...
```
If the cache device is `cachedev_0` in DUMMY mode (no SSD attached), it is effectively a passthrough with no performance benefit.

#### 3.2.2 Disk Event Log (DSM 7.x)

DSM 7.2+ logs disk events in CSV format. **Early DSM 7.0.x and 7.1.x still used `disk_log.html`** (the same HTML format as DSM 6.x). The switch to CSV occurred around DSM 7.2 (build ~69000). Always detect by file presence rather than assuming from the DSM version (see section 1.3.2).

A NAS that was upgraded from DSM 7.0/7.1 to 7.2+ may have **both** log files present — the HTML file containing pre-upgrade history and the CSV file containing post-upgrade events. Read both and merge by timestamp.

A freshly installed or recently factory-reset DSM 7.x NAS may have no disk log yet, or only `disk_refresh` / `bootup_in` entries with no historical error data. This is normal.

**File:** `dsm/var/log/disk_log.csv` *(present in DSM 7.2+; absent or HTML on earlier 7.x builds)*

**Critical parsing note:** Synology uses `,\t` (comma + TAB) as the field separator, NOT a plain comma or plain TAB. Raw header line:
```
level,	time,	path,	model,	serial,	container,	slot,	msg,	errtype,	raw,	info,	eunit,	note
```

**Correct parsing approach:**
```python
normalised = raw_csv_text.replace(",\t", ",")
reader = csv.DictReader(io.StringIO(normalised), delimiter=",")
# Then strip whitespace from all keys and values
```

**Columns:**

| Column | Description |
|---|---|
| `level` | `debug`, `info`, `warning`, `err` |
| `time` | `YYYY/MM/DD HH:MM:SS` |
| `path` | Device path, e.g., `/dev/sata4` |
| `model` | Drive model, e.g., `HDWG480`, `HAT5300-4T` |
| `serial` | Drive serial number |
| `container` | Enclosure name, e.g., `DS1821+`, `RS1221RP+` |
| `slot` | 1-based slot number within container |
| `msg` | Event: `disk_refresh`, `timeout`, `bootup_in`, `plugin`, `drive_fw_upgrade_success`, `drive_bundle_upgrade_firmware`, `disk_smart_passed`, etc. |
| `errtype` | Error sub-type: `rw` (I/O timeout), `serror`, `smart_fail` |
| `raw` | Raw error code |
| `info` | Additional info (e.g., firmware versions `1401 1403`) |
| `eunit` | Expansion unit (if applicable) |
| `note` | Human-readable detail |

**Example — drive with multiple I/O timeouts (critical indicator):**
```
debug, 2026/03/24 03:33:40, /dev/sata4, HDWG480, 4240A21NFR0H, DS1821+, 4, timeout, rw, 0
debug, 2026/03/24 03:03:08, /dev/sata4, HDWG480, 4240A21NFR0H, DS1821+, 4, timeout, rw, 0
debug, 2026/03/24 02:50:33, /dev/sata4, HDWG480, 4240A21NFR0H, DS1821+, 4, timeout, rw, 0
```
Repeated `timeout` + `errtype=rw` on the same drive → drive is failing or has connectivity issues.

**Example — firmware upgrade events (informational):**
```
warning, 2025/10/08 11:37:43, /dev/sata3, HAT5300-4T, 2230U6RR0A1JJFW1H, RS1221RP+, 4, drive_bundle_upgrade_firmware
info,    2025/10/08 11:44:50, /dev/sata3, HAT5300-4T, 2230U6RR0A1JJFW1H, RS1221RP+, 4, drive_fw_upgrade_success, , 0, 1401 1403
```
Firmware upgrades are normal and the `warning` level is DSM's default for this event type.

#### 3.2.3 Per-Disk Runtime Health Files (DSM 6.2.x+)

Synology maintains per-disk runtime status files in a dedicated directory. **This directory is present from DSM 6.2.x** (build ~24000+), but the set of files it contains grows with DSM version. It is absent only in DSM 6.0.x and earlier.

**Base path:** `dsm/run/synostorage/disks/<diskname>/`

**File availability by DSM version:**

| File | DSM 6.2.x | DSM 7.0–7.1 | DSM 7.2+ | DSM 7.3+ |
|---|---|---|---|---|
| `container` (which unit) | ✓ | ✓ | ✓ | ✓ |
| `id` (physical bay number) | ✓ | ✓ | ✓ | ✓ |
| `model`, `serial`, `vendor` | ✓ | ✓ | ✓ | ✓ |
| `smart`, `temperature` | ✓ | ✓ | ✓ | ✓ |

---

### 3.7 System Location Extraction

**Goal:** Extract the user-defined physical location of the NAS.
**Authoritative Source:** SNMP configuration.

Most Synology users do not explicitly set a "Location" unless they configure SNMP for remote monitoring. If configured, the `sysLocation` field provides the most reliable user-defined physical location.

**File:** `dsm/etc/snmp/snmpd.conf` (or `dsm/etc.defaults/snmpd.conf` if custom one is absent)

**Key Content Pattern:**
```conf
sysLocation "Data Center 1, Rack 4, U12"
sysContact "admin@example.com"
```

**Parser Logic:**
1. Search for the line starting with `sysLocation`.
2. Extract the quoted string or the remainder of the line.
3. If absent, fallback to `Unknown` or metadata from the project.

```python
# Regex to match sysLocation:
match = re.search(r'^sysLocation\s+"?([^"\n]+)"?', snmp_text, re.MULTILINE)
location = match.group(1) if match else "Unknown"
```

Availability: Present on all DSM versions where the SNMP service has been initialized at least once.
| `bad_sec_ct`, `adv_status` | ✓ | ✓ | ✓ | ✓ |
| `unc_status`, `unc_weight` | — | ✓ | ✓ | ✓ |
| `reset_fail_status`, `reset_fail_weight` | — | ✓ | ✓ | ✓ |
| `timeout_status`, `timeout_weight` | — | ✓ | ✓ | ✓ |
| `predict_status`, `predict_weight` | — | ✓ | ✓ | ✓ |
| `low_perf_in_raid` | — | — | ✓ | ✓ |
| `seq_status`, `smart_info_list.cache` | — | — | ✓ | ✓ |
| `ioerr_info`, `real_model` | — | — | — | ✓ |

> See Section 9 for using `container` and `id` to build the full physical bay topology.

**Example structure for `sata1` (DSM 7.3):**
```
dsm/run/synostorage/disks/sata1/container
dsm/run/synostorage/disks/sata1/id
dsm/run/synostorage/disks/sata1/ioerr_info
dsm/run/synostorage/disks/sata1/bad_sec_ct
dsm/run/synostorage/disks/sata1/predict_status
dsm/run/synostorage/disks/sata1/reset_fail_status
dsm/run/synostorage/disks/sata1/reset_fail_weight
dsm/run/synostorage/disks/sata1/timeout_status
dsm/run/synostorage/disks/sata1/unc_status
dsm/run/synostorage/disks/sata1/low_perf_in_raid
```

**File meanings and interpretation:**

| File | Content | Healthy Value | Critical Value |
|---|---|---|---|
| `reset_fail_status` | Drive reset failure status | `normal` | `critical` |
| `reset_fail_weight` | Accumulated reset failure weight score | 0 | ≥ 20 |
| `timeout_status` | I/O timeout accumulation status | `normal` | `warning` or `critical` |
| `unc_status` | Uncorrectable read error status | `normal` | `warning` or `critical` |
| `bad_sec_ct` | Reallocated/pending sector count | `0` | Any non-zero integer |
| `predict_status` | SMART predictive failure status | `normal` | `failed` |
| `low_perf_in_raid` | Performance degradation in RAID context | `normal` or absent | `now` |
| `ioerr_info` | I/O error detail JSON | `{}` or empty | Non-empty with errors |

**Example of a failing drive (DS1821+ sata4):**
```
reset_fail_status: critical
reset_fail_weight: 31
timeout_status:    warning
low_perf_in_raid:  now
```
Any single `critical` value here is a strong indicator of imminent drive failure.

#### 3.2.4 Full Drive Info from load_info.result (DSM 7.x)

**File:** `dsm/result/load_info.result`

DSM 7.x has a significantly richer `load_info.result` than DSM 6.x. New fields:

```json
{
  "id": "sata1",
  "device": "/dev/sata1",
  "model": "HAT5300-4T",
  "serial": "2420U6RA0A06QFW1H",
  "vendor": "Synology",
  "firm": "1403",
  "firmware_status": "-",
  "isSynoDrive": true,
  "temp": 22,
  "status": "normal",
  "adv_status": "not_support",
  "drive_status_category": "health",
  "drive_status_key": "normal",
  "summary_status_category": "health",
  "summary_status_key": "normal",
  "action_status": {
    "action_name": "idle",
    "action_progress": ""
  },
  "allocation_role": "reuse_1",
  "remain_life": {"value": -1, "trustable": true},
  "unc": 0,
  "wcache_force_off": false,
  "wcache_force_on": false,
  "wdda_support": false,
  "m2_pool_support": false,
  "disk_location": "Main"
}
```

New DSM 7.x specific fields:
- `isSynoDrive`: true if this is a Synology-branded drive (HAT series, etc.)
- `drive_status_category` / `drive_status_key`: More granular status reporting than just `status`
- `summary_status_category` / `summary_status_key`: Overall health summary
- `action_status.action_name`: Current operation (`idle`, `resyncing`, `testing`, etc.)
- `remain_life`: For SSDs — remaining lifetime percentage; `-1` for HDDs
- `disk_location`: `"Main"` or expansion unit identifier
- `wcache_force_off`/`wcache_force_on`: Write cache override settings
- `wdda_support`: Synology WDDA (write data damage analysis) support flag

The `data` object in DSM 7.x load_info also contains:
- `detected_pools`: Pool detection results (may be empty `[]`)
- `volumes[]`: Volume status with capacity information
- `env`: Environment data (temperatures, power supply status, fan speeds, UPS status)

**Extracting volume capacity from load_info.result:**
```json
{
  "volumes": [{
    "id": "volume_1",
    "status": "normal",
    "size_total": "15584985088",
    "size_used": "5234567890",
    "fs_type": "btrfs",
    "pool_path": "/dev/vg1"
  }]
}
```

**Extracting environment/health from load_info.result:**
```json
{
  "env": {
    "cpu_clock": 2399,
    "cpu_load": [45, 12, 8],
    "mem_usage": 14,
    "fan": [{"speed": 1200, "status": "normal"}],
    "temperature": 38,
    "up_time": "6 hour(s) 8 minute(s)",
    "power": {"status": "normal"},
    "ups": {"status": "invalid"}
  }
}
```

---

### 3.3 RAID Configuration (DSM 7.x)

#### 3.3.1 Live RAID State

**File:** `dsm/proc/mdstat`

DSM 7.x mdstat shows the same format as 6.x, but device names reflect the `sataNpN` convention:

```
Personalities : [raid1] [raid6] [raid5] [raid4] [raidF1]
md2 : active raid5 sata3p3[0] sata2p3[4] sata4p3[3] sata5p3[2] sata1p3[1]
      15585176832 blocks super 1.2 level 5, 64k chunk, algorithm 2 [5/5] [UUUUU]

md1 : active raid1 sata3p2[0] sata2p2[4] sata4p2[3] sata5p2[2] sata1p2[1]
      2097088 blocks [8/5] [UUUUU___]

md0 : active raid1 sata3p1[0] sata2p1[4] sata4p1[3] sata5p1[2] sata1p1[1]
      8388544 blocks [8/5] [UUUUU___]
```

**Note on `[8/5] [UUUUU___]`:** This is normal for a NAS configured for 8 bays but only 5 drives installed. The `_` slots are unused bays. This does **not** indicate degradation — only `_` replacing a `U` for an existing member slot would indicate a failed/missing member.

**raidF1 personality:** DSM 7.x adds `raidF1` (RAID F1), which is Synology's Flash-optimized RAID designed for all-SSD configurations. Presence in the Personalities list does not mean it's in use.

#### 3.3.2 md_examine Logs (DSM 7.x)

Same structure as DSM 6.x but file names reflect the device naming of the model:

**Files:** `dsm/result/md_examine/`

Examples for sata-style models (DS1821+, RS1221rp+):
- `md2_sata1p3.log` → md_examine of sata1's data partition
- `md0_sata1p1.log` → md_examine of sata1's system partition

Examples for sda-style models (RS3617rpxs on DSM 7.2/7.3):
- `md2_sda3.log` → md_examine of sda's data partition
- `md0_sda1.log` → md_examine of sda's system partition

**Superblock cache on DSM 7.x:** The `dsm/run/synostorage/raid_superblock_cache/` directory is **absent on newer consumer/prosumer models** (RS1221rp+, DS1821+) but **is present on enterprise rack units** using sda-style naming (e.g., RS3617rpxs on DSM 7.2.1 and 7.3.1). Always check for presence rather than assuming by version. See section 2.3.3 for details.

#### 3.3.3 Space and Volume Status

**File:** `dsm/run/space/volume_status.cache` (JSON)

```json
{
  "volume_1": {
    "status": 1,
    "build": 12345,
    "description": "volume_1"
  }
}
```

Status codes: `1` = normal, other values indicate degraded or crashed states.

**File:** `dsm/run/space/space_meta.status`

Maps volume names to their storage pool/VG:
```
volume_1 → vg1
```

**File:** `dsm/run/space/datascrubbing.status.tmp` (JSON)

Contains data scrubbing (background consistency check) schedule and last-run results per array and volume. Check `status` field: `idle` / `running` / `finished`.

---

### 3.4 LVM / Volume Configuration (DSM 7.x)

#### 3.4.1 Logical Volume Details

**File:** `dsm/result/lv.result`

Same format as DSM 6.x (output of `lvdisplay`). In DSM 7.x with SSD caching, there may be additional LVs visible.

#### 3.4.2 Device Mapper (DSM 7.x)

**File:** `dsm/result/dmsetup-table.result`

Output of `dmsetup table`, showing how each dm-N device is configured:

```
vg1-volume_1: 0 30494457600 linear 9:2 2048
```
or with SSD cache (flashcache):
```
cachedev_0: 0 30494457600 cache <cache_params>
```

**File:** `dsm/result/dmsetup-status.result`

Runtime status of device-mapper targets. For flashcache/cache targets, shows hit rate, dirty blocks, etc.

**File:** `dsm/result/dm.result`

Summary of device-mapper state.

#### 3.4.3 Identifying SSD Cache State

In DSM 7.x, SSD caching creates additional dm-N layers. The presence of multiple same-sized dm devices (e.g., dm-1 and dm-2 both at ~15.6 TB) indicates SSD cache is configured.

Check `dmsetup-table.result` for the word `cache` or `flashcache` in the target type column. If the cache device is listed as `cachedev_0` and is in DUMMY mode, no SSD is attached and the cache provides no benefit (pure passthrough).

---

### 3.5 System Self-Check

**File:** `dsm/result/synoselfcheck_dsm_full.result`

Synology's built-in system integrity check result. Expected content: `Check Success`. Any other value indicates a configuration or system file integrity issue.

---

### 3.6 Key DSM 7.x Files — Quick Reference

| Data Category | File Path | Notes |
|---|---|---|
| DSM version | `dsm/etc.defaults/VERSION` | Parse key=value |
| Hardware model | `dsm/proc/sys/kernel/syno_hw_version` | Single line |
| NAS Serial Number | `dsm/etc/synoinfo.conf` | `serialno=` field |
| RAM | `dsm/proc/meminfo` | Use `MemAvailable` |
| CPU | `dsm/proc/cpuinfo` | `model name`, `cpu cores` |
| Uptime | `dsm/proc/uptime` | Seconds since boot |
| Load average | `dsm/proc/loadavg` | 1/5/15 min averages |
| Partitions | `dsm/proc/partitions` | Naming: sata1/sata1p1 (consumer/prosumer) or sda/sda1 (enterprise rack); expansion=major 128 |
| RAID state | `dsm/proc/mdstat` | Live RAID status |
| RAID superblocks | `dsm/result/md_examine/*.log` | Per-member detail |
| Disk event log | `dsm/var/log/disk_log.csv` | CSV with ,\t delimiter (DSM 7.2+); HTML on DSM 7.0/7.1 |
| Bay topology map | `dsm/result/diskmaps_curr.result` | Physical bay → device → serial; DSM 7.0+ only |
| Bay topology at boot | `dsm/result/diskmaps_boot.result` | Same as curr unless hot-swap occurred since boot |
| Full drive info | `dsm/result/load_info.result` | Richest single source |
| Per-disk identity & bay | `dsm/run/synostorage/disks/<name>/container`, `/id` | Present from DSM 6.2.x (see Section 9) |
| Per-disk advanced health | `dsm/run/synostorage/disks/<name>/unc_status`, `/reset_fail_status`, etc. | Advanced files: DSM 7.0+ only (see 3.2.3 table) |
| LVM volumes | `dsm/result/lv.result` | Logical volume detail |
| Device mapper | `dsm/result/dm.result` | dm-N mapping |
| Device mapper table | `dsm/result/dmsetup-table.result` | Cache/LVM target config |
| Device mapper status | `dsm/result/dmsetup-status.result` | Cache hit rates, etc. |
| Volume status | `dsm/run/space/volume_status.cache` | JSON status codes |
| Space metadata | `dsm/run/space/space_meta.status` | Volume→VG mapping |
| Scrubbing status | `dsm/run/space/datascrubbing.status.tmp` | Background check state |
| Btrfs health | `dsm/var/log/btrfs/*.result` | Per-volume scrub/stats |
| Ext4 health | `dsm/var/log/tune2fs/*.result` | Superblock info |
| System self-check | `dsm/result/synoselfcheck_dsm_full.result` | "Check Success" = OK |
| System log | `dsm/var/log/messages` | Kernel + daemon msgs |
| Kernel ring buffer | `dsm/var/log/dmesg` | Boot + hardware events |
| Synology system log | `dsm/var/log/synolog/synosys.log` | DSM-level events |
| Improper shutdown | `dsm/var/log/lastimproper.log` | Presence = unclean shutdown |
| Disk test log | `dsm/var/log/disk_testlog.csv` | SMART test results (CSV) |
| Damage thresholds | `dsm/etc.defaults/disk_adv_status.conf` | Weight thresholds per status |
| HA status | `HighAvailability/ha_not_running` | Presence = HA stopped |
| Packages | `dsm/package_status.list` | JSON array |

---

## 4. NAS Health Snapshot — All Indicators

This section defines a structured NAS Health Snapshot: a comprehensive set of health indicators to extract as a first step before any AI-based diagnosis. It consolidates signals from both DSM versions.

### 4.1 Health Snapshot Structure

Extract and present the following categories in order. Each indicator should include its value, source file, and a severity assessment.

---

### 4.2 Category 1: Device Identity

| Indicator | How to Extract | Source |
|---|---|---|
| Hardware model | DSM 6.x: parse `unique=` from synoinfo.conf | `dsm/etc/synoinfo.conf` |
| | DSM 7.x: read file directly | `dsm/proc/sys/kernel/syno_hw_version` |
| NAS Serial Number | Read `serialno` field | `dsm/etc/synoinfo.conf` |
| DSM version | `productversion` field | `dsm/etc/VERSION` or `dsm/etc.defaults/VERSION` |
| DSM build | `buildnumber` field | Same |
| DSM build date | `builddate` field | Same |
| Total RAM | `MemTotal` / 1024 / 1024 → GB | `dsm/proc/meminfo` |
| System uptime | First field / 86400 → days | `dsm/proc/uptime` |
| Capture timestamp | File modification time or disk_log dates | ZIP metadata |

**Health significance:** Short uptime (< 1 day) may indicate a recent crash or reboot. Very long uptime (> 1 year) may mean DSM updates are being skipped.

---

### 4.3 Category 2: Drive Health

For each physical drive, extract:

| Indicator | DSM 6.x Source | DSM 7.x Source |
|---|---|---|
| Drive count | Count entries in proc/partitions with whole-disk size | Same |
| Drive model | disk_log.html or load_info.result | disk_log.csv or load_info.result |
| Drive serial | Same | Same |
| Drive slot (physical bay) | `slot` in disk_log.html; or `id` file in synostorage/disks/ (DSM 6.2.x+) | `slot` in disk_log.csv; `id` file in synostorage/disks/; or `diskmaps_curr.result` (see Section 9) |
| Drive unit (host vs expansion) | `container` field in disk_log.html; `container` file in synostorage/disks/ (DSM 6.2.x+) | Same + `disk_location` in load_info.result; `diskmaps_curr.result` (see Section 9) |
| Drive temperature | `temp` in load_info.result | Same |
| Drive status | `status` in load_info.result | `drive_status_key` + `status` |
| SMART status | `smart_status` in load_info.result | Same |
| Uncorrectable errors (UNC) | `unc` in load_info.result | Same |
| Bad sectors | `exceed_bad_sector_thr` | `bad_sec_ct` file + load_info |
| Reset failures | Not available | `reset_fail_status` file |
| Reset failure weight | Not available | `reset_fail_weight` file |
| I/O timeouts | disk_log.html `msg=timeout` count | disk_log.csv `msg=timeout` count |
| Predictive failure | load_info `smart_status=failing` | `predict_status` file |
| Performance degraded | Not available | `low_perf_in_raid` file |
| Firmware version | `firm` in load_info.result | Same |
| Firmware status | `firmware_status` in load_info.result | Same (also check disk_log for upgrades) |
| Container / location | `container.str` and `container.type` | Same + `disk_location` |

**Severity thresholds:**
- `unc > 0` → Warning (Uncorrectable read errors detected)
- `smart_status = "failing"` → Critical (SMART predicts imminent failure)
- `status = "error"` or `"crashed"` → Critical
- `reset_fail_status = "critical"` → Critical (DSM 7.x)
- `reset_fail_weight >= 20` → Warning; `>= 30` → Critical (DSM 7.x)
- `low_perf_in_raid = "now"` → Warning (DSM 7.x)
- 3+ timeout events on same drive within 24 hours → Warning
- 10+ timeout events on same drive over history → Critical

---

### 4.4 Category 3: RAID Array Health

Extract from `dsm/proc/mdstat`:

| Indicator | Extraction Method | Healthy | Warning | Critical |
|---|---|---|---|---|
| Array name | `md0`, `md1`, `md2` | — | — | — |
| RAID level | `raid5`, `raid6`, `raid1`, etc. | — | — | — |
| Configured members | First number in `[N/M]` | — | — | — |
| Active members | Second number in `[N/M]` | N == M | — | N > M |
| Array state string | `[UUUUU]` pattern | All `U` | — | Any `_` replacing existing member |
| Rebuild in progress | `recovery =` line | Absent | Present (normal state) | Present with errors |
| Array size | `blocks` field → TiB | — | — | — |
| Superblock version | `super 1.2` | 1.2 | 0.90 (old) | — |

**Note on `[N/M]` with unused bays:** If a NAS has 8 bays but only 5 drives, md0 and md1 show `[8/5] [UUUUU___]`. The `___` represent configured-but-absent slots, which is normal. This is NOT degraded. Degradation would show as `[5/4] [UUUU_]` where a slot that previously had a `U` is now missing.

**Check md_examine for:**
- `State: dirty` → Array not cleanly unmounted (possible data integrity risk)
- `Events` counter discrepancy across members of the same array → stale member
- `Array State` containing `.` → missing member

---

### 4.5 Category 4: Volume / Storage Pool Health

Extract from `dsm/result/load_info.result` → `data.volumes[]` and `data.pools[]` (DSM 7.x):

| Indicator | Healthy | Warning | Critical |
|---|---|---|---|
| Volume status | `normal` | `background` | `crashed`, `read_only` |
| Volume usage % | < 80% | 80–90% | > 90% |
| Filesystem type | `btrfs` / `ext4` | — | — |

**DSM 6.x `status=background`:** This means the volume is in a background task (often post-reboot RAID check or scrub). Capture the uptime — if the NAS only had 16 minutes of uptime when the debug file was generated, `background` status is expected and not alarming.

**Calculating usage %:**
```python
used_pct = int(size_used) / int(size_total) * 100
```

**Btrfs scrub results** (`dsm/var/log/btrfs/*.result`):
- `"errors found"` in scrub output → Data integrity issue (serious)
- `"scrub status: running"` → Scrub in progress at capture time (normal)
- Error counters (`read_io_errs`, `write_io_errs`, `corruption_errs`) — any non-zero value is serious

---

### 4.6 Category 5: System Load and Memory

| Indicator | How to Extract | Healthy | Warning | Critical |
|---|---|---|---|---|
| Load average (1 min) | `dsm/proc/loadavg` field 1 | < CPU cores | 1–2× CPU cores | > 2× CPU cores |
| Load average (15 min) | `dsm/proc/loadavg` field 3 | < CPU cores | 1–2× CPU cores | > 2× CPU cores |
| Memory usage % | `(MemTotal - MemAvailable) / MemTotal * 100` | < 80% | 80–90% | > 90% |
| Swap usage % | `(SwapTotal - SwapFree) / SwapTotal * 100` | < 10% | 10–50% | > 50% |

**CPU core count:** Extract from `dsm/proc/cpuinfo` by counting `processor :` lines.

**High I/O load:** If load average is high but CPU load percentage is low, the system is I/O bound (waiting on disk). This correlates with drive timeouts in disk_log.

---

### 4.7 Category 6: System Integrity Indicators

| Indicator | Source | Meaning |
|---|---|---|
| Improper shutdown | `dsm/var/log/lastimproper.log` (file exists) | System was not cleanly shut down (power loss, kernel panic, forced power off) |
| System self-check | `dsm/result/synoselfcheck_dsm_full.result` | `Check Success` = OK; anything else = integrity issue |
| Kernel messages | `dsm/var/log/dmesg` | Search for `kernel panic`, `Oops`, `I/O error`, `ata` errors |
| System log errors | `dsm/var/log/messages` | Search for `error`, `fail`, `timeout`, `oom-killer` |
| OOM killer events | `dsm/var/log/messages` | `Out of memory: Kill process` → serious memory pressure |
| RSYNC signal error | `dsm/var/log/rsync_signal.error` (non-empty) | rsync processes were forcibly killed |
| Bash errors | `dsm/var/log/bash_err.log` | Script errors from DSM management scripts |
| Synoinfo bad | `dsm/var/log/synoinfo.conf.bad` (file exists) | Configuration file corruption detected |

---

### 4.8 Category 7: Package and Service Status

**File:** `dsm/package_status.list`

JSON array. Each entry:
```json
{
  "name": "ContainerManager",
  "version": "24.0.2-1726",
  "status": "running"
}
```

Status values: `running`, `stopped`, `broken`

Note: A package being `stopped` may be intentional (user disabled it). Only flag as an issue if it's a core package like `SynologyDriveServer` or `ActiveBackup` that is expected to be running but shows `stopped`.

**File:** `HighAvailability/ha_not_running`

Presence of this file means High Availability is configured on this NAS but the HA service is currently not running. This is significant if the NAS is supposed to be in an active HA cluster — a stopped HA service means failover protection is disabled.

---

### 4.9 Composite NAS Health Score

Combine the above into a triage severity level for AI handoff:

**CRITICAL** (any one of):
- Any drive `status = "crashed"` or `smart_status = "failed"`
- Any drive `reset_fail_status = "critical"` (DSM 7.x)
- Any RAID array with `[N/M]` where M < N and the missing member was previously active (degraded array)
- `dsm/var/log/lastimproper.log` exists AND volume `size_used/size_total > 0.90`
- OOM killer events in `messages` log
- Kernel panic in `dmesg`
- Btrfs `corruption_errs > 0`

**WARNING** (any one of):
- Drive temperature > 55°C
- Drive `unc > 0`
- Drive with 3+ timeout events in disk_log
- Volume usage > 85%
- Swap usage > 20%
- Load average > 2× CPU core count sustained for 15 min
- `dsm/var/log/lastimproper.log` exists (improper shutdown)
- RAID rebuild in progress
- HA configured but `ha_not_running` file present
- Any package showing `broken` status

**NORMAL:**
- All drives `status = "normal"` and `smart_status = "normal"`
- All RAID arrays fully synced `[N/N] [UUUU...]`
- Volume usage < 75%
- Load average < 1× CPU core count
- `synoselfcheck_dsm_full.result = "Check Success"`

---

## 5. Storage Stack Reference

Understanding the Synology storage stack helps correctly interpret the data from multiple sources.

### 5.1 Physical Drive Layer

```
Physical drives (sata1..N / sda..N)
    ↓ partition table
Partition 1 (p1 / 1): ~2.5 GB or ~8 GB system → feeds md0
Partition 2 (p2 / 2): ~2 GB swap              → feeds md1
Partition 3 (p3 / 3 or p5 / 5): ~full disk    → feeds md2 (data)
```

Partition naming and size depend on the **hardware model and drive naming style**, not just DSM version (see section 1.3.1):

| Model type | Drive name | Partition style | System partition (p1) |
|---|---|---|---|
| DSM 6.x (all models) | `sda`, `sdb`, ... | `sda1`, `sda2`, `sda3` (or `sda5` on older rack units) | ~2.5 GB |
| DSM 7.x — newer consumer/prosumer (DS/RS 2021+) | `sata1`, `sata2`, ... | `sata1p1`, `sata1p2`, `sata1p3` | ~8 GB |
| DSM 7.x — enterprise rack units (RS3617rpxs, RS2212+, etc.) | `sda`, `sdb`, ... | `sda1`, `sda2`, `sda3` | ~2.5 GB |

The larger 8 GB system partition was introduced alongside the `sata`-style naming on newer hardware platforms. Older hardware models on DSM 7.x retain the 2.5 GB system partition.

### 5.2 RAID Layer (mdadm)

```
md0 = RAID1 of all partition-1s   → DSM OS filesystem
md1 = RAID1 of all partition-2s   → swap space
md2 = RAID5/RAID6/RAID1 of partition-3s → data pool
```

md0 and md1 are always RAID1 regardless of the data RAID level chosen. RAID6, RAID5, RAID1, RAID10 are only for md2. On a device with more configured RAID slots than installed drives (e.g., an 8-bay model with 5 drives), md0 and md1 show `[8/5] [UUUUU___]` — the `___` are empty bay slots, which is normal and not a degraded state.

### 5.3 LVM Layer

```
md2 → LVM Physical Volume (pv) → Volume Group vg1
    → Logical Volume volume_1 (dm-1)
```

The LVM layer allows Synology to expand volumes across multiple RAID arrays (multi-volume pools in DSM 7.2+) and to snapshot. `lv.result` shows the LVM logical volume details; `dm.result` and `dmsetup-*` show the device-mapper layer that LVM uses internally.

### 5.4 SSD Cache Layer (Optional, DSM 7.x)

```
volume_1 (dm-1) ← SSD cache device (dm-2) ← physical SSD/NVMe
```

When SSD caching is active, dm-2 wraps dm-1 with a caching layer. The cache target type is `cache` or `flashcache` in `dmsetup-table.result`. If the SSD is removed or not present, the cache operates in DUMMY mode (passthrough).

### 5.5 Filesystem Layer

```
dm-1 (or dm-2 if SSD cache active) → btrfs or ext4 filesystem → volume_1 → shared folders
```

Synology uses btrfs for most modern deployments (supports snapshots, checksums, deduplication). Older deployments use ext4.

### 5.6 Expansion Unit Integration

**All DSM versions — detection by major device number:**
Expansion drives always use major device number **128** in `proc/partitions`, regardless of DSM version or model. This is the single most reliable indicator that a block device belongs to an expansion unit.

```python
expansion = [l.split()[-1] for l in partitions.splitlines()
             if re.match(r'\s+128\s+\d+\s+\d{7,}\s+\w+$', l)]
```

**Drive naming — sda-style models (enterprise rack units):**
- Internal drives: `sda`–`sdl` (up to 12 for most rack units), major 8
- Expansion drives: non-sequential letter prefixes, major 128
  - RS818+-j + RX418 (4+4): internal `sda–sdd`, expansion `sdea–sded`
  - RS3617rpxs + RX1217rp (12+12): internal `sda–sdl`, expansion `sdma–sdpc` (first unit), `sdqa–sdtc` (second unit)
- Do **not** attempt to derive expansion membership from device name — use `container` files (Section 9)

**Drive naming — sata-style models (newer consumer/prosumer):**
- Internal drives: `sata1`–`sataN`, major 8
- Expansion drives (when connected): expected to use major 128 entries — naming pattern unconfirmed in available debug files; check proc/partitions for major 128 entries

**Expansion unit RAID participation:**
- Expansion drives participate in **md2 (data) only**
- md0 (system) and md1 (swap) contain only internal drive partitions

**Identifying which unit each drive belongs to:**
- DSM 6.2.x+: `dsm/run/synostorage/disks/{disk}/container` — empty = HOST, `RX418-1` etc. = expansion unit
- DSM 7.0+: `dsm/result/diskmaps_curr.result` — shows host and each Eunit section with bay numbers
- See Section 9 for full details and code patterns

**Additional identification sources:**
- `synoblock_enum.result` (DSM 6.x): identifies expansion drives (`Is EBox Cross: TRUE`)
- `disk_log.html` / `disk_log.csv` `container` field: shows enclosure name per event
- `load_info.result` `disk_location` field (DSM 7.x): `"Main"` = host NAS, expansion unit name otherwise

---

## 6. Future DSM 8.x Adaptation Notes

Synology has not released DSM 8.x as of this writing. The following guidance ensures the extraction tool can adapt when it arrives.

### 6.1 Version Detection First

Always read `dsm/etc.defaults/VERSION` (or `dsm/etc/VERSION`) and extract `majorversion` before taking any other action. Route to the appropriate extraction branch:

```python
if major >= 8:
    # Attempt DSM 7.x paths first (most likely to be compatible)
    # Fall through to adaptive discovery for anything not found
    ...
elif major == 7:
    # Use DSM 7.x extraction
    ...
elif major == 6:
    # Use DSM 6.x extraction
    ...
```

### 6.2 Likely Changes in DSM 8.x

Based on the evolution from DSM 6.x to 7.x, anticipate:

**Drive naming:** May introduce `nvme` naming more prominently if all-NVMe NAS products expand. May also change partition naming. Always fall back to parsing `proc/partitions` directly by device major number if the expected names are absent.

**Disk log format:** May change from `.csv` to JSON. Check if `disk_log.json` or `disk_log.csv` is present; if neither, fall back to `load_info.result` for drive history.

**load_info.result:** This file has been the most stable across versions and should remain the primary drive inventory source. Its schema may gain new top-level keys; parse defensively using `.get()` with defaults.

**Per-disk health:** The `dsm/run/synostorage/disks/` structure may expand with new status files. Treat the directory as a key-value store and read all files in it; unknown file names should be included in the output for AI analysis.

**Metadata volumes:** DSM 7.2 introduced multi-volume storage pools. DSM 8.x may further change the volume/pool relationship. Always extract both `dsm/result/lv.result` and `dsm/run/space/*` files.

### 6.3 Adaptive Discovery Fallback

When a specific expected file is absent (because of a version change), use adaptive discovery:

```python
# If disk_log.csv not found, search for any disk_log.*
disk_logs = [p for p in all_paths if "disk_log" in p.lower() and not p.endswith("/")]

# If syno_hw_version not found, search for any hw_version file
hw_files = [p for p in all_paths if "hw_version" in p.lower() or "syno_hw" in p.lower()]

# If load_info.result not found, search for any *load_info* file
load_infos = [p for p in all_paths if "load_info" in p.lower()]
```

Log which path was actually used for each data point, so AI analysis can note if data came from a fallback source.

### 6.4 Structural Section Detection

Regardless of DSM version, always enumerate the top-level sections present in the ZIP:

```python
sections = sorted(set(p.split("/")[0] for p in all_paths if "/" in p))
# Expected sections (DSM 7.x): ['HighAvailability', 'LogCenter', 'SMBService', 'dsm', ...]
```

New sections in DSM 8.x will appear here and can be added to the tier-3 extraction list.

---

---

## 7. Network Issues

Network problems are one of the most common but least obvious issues in NAS environments, since the debug file is captured from the device itself and connectivity problems may not be visible from the inside. Systematic extraction of network state is required.

### 7.1 Interface Inventory and Status

**File:** `dsm/result/ifconfig.result`

Lists all network interfaces with their IP address, MAC address, MTU, and current flags. Key things to check:

| Indicator | Healthy | Problem |
|---|---|---|
| IP address | Valid unicast (e.g., `192.168.x.x`) | `169.254.x.x` (APIPA/link-local) — means the port failed to get an address; no real network connectivity |
| Interface flags | `UP BROADCAST RUNNING` | `BROADCAST MULTICAST` without `RUNNING` — link is down |
| MTU | 1500 (standard) | 9000 = jumbo frames configured (intentional or misconfigured) |
| TX/RX bytes | Non-zero on the active port | All zeros — interface has never had traffic |

**APIPA address (169.254.x.x) is a critical network indicator.** It means the interface is configured for DHCP but received no response, or was set to a link-local fallback. The NAS is effectively offline on that port.

Real example from debug.dat.dat (RS3617rpxs):
```
eth0: inet addr:169.254.182.219  →  No DHCP response on eth0; NAS fell back to link-local
```
The default route for this same device uses `eth4`, meaning eth0 is simply disconnected.

**File:** `dsm/result/ethtool.ethX.result`

Per-interface physical layer status. One file per interface (`eth0`, `eth1`, etc.):

```
Speed: 1000Mb/s      → 1 GbE link (expected for most NAS models)
Speed: 100Mb/s       → ⚠ Running at 100 Mbps — cable quality issue, switch port issue, or wrong auto-neg
Speed: Unknown!      → ⚠ Link is physically down ("Link detected: no")
Duplex: Full         → Correct
Duplex: Half         → ⚠ Severe performance problem — collisions will occur under any significant load
Auto-negotiation: on → Correct
Link detected: yes   → Physical cable connected and link up
Link detected: no    → ⚠ No physical link — cable unplugged or port failure
```

**NIC driver and firmware:**
**File:** `dsm/result/ethtool_info.ethX.result`
```
driver: igb          → Intel GbE driver (common on rack units)
driver: r8168        → Realtek GbE driver (common on desktop units)
firmware-version: 1.8, 0x80000bec   → NIC firmware version (check for updates if link unstable)
```

**NIC error counters:**
**File:** `dsm/proc/net/dev`

```
 face |bytes    packets errs drop fifo frame compressed multicast
  eth0: 43795237  201445    0    0    0     0          0      3681 ...
```

| Column | Meaning | Threshold |
|---|---|---|
| `errs` | Receive errors | Any non-zero = investigate |
| `drop` | Packets dropped by kernel | > 0 sustained = ring buffer overflow or CPU overload |
| `fifo` | FIFO overrun errors | Any non-zero = NIC ring too small |
| `frame` | Frame alignment errors | Any non-zero = duplex mismatch or cable fault |
| `colls` | Transmit collisions | Any non-zero = half-duplex operation (serious) |
| `carrier` | Loss of carrier events | Any non-zero = link instability |

---

### 7.2 Network Configuration

**File:** `dsm/etc/sysconfig/network-scripts/ifcfg-ethX`

One file per interface. Key fields:

```ini
DEVICE=eth0
BOOTPROTO=static          # or "dhcp"
IPADDR=192.168.0.215
NETMASK=255.255.255.0
BRIDGE=""                 # non-empty = this port is part of a bridge
IPV6INIT=dhcp             # or "off" — IPv6 mode
IPV6_ACCEPT_RA=1          # accept IPv6 router advertisements
```

Issues to detect:
- `BOOTPROTO=dhcp` but interface has 169.254.x.x → DHCP server unreachable
- Multiple ports `BOOTPROTO=static` with IPs on different subnets → asymmetric routing risk
- `BOOTPROTO=dhcp` on a server — fine for home use, but for enterprise NAS a static IP is strongly recommended (DHCP lease changes cause share mapping failures)

**File:** `dsm/result/route.result`

The kernel routing table. Check:
- Default gateway (`0.0.0.0 UG`) is present and uses the expected interface
- No duplicate default routes (metric conflicts)
- No stale routes to disconnected subnets

Real example — default route on wrong interface:
```
0.0.0.0  172.16.10.254  0.0.0.0  UG  0  0  0  eth4   ← default via eth4
169.254.0.0  0.0.0.0  255.255.0.0  U  0  0  0  eth0   ← eth0 stuck at link-local
```
The device can reach the internet via `eth4` but eth0 is effectively dead.

**File:** `dsm/etc/resolv.conf`

DNS server configuration. Issues:
- Duplicate nameserver entries → cosmetically harmless but indicates a configuration issue
- Only one nameserver listed → no redundancy; DNS failure = name resolution failure for cloud sync, QuickConnect, Active Directory, etc.
- Nameserver is the same IP as the default gateway → the router is acting as DNS relay (common, but a single point of failure)

Real example from debug_2320SKRBDTQZ0.dat:
```
nameserver  192.168.55.20
nameserver  192.168.8.1
nameserver  192.168.55.20    ← duplicate; same as first entry
```

---

### 7.3 Link Aggregation (Bonding)

**Files:** `dsm/etc/iproute2/config/bond0-table-rule`, `dsm/etc/sysconfig/pppoe-relay/bond0`

If bonding is configured, the bond interface (`bond0`) will appear in `ifconfig.result` alongside the member ports. Check:
- All member ports show traffic in `proc/net/dev` (a dead member is silently excluded)
- The bond mode is appropriate: `mode=1` (Active-Backup) for redundancy, `mode=4` (LACP/802.3ad) for throughput — LACP requires switch support
- Member port speeds match — mixing 100Mb/s and 1Gb/s members causes the bond to operate at the lower speed

---

### 7.4 File Sharing Protocols

#### 7.4.1 SMB/CIFS

**File:** `SMBService/etc/samba/smb.conf` or `dsm/etc/samba/smb.conf`

Key settings:

| Setting | Value | Implication |
|---|---|---|
| `min protocol` | `SMB2` | SMBv1 disabled — correct (SMBv1 is insecure, deprecated) |
| `min protocol` | `NT1` or `SMB1` | ⚠ SMBv1 enabled — security risk, should be disabled |
| `max protocol` | `SMB3` | Correct — enables SMB signing, encryption, multichannel |
| `security` | `ads` | Active Directory domain integration |
| `security` | `user` | Local user authentication |
| `password server` | IP or hostname | AD domain controller — verify reachable; DNS must resolve it |
| `realm` | `DOMAIN.COM` | AD domain realm |

Performance considerations from SMB config:
- `min protocol=SMB2` with `max protocol=SMB3` is the optimal configuration
- If clients connect with SMBv2 but the NAS supports SMBv3, upgrade clients to get SMB multichannel and signing benefits
- Large numbers of shares increase smbd memory footprint (visible in `top.result`)

**SMB transfer database:** `dsm/var/log/synolog/.SMBXFERDB` — SQLite binary file. Contains historical transfer logs. Its presence and size indicate active SMB usage; zero size means no transfers recorded.

#### 7.4.2 NFS

**File:** `dsm/result/showmount_exports.result`

Lists active NFS exports. An empty export list (`Export list for NAS:`) with NFS enabled suggests NFS is running but no shares are exported — check for misconfiguration or a service that has not yet been configured.

NFS performance notes:
- NFSv3 is stateless, lower overhead, but no locking guarantees
- NFSv4 offers stateful operation, better security (Kerberos option), better for concurrent access
- The NFS version in use is not directly visible in the debug file but can be inferred from kernel module loading (visible in `dmesg`)

#### 7.4.3 Firewall Rules

**File:** `dsm/usr/syno/etc/firewall.d/1.json`

```json
{
  "adapterPolicyMap": { "global": 2 },
  "rules": null
}
```

`adapterPolicyMap "global": 2` = default policy is ALLOW (permissive firewall). `"rules": null` means no specific rules are configured — all traffic is permitted.

If `rules` is non-null, examine for:
- Rules that block known management ports (5000, 5001, 22) from expected admin subnets
- Overly broad `allow all` rules that negate the purpose of the firewall
- Rules blocking NFS (port 2049) or SMB (port 445) from the wrong subnets

A real active firewall entry:
```json
{
  "portList": ["5000"],
  "protocol": 3,
  "policy": 0,
  "enable": true
}
```
`policy: 0` = allow, `policy: 1` = deny.

---

### 7.5 UPS and Power Management

**File:** `dsm/result/upsc.result`

If a UPS is connected (USB or SNMP), this file contains the current UPS status:

```
battery.charge: 100
battery.voltage: 13.50
ups.load: 23
ups.status: OL       → On Line (AC power present)
ups.status: OB       → On Battery (power failure)
ups.status: LB       → Low Battery (imminent shutdown)
```

**File:** `dsm/etc/ups/upsmon.conf`

```
MONITOR ups@localhost 1 admin password slave
SHUTDOWNCMD "/sbin/poweroff"
POWERDOWNFLAG /etc/killpower
MINSUPPLIES 1
```

Issues to check:
- `ups.status: OB` at capture time → the NAS was running on battery when the debug file was made
- `battery.charge` < 50% → battery degraded or discharging at capture time
- No UPS configured on a critical NAS → single point of failure for power loss (correlates with `lastimproper.log` presence)

---

### 7.6 High Availability Network (HA)

**File:** `dsm/var/log/systemd/pkg-synoha-arping-update@ethX.service.log`

Present only on HA-configured devices. Contains ARP probe outputs for the virtual cluster IP:

```
ARPING 192.168.0.200 from 192.168.0.200 eth0
Sent 60 probes (60 broadcast(s))
```

This shows the active node broadcasting the cluster IP via gratuitous ARP. If this log is present but `HighAvailability/ha_not_running` also exists, the HA service has stopped — the cluster IP is no longer being maintained and clients may lose access.

---

### 7.7 Synology Active Insight

**File:** `ActiveInsight/usr/local/packages/@appdata/ActiveInsight/pkg_status.json`

```json
{"status": "disabled"}                     → Not sending telemetry
{"status": "enabled"}                       → Active — Synology receives device health data
{"status": "enabled", "reason": ["network_error"]}  → ⚠ Enabled but cannot reach Synology servers
```

`network_error` status means Active Insight is configured but the NAS cannot reach Synology's cloud. This may indicate a DNS or outbound connectivity problem that also affects QuickConnect, package updates, and cloud sync.

---

### 7.8 Network Quick Reference

| Indicator | File | Look For |
|---|---|---|
| Interface IPs and state | `dsm/result/ifconfig.result` | 169.254.x.x = no link, flags missing RUNNING |
| Link speed / duplex | `dsm/result/ethtool.ethX.result` | Speed: 100Mb/s, Duplex: Half, Link detected: no |
| NIC driver | `dsm/result/ethtool_info.ethX.result` | Driver name and firmware version |
| Error counters | `dsm/proc/net/dev` | errs, drop, frame, colls, carrier > 0 |
| Static/DHCP config | `dsm/etc/sysconfig/network-scripts/ifcfg-ethX` | BOOTPROTO, IPADDR, BRIDGE |
| Default route | `dsm/result/route.result` | Gateway present, correct interface |
| DNS servers | `dsm/etc/resolv.conf` | Duplicates, single point of failure |
| Active connections | `dsm/result/netstat.result` | Unexpected established connections |
| SMB config | `SMBService/etc/samba/smb.conf` | min protocol, security, AD server |
| NFS exports | `dsm/result/showmount_exports.result` | Empty export list |
| Firewall rules | `dsm/usr/syno/etc/firewall.d/1.json` | Default-allow, no rules |
| UPS status | `dsm/result/upsc.result` | OB = on battery, LB = low battery |
| HA heartbeat | `dsm/var/log/systemd/pkg-synoha-*` | Arping log present + ha_not_running |
| Active Insight | `ActiveInsight/.../pkg_status.json` | network_error = outbound connectivity issue |

---

## 8. Performance Issues and Optimizations

Performance data in debug files is a snapshot at the moment of capture. High load at capture time is significant; the context (uptime, time of day, running tasks) must be considered. Cross-reference with disk event logs and D-state process dumps for corroborating evidence.

### 8.1 CPU and Load Average

**File:** `dsm/result/top.result`

Synology's `top` output includes an extended load average line that separates IO load from CPU load — this is a Synology-specific addition:

```
top - 15:06:12 up 6:09, load average: 7.36, 8.55, 8.60 [IO: 7.13, 8.43, 8.53 CPU: 0.23, 0.11, 0.02]
%Cpu(s):  0.7 us,  1.4 sy,  0.0 ni, 60.6 id, 37.3 wa,  0.0 hi,  0.0 si
```

| Field | Meaning | Problem threshold |
|---|---|---|
| Load average 1/5/15 min | Runnable + uninterruptible tasks | > number of CPU cores |
| IO load (Synology extension) | Load contributed by I/O wait | > 2.0 on a 4-core system = I/O bottleneck |
| CPU load (Synology extension) | Load contributed by actual CPU work | Remaining load from above |
| `%wa` (I/O wait %) | CPU time spent waiting for disk | > 20% = I/O bottleneck |
| `%us` (user) | Application CPU | > 80% with high load = CPU-bound |
| `%sy` (system/kernel) | Kernel CPU time | > 20% = kernel overhead (NFS, RAID rebuild, etc.) |
| `%ni` (nice) | Low-priority background tasks | High value = scheduled jobs running (antivirus, Hyper Backup) |

**Real examples from debug files:**

DS1821+ (critical I/O crisis):
```
load average: 7.36, 8.55, 8.60 [IO: 7.13, 8.43, 8.53 CPU: 0.23, 0.11, 0.02]
%Cpu(s): 0.7 us, 1.4 sy, 37.3 wa
```
The IO load of 7.13 on a 4-core CPU (6 cores total with hyperthreading) means essentially all runnable processes are blocked waiting for disk I/O — directly correlated with the sata4 drive timeouts.

RS3617rpxs (background CPU load from antivirus):
```
load average: 4.21, 3.70, 3.37 [IO: 2.70, 2.21, 2.04 CPU: 1.51, 1.49, 1.34]
%Cpu(s): 0.7 us, 2.9 sy, 15.0 ni, 81.4 id, 0.0 wa
```
IO load of 2.70 on 8 cores with 0.0% wa is unusual — `%ni` = 15% indicates a nicely-prioritised background process is doing heavy work. The process list confirms `AntiVirus` consuming 137.5% CPU (over 19,000 minutes cumulative — it has been running continuously for days without completing).

**CPU core count:** `grep -c "^processor" dsm/proc/cpuinfo`

**Zombie processes:** `top.result` line `N zombie` — zombie processes are children that have exited but not been reaped by their parent. A few are normal; a large and growing count indicates a parent process that is failing to clean up children (software bug or hung parent).

---

### 8.2 Memory and Swap

**File:** `dsm/proc/meminfo`

```
MemTotal:       16351084 kB
MemAvailable:   15237784 kB    → Use this for "free" — accounts for reclaimable cache
SwapTotal:      11911084 kB
SwapFree:       11911084 kB    → If SwapFree < SwapTotal, swap is being used
```

**File:** `dsm/proc/vmstat`

Key indicators of memory pressure:

| Field | Meaning | Concern if... |
|---|---|---|
| `nr_dirty` | Pages modified but not yet written to disk | Very large (> 500 MB equivalent) |
| `nr_writeback` | Pages actively being written | Non-zero for extended periods |
| `pgscan_direct` | Direct reclaim of pages (sync) | Non-zero = memory critically low |
| `pgmajfault` | Major page faults (disk reads for pages) | Rapidly growing = swap thrashing |
| `nr_free_pages` | Free pages | `< 1000` × 4 KB = very low free memory |

**Swap usage interpretation:**

```
SwapTotal:  11911084 kB  (11.4 GB)
SwapFree:    9550380 kB  → 1.69 GB swap in use
```

Synology uses ZRAM (compressed RAM as swap) and a swap partition on md1. ZRAM swap is normal and does not indicate memory pressure — it extends usable RAM with minimal overhead. Actual disk-backed swap usage is more serious.

**File:** `dsm/result/top.result` — Memory line:

```
GiB Mem: 7.730 total, 0.126 free, 0.654 used, 6.950 buff/cache
```

`buff/cache` is kernel page cache — this is reclaimable and should not be treated as consumed memory. The `MemAvailable` value from `meminfo` accounts for this correctly.

---

### 8.3 Disk I/O Performance

**File:** `dsm/proc/diskstats`

Per-device I/O statistics at capture time (cumulative since boot):

```
  8    0 sata1 reads completed  read_sectors  read_time_ms  writes_completed  write_sectors  write_time_ms  io_in_progress  total_io_time_ms
```

Key fields (columns 4–11):
- `reads_completed`, `writes_completed` — total operations
- `read_time_ms`, `write_time_ms` — total time spent in I/O (ms)
- `io_in_progress` — current queue depth (non-zero at capture time = drive busy)
- `total_io_time_ms` — total time at least one I/O was in progress (denominator for utilisation)

**Average service time per operation:**
```python
avg_read_ms  = read_time_ms  / reads_completed   if reads_completed  else 0
avg_write_ms = write_time_ms / writes_completed  if writes_completed else 0
# Healthy HDD: < 20ms average
# Concerning:  > 50ms average
# Critical:    > 100ms average (drive struggling)
```

**File:** `dsm/result/df.result`

Volume mount points and usage. Check for:
- `/dev/md0` (system partition) > 80% used → DSM update packages or logs filling the OS partition
- `/volume1` > 85% used → volume almost full, btrfs performance degrades significantly above 80–85% usage
- `/tmp` unusually large → temporary files accumulating (package build artifacts, failed downloads)

Real example — system disk nearing full:
```
/dev/md0   2385528  1681248  585496  75%  /    ← RS3617rpxs: system partition 75% full
```
At 75% on a 2.3 GB system partition, there is only ~570 MB left. DSM updates require free space to unpack, so this could block future upgrades.

---

### 8.4 D-State Processes (I/O Hung Processes)

**File:** `dsm/var/log/syno_sys_status.log`

This log is written by Synology when processes enter the Linux "D state" (uninterruptible sleep — waiting for I/O that never returns). Each entry marks a timestamp when the issue was detected:

```
[date] Fri Feb 27 12:47:24 IST 2026
[D state process call stack]
...
[date] Fri Feb 27 12:47:28 IST 2026
```

Repeated D-state entries over an extended period are a strong indicator of a storage I/O problem — typically a failing or unresponsive drive causing the kernel to wait indefinitely for I/O completion. Cross-reference timestamps with `disk_log` timeout events for the same dates.

A single isolated entry may be benign. Multiple entries within the same hour, or entries appearing across many months, indicate a persistent I/O problem that predates the debug capture.

---

### 8.5 Btrfs Filesystem Health and Optimizations

**File:** `dsm/var/log/btrfs/btrfs_shares.result`

Per-share btrfs settings:

```ini
[ShareName]
    Compression="no"
    COW disalbed="yes"    # note: Synology misspells "disabled"
    Encrypted="no"
    Path="/volume1"
    Snapshot browsing="no"
    WriteOnce="no"
```

**COW (Copy-on-Write) disabled:** This is the most important optimization flag. When COW is disabled on a share, btrfs writes data in-place instead of to a new location — this improves performance for database workloads (VM images, databases, containers) by eliminating write amplification, but means data integrity on power loss is not guaranteed by the filesystem. Synology disables COW by default on all shares.

**When to recommend re-enabling COW:**
- Shares used for general file storage (documents, photos) — COW provides checksums and snapshot efficiency at low cost
- Shares where snapshot consistency matters

**When COW should remain disabled:**
- VM Guest folder (VMDK/VHD images)
- Container storage
- Database shares (MySQL, MariaDB, PostgreSQL)
- Any share where `Encrypted="yes"` is also set (encrypted + COW has a performance penalty)

**Compression:** `Compression="no"` is the default. Enabling btrfs LZO or ZSTD compression can significantly reduce storage usage for document, log, and media archives at the cost of some CPU during writes. It is not recommended for already-compressed data (video, photos, archives).

**File:** `dsm/run/space/datascrubbing.status.tmp`

```ini
[md2]
scrubbingstatus=0         # 0=idle, 1=running, 2=finished
scrubbingtimestamp=1774153802
scrubbingprogress=0

[ScrubbingGeneral]
schedulestatus=4          # 4=scheduled (periodic), 0=disabled
schedulenextstarttime=0
```

`scrubbingstatus=0` with `scrubbingtimestamp` far in the past means data scrubbing has not run recently. Scrubbing is the only way to detect and repair silent data corruption (bit rot) on btrfs volumes. Synology recommends monthly scrubbing; quarterly is acceptable for infrequently changing data.

**Interpretation of scrubbingstatus:**
- `0` with `schedulestatus=4` → scheduled but not currently running (normal)
- `0` with `schedulestatus=0` → scrubbing disabled entirely → **recommend enabling**
- `1` → scrubbing in progress at capture time (normal)

---

### 8.6 Disk Prediction (AI Health Data)

**File:** `dsm/var/log/diskprediction/data-YYYY-MM-DD.json`

Synology's proprietary AI-based disk health monitoring stores daily snapshots. This is the richest single source of drive health data, combining conventional SMART attributes with Synology's own weighted scoring system.

**Top-level fields:**

```json
{
  "app_priv_num": 10,           // number of open file handles
  "application_usage_num": 4,   // active applications using storage
  "avail_kb": "602508",         // available space at capture time
  "collector_version": 1,
  "disks": [...]
}
```

**Per-disk fields of interest:**

```json
{
  "adv_status": "normal",         // advanced health status
  "bad_sec_ct": "0",              // bad sector count
  "below_remain_life_thr": "0",   // SSD wear below threshold
  "capacity": "4000787030016",    // bytes
  "exc_bad_sec_ct": "0",         // exceeded bad sector count threshold
  "firm": "SC60",                 // firmware version
  "hibernation": {
    "deepsleep": 0,               // times drive entered deep sleep
    "hibernation": 4268           // times drive spun down
  },
  "ihm_code": "000",             // IHM (drive health monitor) code; "000" = OK
  "ihm_status": "normal",        // or "warning" / "critical"
  "ironwolf": "0",               // IronWolf Health Management enabled
  "kernel_err": {
    "icrc": 0,                    // CRC errors
    "idnf": 0,                    // ID not found
    "reset_fail": 0,              // reset failures
    "retry": 0,                   // retried commands
    "timeout": 0,                 // command timeouts
    "unc": 0                      // uncorrectable errors
  },
  "dsl_attr": [["attr_id", "value", "is_present", "is_valid", ...]...]
}
```

**`kernel_err` fields are the most actionable:** Any non-zero value in `reset_fail`, `timeout`, or `unc` is a direct indicator of drive hardware problems corroborated at the kernel level.

**`dsl_attr` codes** are Synology-internal SMART attribute mappings. The attribute IDs map to:
- `403–409` → Power-on hours, start/stop count, load cycles, reallocated sectors, pending sectors, uncorrectable errors
- `503–504` → SATA error counters
- `603–610` → Temperature readings (current, min, max, lifetime)
- `703` → SSD-specific wear indicators
- `803–815` → Drive-specific extended attributes (model-dependent)
- `903–905` → Firmware-specific counters

**`hibernation.hibernation` count:** How many times the drive has spun down. Very high counts (> 50,000) on a working NAS may indicate aggressive power management settings causing excessive spin-up cycles, which add mechanical wear. Check DSM's HDD Hibernation settings (Control Panel → Hardware & Power).

---

### 8.7 Resource-Hungry Processes

**File:** `dsm/result/top.result` and `dsm/result/ps.result`

Scan the process list for processes with high CPU time accumulation:

```
PID  USER     NI  VIRT   RES   %CPU  %MEM  TIME+     COMMAND
8547 Antivir+  5  1692m  222m  137.5  1.4   19547:52  synoavscan
```

`TIME+` = total CPU minutes consumed since the process started. `19547:52` = over 19,000 CPU-minutes (≈ 325 CPU-hours) is abnormal for an antivirus scan — this process has been running continuously for the NAS's full uptime (272 days) without completing, indicating a stuck or infinite-loop scan.

**Common resource-hungry processes and their implications:**

| Process | Normal Behaviour | Abnormal |
|---|---|---|
| `synoavscan` | Runs periodically, completes in hours | Running for days with high %CPU — scan is stuck (often on a large sparse file or a looping file) |
| `smbd`, `winbindd` | Steady moderate CPU | Spike during high SMB load; `winbindd` high CPU = AD authentication issues |
| `synorelayd` | Low steady usage | High usage = QuickConnect relay traffic; consider direct access |
| `syno-cloud-clientd` | Low to moderate | High = Cloud Sync backlog or sync loop |
| `nginx` | Low | High = many simultaneous DSM sessions or API hammering |
| `synostoraged`, `synostgd` | Low unless RAID rebuild | Sustained high = RAID rebuild, scrub, or defrag |

**Zombie process count:** `top.result` shows `N zombie` in the Tasks line. 1–2 zombies are acceptable; > 5 persistent zombies indicate a management process (`init` hierarchy) that is not reaping children, possibly due to being itself stuck.

---

### 8.8 Performance Optimizations — Fact-Based Recommendations

The following recommendations are derived directly from observable facts in the debug files, not generic advice.

| Observation | Source | Recommendation |
|---|---|---|
| NIC Speed: 100Mb/s on GbE port | `ethtool.ethX.result` | Replace patch cable (Cat5e minimum); check switch port negotiation; try forcing `Speed: 1000Mb/s` in DSM network settings |
| DHCP IP on server NAS | `ifcfg-ethX` `BOOTPROTO=dhcp` | Assign a static IP via DSM or DHCP reservation to prevent share mapping failures after lease change |
| Duplicate DNS entries | `resolv.conf` | Remove duplicate; add a secondary DNS from a different subnet for redundancy |
| COW disabled on general-purpose shares | `btrfs_shares.result` | Re-enable COW (in DSM Shared Folder settings) for document/photo shares to benefit from checksums and efficient snapshots |
| Data scrubbing disabled | `datascrubbing.status.tmp` `schedulestatus=0` | Enable monthly scrubbing in DSM Storage Manager → Storage Pool → Schedule Data Scrubbing |
| Volume > 85% full | `df.result` | Btrfs performance degrades markedly above 85% — expand pool, add drives, or archive data |
| System partition (/dev/md0) > 70% | `df.result` | DSM package logs or core dumps filling OS partition — check `/var/log/` and `/var/tmp/` for large files |
| AntiVirus running > 24 hours | `top.result` TIME+ field | Stop scan, exclude large sparse files and VM image folders, reschedule for off-peak hours with time limit |
| D-state entries in syno_sys_status.log | `syno_sys_status.log` | Correlate dates with disk timeout events — indicates a historically struggling drive; replace if multiple months of entries |
| No UPS configured, lastimproper.log present | `lastimproper.log` exists, no `ups.conf` | Install UPS; configure automatic safe shutdown at low battery |
| ActiveInsight: network_error | `ActiveInsight/.../pkg_status.json` | Resolve outbound DNS/HTTPS connectivity — affects QuickConnect, package updates, and cloud sync |
| Unused NIC ports (all zeros in proc/net/dev) | `dsm/proc/net/dev` | Consider bonding (Link Aggregation) if switch supports LACP for improved throughput and redundancy |
| All ports DHCP, no bond configured | `ifcfg-*` files | On rack units with 4+ ports, bonding 2 ports for redundancy is highly recommended |
| SSD cache in DUMMY mode | `dmsetup-table.result` | Either install compatible NVMe/SSD for caching benefit or disable the cache layer to reduce complexity and avoid any DUMMY-mode overhead |
| `hibernation.hibernation` count very high | `diskprediction/data-*.json` | Reduce HDD hibernation aggressiveness in DSM (Control Panel → Hardware & Power → HDD Hibernation) |

---

### 8.9 Performance Quick Reference

| Indicator | File | Threshold |
|---|---|---|
| Load average | `dsm/result/top.result` | > CPU core count = saturated |
| I/O wait % | `top.result` `%wa` | > 20% = I/O bottleneck |
| IO load (Synology) | `top.result` `[IO: x.xx]` | > 2× cores = severe I/O congestion |
| Zombie count | `top.result` Tasks line | > 5 = investigate parent processes |
| Swap in use | `dsm/proc/meminfo` `SwapFree < SwapTotal` | > 10% disk swap used = memory pressure |
| NIC speed | `ethtool.ethX.result` | 100Mb/s when 1000 expected = link/cable issue |
| NIC drops | `dsm/proc/net/dev` | Any drop > 0 sustained = investigate |
| Volume usage | `dsm/result/df.result` | > 85% on btrfs = performance impact |
| System partition | `df.result` `/dev/md0` | > 70% = approaching update/log space risk |
| D-state events | `dsm/var/log/syno_sys_status.log` | Any recent entries = I/O stall history |
| Scrubbing status | `dsm/run/space/datascrubbing.status.tmp` | schedulestatus=0 = scrubbing disabled |
| COW disabled | `dsm/var/log/btrfs/btrfs_shares.result` | On general-purpose shares = data integrity trade-off |
| Disk UNC errors | `diskprediction/data-*.json` `kernel_err.unc` | Any > 0 = uncorrectable read errors |
| Disk timeout errors | `diskprediction/data-*.json` `kernel_err.timeout` | Any > 0 = drive not responding to commands |
| Antivirus stuck | `top.result` `synoavscan TIME+ > 1440 min` | Scan running > 24 hours = stuck |

---

## 9. Drive Bay Detection & Expansion Unit Topology

This section covers how to determine which physical unit (host NAS or expansion enclosure) each drive belongs to, and what physical bay slot it occupies within that unit.

---

### 9.1 Source Availability by DSM Version

| Capability | DSM 6.0.x (≤ build 8754) | DSM 6.2.x (build ~24000+) | DSM 7.0+ (build 42218+) |
|---|---|---|---|
| Drive list (proc/partitions) | ✓ | ✓ | ✓ |
| Which unit (container file) | NOT AVAILABLE | ✓ most models | ✓ most models |
| Physical bay number (id file) | NOT AVAILABLE | ✓ | ✓ |
| Drive model / serial | NOT AVAILABLE | ✓ | ✓ |
| diskmaps topology map | NOT AVAILABLE | NOT AVAILABLE | ✓ |
| Expansion unit count | NOT AVAILABLE | ✓ via container | ✓ via container + diskmaps |
| Empty bay detection | NOT AVAILABLE | NOT AVAILABLE | ✓ via diskmaps |

**Known exception:** RS2212+ (cedarview, DSM 6.2.2) and likely other older enterprise rack units have `container` files present but all drives report empty (attributed to HOST), even when an expansion unit is physically connected. These models use direct SAS attachment rather than Synology's enclosure management protocol; expansion unit membership is NOT AVAILABLE for them regardless of DSM version.

---

### 9.2 Per-Disk Files: `container` and `id`

**Files:** `dsm/run/synostorage/disks/{disk}/container` and `dsm/run/synostorage/disks/{disk}/id`

These two files, one per detected drive, are the primary sources for bay topology on DSM 6.2.x and 7.x.

**`container`** — names the enclosure this drive belongs to:
- Empty string → **HOST NAS** (internal bay)
- `{model}-{n}` → **expansion unit** of that model at connection index n (e.g., `RX418-1`, `RX1217rp-1`, `RX1217rp-2`)

**`id`** — the **physical bay number** within that enclosure:
- 1-based integer matching the labeled bay slot on the physical hardware
- Matches the bay numbers in `diskmaps_curr.result` when both are available
- Confirmed accurate across all tested models: RS818+-j, RS3617rpxs (DSM 7.2.1 and 7.3.1), RS2212+, DS916+-j, DS1821+, RS1221rp+

**Combined extraction — full bay topology:**
```python
def extract_bay_topology(zf):
    names = zf.namelist()

    def read_disk_file(disk, fname):
        p = f"dsm/run/synostorage/disks/{disk}/{fname}"
        try: return zf.read(p).decode(errors='replace').strip()
        except: return ""

    disk_dirs = sorted(set(
        n.split('/disks/')[1].split('/')[0]
        for n in names
        if '/synostorage/disks/' in n and len(n.split('/disks/')[1].split('/')) > 1
    ))

    topology = {}  # { unit_name: [ {bay, device, serial, model, type}, ... ] }

    for disk in disk_dirs:
        bay_id = read_disk_file(disk, "id")
        if not bay_id or not bay_id.isdigit():
            continue   # skip empty placeholder entries
        container  = read_disk_file(disk, "container") or "HOST"
        serial     = read_disk_file(disk, "serial") or read_disk_file(disk, "ui_serial")
        model      = read_disk_file(disk, "model")
        disk_type  = read_disk_file(disk, "type")   # "HDD" or "SSD"

        topology.setdefault(container, []).append({
            "bay": int(bay_id), "device": f"/dev/{disk}",
            "serial": serial, "model": model, "type": disk_type,
        })

    for unit in topology:
        topology[unit].sort(key=lambda d: d["bay"])

    return topology
```

**Output example — RS3617rpxs DSM 7.2.1 with 2 × RX1217rp:**
```
HOST         Bay  1: /dev/sda   ZC124E4Y  ST4000NM0115  HDD
HOST         Bay  2: /dev/sdb   ZC1BAL3S  ST4000NM0035  HDD
...
HOST         Bay 12: /dev/sdl   ZC184H3P  ST4000NM0035  HDD
RX1217rp-1   Bay  1: /dev/sdma  ZC129286  ST4000NM0035  HDD
...
RX1217rp-1   Bay 12: /dev/sdpc  WS23LDC0  ST4000NM002A  HDD
RX1217rp-2   Bay  1: /dev/sdqa  2420U6RA…  HAT5300-4T   HDD
...
RX1217rp-2   Bay 12: /dev/sdtc  2420U6RA…  HAT5300-4T   HDD
```

---

### 9.3 `diskmaps_curr.result` and `diskmaps_boot.result` (DSM 7.0+ only)

**Files:** `dsm/result/diskmaps_curr.result`, `dsm/result/diskmaps_boot.result`

**Available from:** DSM 7.0 build 42218+. Not present in any DSM 6.x build.

These files provide the authoritative physical bay-to-device-to-serial map as seen by DSM at boot time and at current time respectively.

**Format:**
```
Internal disk info:
01: /dev/sda	(ZC124E4Y)
02: /dev/sdb	(ZC1BAL3S)
...
12: /dev/sdl	(ZC184H3P)
Eunit disk info: RX1217rp
01: /dev/sdma	(ZC129286)
...
12: /dev/sdpc	(WS23LDC0)
Eunit disk info: RX1217rp
01: /dev/sdqa	(2420U6RA0A0CCFW1H)
...
12: /dev/sdtc	(2420U6RA0A068FW1H)
```

**Parsing rules:**
- `Internal disk info:` — introduces the HOST section; bay numbers are 1-based physical slots
- `Eunit disk info: {model}` — introduces one expansion unit section; multiple units of the same model appear as successive sections in connection order (first = unit -1, second = unit -2, matching `container` file suffixes)
- Bay line format: `{N}: /dev/{device}\t({serial})` — tab-separated device and serial in parentheses
- Empty bay: `{N}:` with no device or serial — use this to determine total bay capacity and which slots are empty
- `diskmaps_boot.result` = bay map as of last system boot; `diskmaps_curr.result` = live map. They normally match; a difference between them indicates a drive was hot-swapped after boot without a reboot.

**Parser:**
```python
import re

def parse_diskmaps(text):
    sections = {}
    current = None
    for line in text.splitlines():
        line = line.strip()
        if line == "Internal disk info:":
            current = "HOST"; sections[current] = []
        elif m := re.match(r'Eunit disk info:\s*(.+)', line):
            model = m.group(1).strip()
            # Count existing sections for this model to assign -1, -2 suffix
            existing = sum(1 for k in sections if k.startswith(model))
            current = f"{model}-{existing + 1}"
            sections[current] = []
        elif current and (m := re.match(r'(\d+):\s*(?:/dev/(\S+))?\s*(?:\(([^)]+)\))?', line)):
            sections[current].append({
                "bay": int(m.group(1)),
                "device": f"/dev/{m.group(2)}" if m.group(2) else None,
                "serial": m.group(3) or None,
            })
    return sections
```

---

### 9.4 Physical Bay ≠ Device Number on Some Models

For certain rack models, the sata device number does NOT match the physical bay slot. Always use the `id` file or `diskmaps` for physical bay assignment — never assume device number = bay number.

**RS1221rp+ (12-bay rackmount, sata-style naming):**
```
Physical bay 1 → /dev/sata3   (sata3 is NOT bay 3)
Physical bay 2 → /dev/sata1
Physical bay 3 → /dev/sata5
Physical bay 4 → /dev/sata4
Physical bay 5 → /dev/sata2
```
Confirmed consistently across two separate RS1221rp+ debug files.

**DS1821+ (8-bay desktop, sata-style naming):**
```
Physical bay 1 → /dev/sata1   (aligned — device N = bay N on this model)
Physical bay 2 → /dev/sata2
...
```

**RS3617rpxs (12-bay rackmount, sda-style naming):**
```
Physical bay 1 → /dev/sda    (aligned — sda=bay1, sdb=bay2, ..., sdl=bay12)
```

The misalignment is model-specific and cannot be predicted from the device name. Always read `id` or `diskmaps` for the correct physical bay.

---

### 9.5 Expansion Unit Drive Device Naming (sda-style Models)

For enterprise rack units using `sda/sdb` naming, expansion drives do not continue sequentially from the last internal drive. They appear on a separate host adapter enumeration with non-sequential letter prefixes.

| NAS + Expansion | Internal | First Expansion | Second Expansion |
|---|---|---|---|
| RS818+-j + RX418 (4+4 bays) | sda–sdd | sdea, sdeb, sdec, sded | — |
| RS3617rpxs + 1×RX1217rp (12+12) | sda–sdl | sdma–sdmc, sdna–sdnc, sdoa–sdoc, sdpa–sdpc | — |
| RS3617rpxs + 2×RX1217rp (12+12+12) | sda–sdl | sdma–sdpc | sdqa–sdtc |

**Letter-pair pattern for RX1217rp (12-bay expansion):** Each SAS expander wide port presents 3 drives sharing a two-letter device prefix. Four groups of three = 12 drives: `sdma/sdmb/sdmc`, `sdna/sdnb/sdnc`, `sdoa/sdob/sdoc`, `sdpa/sdpb/sdpc`.

The exact naming depends on the controller port hardware and cannot be predicted from the NAS model alone. Use `container` files (not device names) as the authoritative source for unit membership.

---

### 9.6 `syno_disks_group` — Not an Expansion Unit Indicator

**File:** `dsm/proc/sys/kernel/syno_disks_group`

Present in all DSM versions. Contains a single integer. This is **not** a count of enclosures or expansion units.

| Model | Value | Expansion units? |
|---|---|---|
| DS215+-j (2-bay desktop) | 1 | None |
| DS916+-j (4-bay desktop) | 1 | None |
| RS818+-j (4-bay rack, with RX418) | 1 | Yes — value does not reflect it |
| RS1221rp+ (12-bay rack, no expansion) | 1 | None |
| RS3617rpxs (12-bay rack, 0–2 expansions) | 4 | Yes, but value is constant regardless of expansion count |
| RS2212+ (12-bay rack) | 4 | Unknown — container files inconclusive |
| DS1821+ (8-bay desktop) | 8 | None |

The value likely reflects drive power-on sequencing groups or SAS channel topology, not enclosure count. Do not use it to infer whether expansion units are attached.

---

### 9.7 DSM 6.0.x — No Bay Information Available

Debug files from DSM 6.0.x (build ≤ 8754, e.g., DS215+-j) have no `dsm/run/synostorage/disks/` directory tree. There are no `container` or `id` files and no `diskmaps_*.result`.

For these systems:
- **Drive list:** available from `dsm/proc/partitions`
- **Bay assignment:** NOT AVAILABLE
- **Expansion unit detection:** NOT AVAILABLE
- **Drive model/serial:** NOT AVAILABLE from runtime files; may be extractable from `smartctl` output if present in the debug archive

---

*End of Hardware & Data Extraction Reference*
