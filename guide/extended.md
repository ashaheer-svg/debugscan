# Extended Database Extraction Guide — Synology Debug Analyser
**Version:** 1.0 — April 2026
**Scope:** Extraction, 1-year filtering, and AI-ready packaging of SQLite databases embedded inside Synology `.dat` debug ZIP archives.
**Read alongside:** `Hardwarev2.md` (hardware extraction), `ActiveDesign.md` (UI), `requirment.md` (project spec).

---

## Table of Contents

1. [Overview & Purpose](#1-overview--purpose)
2. [Extraction Pipeline](#2-extraction-pipeline)
3. [WAL File Handling — Critical](#3-wal-file-handling--critical)
4. [Database Reference — Schemas & Filter Strategy](#4-database-reference)
   - 4.1 [.SYNOSYSDB — System Events](#41-synosysdb--system-events)
   - 4.2 [.SYNODISKHEALTHDB — Disk Health & Prediction](#42-synodiskhealthdb--disk-health--prediction)
   - 4.3 [.SYNOCONNDB — Connection & Access Log](#43-synoconndb--connection--access-log)
   - 4.4 [.SYNODISKDB — Disk Event Log](#44-synodiskdb--disk-event-log)
5. [Packaging Formats — Options, Pros & Cons](#5-packaging-formats)
   - 5.1 [Format A — Raw JSON Dump](#51-format-a--raw-json-dump)
   - 5.2 [Format B — Filtered Markdown Tables](#52-format-b--filtered-markdown-tables)
   - 5.3 [Format C — Tiered (Summary + Errors) — RECOMMENDED](#53-format-c--tiered-summary--errors-only--recommended)
   - 5.4 [Format D — Aggregated Summary JSON](#54-format-d--aggregated-summary-json)
   - 5.5 [Format E — Prompt-Ready Narrative](#55-format-e--prompt-ready-narrative)
   - 5.6 [Format F — NDJSON Streaming](#56-format-f--ndjson-streaming)
6. [Recommended Packaging Strategy per Database](#6-recommended-packaging-strategy-per-database)
7. [Token Budget Reference](#7-token-budget-reference)
8. [Reference Python Extraction Module](#8-reference-python-extraction-module)
9. [AI Handoff Block Format](#9-ai-handoff-block-format)

---

## 1. Overview & Purpose

Synology `.dat` debug files are ZIP archives. Embedded inside every archive (DSM 6.x and 7.x confirmed) are multiple SQLite binary databases in `dsm/var/log/synolog/`. These databases are **not plain text** and cannot be read by grep or regex-based parsers.

They contain structured, timestamped event data that is often richer and more reliable than the plain-text log files, because:
- Events are already categorised by `level` (info / warning / err)
- Per-drive error counters in `.SYNODISKHEALTHDB` are aggregated across the device lifetime
- Connection and access events in `.SYNOCONNDB` include IP, protocol, username, and user agent
- The `.SYNODISKHEALTHDB` `prediction` table contains Synology's own AI-based drive failure scores

This guide defines how to extract these databases, filter to the **last 365 days**, and package the output for injection into an AI analysis prompt.

---

## 2. Extraction Pipeline

```
debug.dat (ZIP)
    │
    ├── Extract target DB files + WAL siblings to temp directory
    │       dsm/var/log/synolog/.SYNOSYSDB
    │       dsm/var/log/synolog/.SYNOSYSDB-wal      ← always extract if present
    │       dsm/var/log/synolog/.SYNOSYSDB-shm      ← always extract if present
    │       (repeat for each target DB)
    │
    ├── Open with Python sqlite3 (WAL handled automatically)
    │
    ├── Compute cutoff timestamp
    │       cutoff_unix = int(time.time()) - (365 * 86400)
    │       cutoff_date = (datetime.date.today() - timedelta(days=365)).isoformat()
    │
    ├── Run per-DB extraction queries (see Section 4)
    │
    ├── Package output (see Section 5 — choose format)
    │
    └── Delete temp directory
```

### Required imports
```python
import sqlite3, zipfile, tempfile, os, time, datetime, json
from pathlib import Path

# Databases to extract (base names, without -wal / -shm suffixes)
TARGET_DBS = [
    ".SYNOSYSDB",
    ".SYNODISKHEALTHDB",
    ".SYNOCONNDB",
    ".SYNODISKDB",
]

DB_BASE_PATH = "dsm/var/log/synolog/"
```

### Extraction helper
```python
def extract_dbs(zip_path: str, temp_dir: str) -> dict[str, str]:
    """
    Extract all target SQLite databases (+ WAL siblings) from the ZIP.
    Returns a dict of { db_name: local_file_path } for successfully extracted DBs.
    """
    found = {}
    with zipfile.ZipFile(zip_path, 'r') as zf:
        namelist = zf.namelist()
        for db_name in TARGET_DBS:
            base = DB_BASE_PATH + db_name
            for entry in namelist:
                if entry == base or entry.endswith(db_name) and 'synolog' in entry:
                    # Extract DB + WAL siblings
                    for suffix in ['', '-wal', '-shm']:
                        target = entry + suffix if suffix else entry
                        if target in namelist:
                            zf.extract(target, temp_dir)
                    local_path = os.path.join(temp_dir, entry.lstrip('/'))
                    if os.path.exists(local_path):
                        found[db_name] = local_path
                    break
    return found
```

---

## 3. WAL File Handling — Critical

Several databases operate in **WAL (Write-Ahead Log) mode**, particularly on newer DSM 7.x units:
- `.SYNOCONNDB-wal` + `.SYNOCONNDB-shm`
- `.SYNOSYSDB-wal` + `.SYNOSYSDB-shm`

**Rule:** If a `-wal` file exists alongside the main DB file, it means uncommitted transactions are pending in the WAL. The main `.DB` file alone will be **incomplete** — the WAL rows will be missing.

**Python's `sqlite3` module handles this automatically** provided all three files (`.DB`, `.DB-wal`, `.DB-shm`) are in the **same directory**. As long as the extraction preserves the flat directory structure (all files extracted to the same folder), no extra steps are needed.

```
# Correct: all three files in same directory
/tmp/extract/dsm/var/log/synolog/.SYNOCONNDB
/tmp/extract/dsm/var/log/synolog/.SYNOCONNDB-wal   ← auto-merged on open
/tmp/extract/dsm/var/log/synolog/.SYNOCONNDB-shm   ← auto-merged on open

# Wrong: extracting only .SYNOCONNDB — WAL rows are silently missing
```

---

## 4. Database Reference

### 4.1 `.SYNOSYSDB` — System Events

**File type tag:** `[SYNOSYSDB]`

**Purpose:** Synology's primary system audit log. Records DSM service events, RAID state changes, power events, failed logins at the DSM level, package installs/uninstalls, and hardware alerts. This is equivalent to `dmesg` + systemd journal at the application layer.

**Schema:**
```sql
CREATE TABLE logs (
    id        INTEGER PRIMARY KEY,
    time      INT     DEFAULT NULL,   -- Unix timestamp (integer)
    level     TEXT    DEFAULT NULL,   -- 'info' | 'warning' | 'err'
    username  TEXT    DEFAULT NULL,   -- Acting user, or 'SYSTEM'
    msg       TEXT    DEFAULT NULL    -- Human-readable event description
)
```

**Confirmed data characteristics:**
- DSM 6.x: ~851 rows total (7-year span). ~50 errors/warnings.
- DSM 7.x: ~4,951 rows total (4-year span). ~2,295 errors/warnings.
- No WAL observed on DSM 6.x; WAL present on DSM 7.x.
- `time` field is a standard Unix epoch integer — directly filterable.

**1-year filter query:**
```sql
SELECT time, level, username, msg
FROM logs
WHERE time >= :cutoff
ORDER BY time ASC
```

**Recommended extraction:**
- All rows within the 1-year window (both info and err/warning).
- Row count is manageable — typically 200–1,500 rows per year.
- Do NOT pre-filter to errors only — info events provide context (e.g., a reboot event before a crash).

**Notable event patterns to flag in AI prompt:**
| `msg` pattern | Significance |
|---|---|
| `Storage Pool [N] was crashed` | RAID failure — critical |
| `Storage Pool [N] has an insufficient number of drives` | Degraded RAID |
| `The power supply N has stopped providing power` | PSU failure |
| `The redundant power[N] on Expansion Unit` | Expansion unit PSU failure |
| `System booted up from an improper shutdown` | Unexpected reboot / power loss |
| `Detected low storage space on Volume` | Capacity warning |
| `Failed to send email` | Alert notification broken — admin blind |
| `System failed to start [Domain service]` | AD/LDAP integration failure |
| `failed command: READ FPDMA QUEUED` | Drive I/O failure (also in messages log) |

---

### 4.2 `.SYNODISKHEALTHDB` — Disk Health & Prediction

**File type tag:** `[SYNODISKHEALTHDB]`

**Purpose:** Synology's internal drive health tracking. Contains two tables:
1. `disk_error` — Lifetime cumulative error counters per drive (27 columns). **No timestamp** — this is a running tally, not a time series.
2. `prediction` — Synology's proprietary AI-based drive failure prediction scores, populated when Synology's DiskPrediction service has run. Often empty.

**Schema — `disk_error`:**
```sql
CREATE TABLE disk_error (
    Serial        TEXT NOT NULL,   -- Drive serial number (primary key effectively)
    Model         TEXT NOT NULL,   -- Drive model string
    MailTimestamp BIGINT DEFAULT 0, -- Unix ts of last alert email sent (0 = never)
    Retry         INT DEFAULT 0,
    BadSector     INT DEFAULT 0,   -- *** KEY: bad sectors detected by Synology ***
    UNC           INT DEFAULT 0,   -- Uncorrectable errors
    ICRC          INT DEFAULT 0,   -- Interface CRC errors
    IDNF          INT DEFAULT 0,   -- ID Not Found errors
    ABRT          INT DEFAULT 0,   -- Command Aborted errors
    IOERR         INT DEFAULT 0,   -- General I/O errors
    RecovData     INT DEFAULT 0,   -- Recovered data errors
    RecovComm     INT DEFAULT 0,   -- Recovered communication errors
    UnrecovData   INT DEFAULT 0,   -- Unrecoverable data errors
    Persist       INT DEFAULT 0,
    Proto         INT DEFAULT 0,   -- Protocol errors
    HostInt       INT DEFAULT 0,   -- Host interface errors
    PHYRdyChg     INT DEFAULT 0,   -- PHY ready change (cable/connector issue indicator)
    PHYInt        INT DEFAULT 0,   -- PHY internal errors
    CommWake      INT DEFAULT 0,
    E10B8B        INT DEFAULT 0,
    Dispar        INT DEFAULT 0,
    BadCRC        INT DEFAULT 0,   -- Bad CRC on SATA interface (cable quality indicator)
    Handshk       INT DEFAULT 0,   -- Handshake errors
    LinkSeq       INT DEFAULT 0,   -- Link sequence errors
    TrStaTrns     INT DEFAULT 0,
    UnrecFIS      INT DEFAULT 0,   -- Unrecognised FIS (SATA framing error)
    DevExch       INT DEFAULT 0    -- Device exchange count
)
```

**Schema — `prediction`:**
```sql
CREATE TABLE prediction (
    id        INTEGER PRIMARY KEY,
    date      DATE DEFAULT CURRENT_DATE,  -- DATE string 'YYYY-MM-DD'
    serial    TEXT DEFAULT NULL,
    model     TEXT DEFAULT NULL,
    firmware  TEXT DEFAULT NULL,
    container TEXT DEFAULT NULL,   -- 'internal' or expansion unit ID
    slot      TEXT DEFAULT NULL,   -- Physical bay number
    fail      INT DEFAULT 0,       -- *** 1 = predicted to fail, 0 = healthy ***
    ui_score  FLOAT DEFAULT 0.0,   -- Score displayed to user (0.0–1.0)
    score     FLOAT DEFAULT 0.0,   -- Internal raw model score
    threshold FLOAT DEFAULT 1.0,   -- Threshold above which fail=1
    observe   INT DEFAULT 0,       -- Observation count (days of data)
    version   INT DEFAULT 0,       -- Model version
    logtime   TEXT DEFAULT '0-0-0',
    factors   TEXT DEFAULT NULL,   -- JSON blob: contributing failure factors
    note      TEXT DEFAULT NULL,
    msg       TEXT DEFAULT NULL
)
```

**1-year filter strategy:**
- `disk_error`: **No timestamp column — always extract all rows.** This is a lifetime counter table. Typically 3–22 rows (one per drive). At ~27 columns per row, this is compact.
- `prediction`: Filter by `date >= :cutoff_date` (ISO format string `YYYY-MM-DD`).

**Extraction queries:**
```sql
-- disk_error: always extract all rows (no time filter possible)
SELECT * FROM disk_error ORDER BY BadSector DESC, UNC DESC

-- prediction: filter to last year
SELECT date, serial, model, slot, fail, ui_score, score, threshold, factors, msg
FROM prediction
WHERE date >= :cutoff_date
ORDER BY date DESC
```

**Critical fields for AI analysis:**
| Field | Non-zero significance |
|---|---|
| `BadSector` | Synology-detected bad sectors — more conservative than SMART pending count |
| `UNC` | Uncorrectable errors logged by Synology subsystem |
| `MailTimestamp` | Non-zero = Synology sent a health alert at this time. Convert from Unix timestamp. |
| `PHYRdyChg` + `BadCRC` + `Handshk` | Non-zero = physical cable/connector issue, not media failure |
| `prediction.fail` | 1 = Synology predicts imminent drive failure |
| `prediction.factors` | JSON — lists specific SMART attributes driving the prediction |

---

### 4.3 `.SYNOCONNDB` — Connection & Access Log

**File type tag:** `[SYNOCONNDB]`

**Purpose:** Records all access events to the NAS — every successful and failed login, every CIFS share mount, DSM web login, SSH session, VPN connection, Active Backup job, and SynologyDrive sync. This is the primary source for security analysis (brute-force detection, unauthorised access) and network topology (which clients connect, via which protocols).

**Schema:**
```sql
CREATE TABLE logs (
    id         INTEGER PRIMARY KEY,
    time       INT  DEFAULT NULL,      -- Unix timestamp
    level      TEXT DEFAULT NULL,      -- 'info' | 'warning' | 'err'
    username   TEXT DEFAULT NULL,      -- Username attempted/used
    msg        TEXT DEFAULT NULL,      -- Full event description (includes IP, hostname)
    user       TEXT DEFAULT NULL,      -- Alternate user field (some DSM versions)
    uid        TEXT DEFAULT NULL,      -- User ID
    ip         TEXT DEFAULT NULL,      -- Client IP (IPv4 or IPv6)
    protocol   TEXT DEFAULT NULL,      -- CIFS | DSM | SMB | SSH | ActiveBackup | SynologyDrive | VPN
    token      TEXT DEFAULT NULL,
    useragent  TEXT DEFAULT NULL       -- Browser/client user agent string
)
```

**Confirmed data volumes (1 year):**
- Small NAS (debug2, DSM 6.x): ~592 rows total.
- Enterprise NAS (debug_2070, DSM 7.x, 22 drives, busy network): ~7,319 rows total.
- `CIFS` protocol dominates on busy file servers (>90% of rows).
- `.SMBXFERDB` (not covered here) can be 34 MB+ and is separate — SMB transfer detail only.

**⚠ Volume warning:** On a busy NAS, SYNOCONNDB can easily have 5,000–15,000 rows per year. Sending all rows to an AI model is not feasible. Use Format C (Tiered) for this database exclusively.

**1-year filter query — errors/warnings only (for AI injection):**
```sql
SELECT time, level, username, ip, protocol, msg
FROM logs
WHERE time >= :cutoff
  AND level IN ('warning', 'err')
ORDER BY time ASC
```

**Aggregation query — for summary block:**
```sql
SELECT
    protocol,
    level,
    COUNT(*) as cnt
FROM logs
WHERE time >= :cutoff
GROUP BY protocol, level
ORDER BY cnt DESC
```

**Failed login aggregation (brute-force detection):**
```sql
SELECT ip, username, COUNT(*) as attempts, MIN(time) as first_attempt, MAX(time) as last_attempt
FROM logs
WHERE time >= :cutoff
  AND level = 'warning'
  AND msg LIKE '%failed%'
GROUP BY ip, username
HAVING attempts >= 3
ORDER BY attempts DESC
```

**External IP detection:**
```sql
SELECT ip, protocol, COUNT(*) as cnt
FROM logs
WHERE time >= :cutoff
  AND ip NOT LIKE '192.168.%'
  AND ip NOT LIKE '10.%'
  AND ip NOT LIKE '172.16.%'
  AND ip NOT LIKE '172.17.%'
  AND ip NOT LIKE '172.18.%'
  AND ip NOT LIKE '172.19.%'
  AND ip NOT LIKE '172.2%.%'
  AND ip NOT LIKE '172.3%.%'
  AND ip NOT LIKE 'fe80:%'
  AND ip IS NOT NULL AND ip != ''
GROUP BY ip, protocol
ORDER BY cnt DESC
```

**Notable patterns for AI flagging:**
| Pattern | Significance |
|---|---|
| `failed to sign in` from same IP, multiple times | Brute-force attempt |
| `SMB1 not permitted` | Legacy client using deprecated protocol — security risk |
| External IP accessing via CIFS/SMB | Unusual — NAS exposed to internet or VPN client |
| `failed to log in via [ActiveBackup]` | Broken backup job |
| Multiple usernames tried from same IP | Credential stuffing |
| `failed` events from internal IPs | Password management issue within LAN |

---

### 4.4 `.SYNODISKDB` — Disk Event Log

**File type tag:** `[SYNODISKDB]`

**Purpose:** Disk-level event log maintained by Synology's storage subsystem. Records I/O errors, SMART status changes, disk insertion/removal, disk tests, and read/write failures — all attributed to specific drives by model, serial, and physical slot number. This is the structured equivalent of grep-ing `messages` for drive errors, but pre-parsed and per-drive.

**Schema:**
```sql
CREATE TABLE logs (
    id        INTEGER PRIMARY KEY,
    time      INT  DEFAULT NULL,      -- Unix timestamp
    level     TEXT DEFAULT NULL,      -- 'info' | 'warning' | 'err' | 'debug'
    path      TEXT DEFAULT NULL,      -- Device path (e.g. /dev/sda)
    model     TEXT DEFAULT NULL,      -- Drive model string
    serial    TEXT DEFAULT NULL,      -- Drive serial number
    container TEXT DEFAULT NULL,      -- 'internal' or expansion unit identifier
    slot      TEXT DEFAULT NULL,      -- Physical bay number (integer as text)
    msg       TEXT DEFAULT NULL,      -- Event type: 'ioerr', 'unc', 'disk_refresh', 'insert', 'remove'
    show      INTEGER DEFAULT 1,
    errtype   TEXT DEFAULT NULL,      -- Error category: 'rw', 'smart', etc.
    raw       INTEGER DEFAULT 0,      -- Raw error code
    info      TEXT DEFAULT NULL,      -- Additional detail (JSON or plain text)
    eunit     TEXT DEFAULT NULL,      -- Expansion unit identifier
    note      TEXT DEFAULT NULL,
    logtime   INT  DEFAULT NULL       -- Secondary timestamp (sometimes used)
)
```

**Confirmed data characteristics:**
- DSM 6.x failing NAS: 74 rows, 68 errors — almost entirely `ioerr` events on the failing drive (WD-WMAZA7638989, slot 1), spanning Dec 2021 – Jan 2022.
- DSM 7.x healthy NAS: 133 rows, 121 are `debug` level `disk_refresh` events (routine polling). Only 3 warnings.
- `debug` level events (`disk_refresh`) are noise — exclude from AI injection.

**1-year filter query:**
```sql
SELECT time, level, model, serial, slot, container, msg, errtype, info
FROM logs
WHERE time >= :cutoff
  AND level IN ('info', 'warning', 'err')   -- exclude 'debug'
ORDER BY time ASC
```

**Aggregation by drive (for summary):**
```sql
SELECT serial, model, slot, level, msg, COUNT(*) as cnt
FROM logs
WHERE time >= :cutoff
  AND level IN ('warning', 'err')
GROUP BY serial, msg
ORDER BY cnt DESC
```

**Critical `msg` values:**
| `msg` value | Significance |
|---|---|
| `ioerr` | I/O error on this drive — corroborates SMART pending sectors |
| `unc` | Uncorrectable read error — data loss event |
| `rw` (`errtype`) | Read/write error type — physical media |
| `remove` | Drive removed (could be failure or intentional) |
| `insert` | Drive inserted (replacement) |
| `smart` (`errtype`) | SMART threshold exceeded |

---

## 5. Packaging Formats

### Comparison Matrix

| Format | Token Cost | AI Readability | Evidence Preserved | Best For |
|---|---|---|---|---|
| A — Raw JSON | Very High (3–8x) | Medium | Full | Small DBs only (SYNODISKHEALTHDB) |
| B — Markdown Tables | High (2–4x) | High | Good | Small/medium DBs, human review |
| C — Tiered Summary + Errors | **Low (1x baseline)** | **High** | Selective (errors only) | **All DBs — recommended default** |
| D — Aggregated JSON | Very Low (0.3x) | Medium | Counts only | Context header / dashboard |
| E — Narrative Text | Low (0.8x) | Very High | Weak | Final report generation only |
| F — NDJSON Streaming | Medium (1.5x) | Low | Full | Pipeline/batch processing |

---

### 5.1 Format A — Raw JSON Dump

Every row from the filtered query is serialised as a JSON array of objects.

```json
{
  "source": "SYNOSYSDB",
  "period": "2025-04-07 to 2026-04-07",
  "rows": [
    { "time": "2025-05-12 09:14:22", "level": "err", "username": "SYSTEM", "msg": "Storage Pool [1] was crashed." },
    { "time": "2025-05-12 09:14:30", "level": "info", "username": "SYSTEM", "msg": "System successfully restarted." }
  ]
}
```

**Pros:**
- Complete data — no information loss.
- Programmatically parseable — downstream code can process it.
- Field names are self-documenting.
- Easy to generate (`json.dumps(rows)`).

**Cons:**
- Very high token cost — field names repeat on every row.
- SYNOCONNDB at 7,000+ rows would consume 15,000–40,000 tokens alone.
- AI models given too much data tend to miss patterns — information overload.
- Not human-readable without a JSON viewer.

**Use only for:** `.SYNODISKHEALTHDB` (disk_error table, maximum 22 rows, no timestamp filter possible). Too expensive for any other database.

---

### 5.2 Format B — Filtered Markdown Tables

Rows are rendered as GitHub-flavoured markdown tables, filtered to errors and warnings only.

```markdown
## [SYNOSYSDB] System Events — Last 12 Months
**Range:** 2025-04-07 to 2026-04-07 | **Total rows in period:** 312 | **Errors:** 18 | **Warnings:** 44

| Timestamp | Level | User | Event |
|---|---|---|---|
| 2025-11-26 23:11:58 | err | SYSTEM | The power supply 1 has stopped providing power. |
| 2025-11-28 10:27:16 | err | SYSTEM | The power supply 1 has stopped providing power. |
| 2026-03-30 16:36:38 | err | SYSTEM | Storage Pool [1] has an insufficient number of drives. |
```

**Pros:**
- Highly readable by both humans and AI models.
- Table structure makes it easy for AI to identify patterns across rows.
- Markdown renders cleanly in the UI report.
- Can be directly embedded in a prompt without preprocessing.

**Cons:**
- Still verbose for large datasets — each row is one table line.
- Column alignment adds some overhead vs. plain text.
- Not machine-parseable without a markdown parser.
- Pipe characters in `msg` field must be escaped.

**Use for:** `.SYNOSYSDB` (all rows in period), `.SYNODISKDB` (error/warning rows only).

---

### 5.3 Format C — Tiered (Summary + Errors Only) — RECOMMENDED

A two-block structure per database: a compact **summary header** (aggregate statistics), followed by **full detail only for err/warning rows**.

```
[SYNOCONNDB] Connection & Access Log
Period: 2025-04-07 → 2026-04-07

SUMMARY
  Total events in period : 7,319
  Info events            : 7,299
  Warning events         :    19
  Error events           :     1
  Protocols active       : CIFS (7,283), DSM (17), SMB (19)
  Unique client IPs      : 47
  External IPs detected  : 1 (IPv6 remote user — Sameera, CIFS, confirmed recurring)
  Failed logins          : 20 warning-level events

FAILED LOGINS / ERRORS (full detail)
  2025-12-09 10:24:21 | warning | SYSTEM      | 192.168.1.26 | SMB  | Host [192.168.1.26] failed via SMB1 not permitted
  2025-12-09 10:24:21 | warning | SYSTEM      | 192.168.1.26 | SMB  | Host [192.168.1.26] failed via SMB1 not permitted
  2026-03-30 16:49:08 | warning | Active      | 192.168.0.52 | DSM  | User [Active] failed to sign in via password — max attempts
  ...

BRUTE-FORCE ANALYSIS (IPs with 3+ failed attempts)
  192.168.1.26 : 4 SMB1 failures (legacy client — not brute force, SMB1 disabled)
  192.168.0.52 : 1 DSM failure (isolated)
```

**Pros:**
- Lowest token cost of any format that preserves evidence — summary is 20–40 tokens, error detail is proportional only to actual problems found.
- AI gets counts and context first (summary), then evidence (detail). This mirrors how a human analyst works.
- On a healthy NAS with no errors, the detail section may be empty — near-zero cost.
- On a failing NAS, all critical events are preserved in full.
- Easily extensible — add a `RECOMMENDATIONS` block at the end.

**Cons:**
- Suppresses info-level rows — AI cannot see routine events between errors.
- Brute-force and access pattern detection requires the aggregation queries to be run server-side before packaging (slightly more extraction complexity).
- If an important event is at `info` level (e.g., "system shutdown before a crash"), it will be missed.

**Mitigation:** Include the 10 events immediately before and after each `err` event (context window around errors) to preserve causality.

**Use for:** `.SYNOCONNDB` (mandatory — too large otherwise), `.SYNODISKDB` (error/warning + debug excluded).

---

### 5.4 Format D — Aggregated Summary JSON

Only statistical aggregates are extracted — no individual rows.

```json
{
  "source": "SYNOCONNDB",
  "period_days": 365,
  "total_events": 7319,
  "by_level": { "info": 7299, "warning": 19, "err": 1 },
  "by_protocol": { "CIFS": 7283, "DSM": 17, "SMB": 19 },
  "failed_login_ips": [
    { "ip": "192.168.1.26", "attempts": 4, "reason": "SMB1 not permitted" }
  ],
  "external_ips": 1
}
```

**Pros:**
- Extremely compact — 5–20 tokens per database.
- Suitable for dashboard-level display without any AI inference.
- Fast to generate.

**Cons:**
- No evidence — AI cannot prove any finding, violating the project requirement (`any problems identified has to be proven with log entries`).
- Useless for root cause analysis — only good for "something happened" not "here is proof".
- Cannot be used as the sole format for AI injection.

**Use only for:** The summary header block inside Format C. Never as a standalone format for AI analysis.

---

### 5.5 Format E — Prompt-Ready Narrative

Raw data is pre-synthesised into human-readable prose before being passed to the AI. The AI receives a paragraph, not a table.

```
The system event log covers 312 events from May 2025 to April 2026.
Of these, 18 are errors and 44 are warnings. Three critical patterns were
observed: (1) Power supply 1 on the RX1217rp expansion unit failed on six
separate occasions between November 2025 and March 2026, indicating a failing
PSU. (2) All three volumes repeatedly hit low-space warnings from November
onwards. (3) Storage Pool 1 was degraded briefly on 2026-03-30.
```

**Pros:**
- Most AI-friendly format — models are tuned to process natural language.
- Very low token cost for the volume of information conveyed.
- Immediately reportable — output can go directly into the UI report.

**Cons:**
- Requires a first-pass AI call to generate the narrative before the analysis call — two LLM calls per database.
- Pre-synthesised narrative may lose nuance or introduce hallucinations before the analysis AI even sees the data.
- Cannot be used for `proof with log entries` — the raw timestamps and event text are gone.
- Defeats the purpose of AI analysis if the analysis is already done in the synthesis step.

**Use for:** Report generation layer only, after analysis is complete. Not for the analysis input.

---

### 5.6 Format F — NDJSON Streaming

Each filtered row is serialised as one JSON object per line (newline-delimited JSON).

```
{"time":"2025-11-26 23:11:58","level":"err","username":"SYSTEM","msg":"The power supply 1 has stopped providing power."}
{"time":"2025-11-28 10:27:16","level":"err","username":"SYSTEM","msg":"The power supply 1 has stopped providing power."}
```

**Pros:**
- Streamable — can be processed line-by-line without loading everything into memory.
- No nesting overhead compared to JSON arrays.
- Easy to append rows incrementally.
- Supported by many log analysis tools natively.

**Cons:**
- Not human-readable without tooling.
- Still high token cost — field names repeat on every line.
- Less structured than Format A for AI consumption.
- AI models handle JSON arrays better than NDJSON in practice.

**Use for:** Intermediate pipeline format if building a streaming processor. Not for direct AI injection.

---

## 6. Recommended Packaging Strategy per Database

| Database | Format | Rows Included | Rationale |
|---|---|---|---|
| `.SYNOSYSDB` | **B (Markdown Table)** | All rows in 1-year window | Small volume, all events have context value, AI needs full timeline |
| `.SYNODISKHEALTHDB` `disk_error` | **A (Raw JSON)** | All rows (no time filter) | Maximum 22 rows, 27 cols — compact; lifetime counters must be complete |
| `.SYNODISKHEALTHDB` `prediction` | **A (Raw JSON)** | All rows in 1-year window | Usually empty; when populated, every field is analytically significant |
| `.SYNOCONNDB` | **C (Tiered)** | Summary + warning/err + brute-force aggregation | Can be 7,000+ rows; info rows are noise for analysis purposes |
| `.SYNODISKDB` | **C (Tiered)** | Summary + warning/err rows; exclude `debug` level | `disk_refresh` debug rows are routine noise; errors are the signal |

---

## 7. Token Budget Reference

Approximate token costs per database using recommended formats, based on confirmed sample data:

| Database | Healthy NAS | Failing/Busy NAS | Notes |
|---|---|---|---|
| `.SYNOSYSDB` (Format B) | ~300–600 tokens | ~1,500–3,000 tokens | Scales with error count |
| `.SYNODISKHEALTHDB` (Format A) | ~400–800 tokens | ~400–800 tokens | Fixed by drive count (3–22 drives) |
| `.SYNOCONNDB` (Format C) | ~150–300 tokens | ~400–800 tokens | Summary block is fixed; detail scales with failures |
| `.SYNODISKDB` (Format C) | ~100–200 tokens | ~500–1,500 tokens | Scales with I/O error frequency |
| **Total (4 databases)** | **~950–1,900 tokens** | **~2,800–6,300 tokens** | Well within Groq context window |

This budget leaves substantial room for the hardware extraction output (from `Hardwarev2.md` pipeline), SMART data, RAID state, and the AI system prompt.

---

## 8. Reference Python Extraction Module

```python
import sqlite3, zipfile, tempfile, os, time, datetime, json
from pathlib import Path

# ── Constants ──────────────────────────────────────────────────────────────
DB_BASE = "dsm/var/log/synolog/"
TARGET_DBS = [".SYNOSYSDB", ".SYNODISKHEALTHDB", ".SYNOCONNDB", ".SYNODISKDB"]
YEAR_SECONDS = 365 * 86400

def ts(unix_int):
    """Convert Unix timestamp to readable string."""
    try:
        return datetime.datetime.fromtimestamp(int(unix_int)).strftime('%Y-%m-%d %H:%M:%S')
    except:
        return str(unix_int)

def get_cutoffs():
    now = int(time.time())
    cutoff_unix = now - YEAR_SECONDS
    cutoff_date = (datetime.date.today() - datetime.timedelta(days=365)).isoformat()
    return cutoff_unix, cutoff_date

def extract_dbs_from_zip(zip_path: str, tmp_dir: str) -> dict:
    found = {}
    with zipfile.ZipFile(zip_path, 'r') as zf:
        names = set(zf.namelist())
        for db_name in TARGET_DBS:
            candidates = [n for n in names if n.endswith(db_name) and 'synolog' in n]
            if not candidates:
                continue
            base_entry = candidates[0]
            for suffix in ['', '-wal', '-shm']:
                entry = base_entry + suffix
                if entry in names:
                    zf.extract(entry, tmp_dir)
            local = os.path.join(tmp_dir, base_entry.lstrip('/').lstrip('\\'))
            if os.path.exists(local):
                found[db_name] = local
    return found

def open_db(path: str) -> sqlite3.Connection:
    con = sqlite3.connect(path)  # WAL auto-handled if -wal/-shm in same dir
    con.row_factory = sqlite3.Row
    return con

# ── [SYNOSYSDB] ────────────────────────────────────────────────────────────
def extract_synosysdb(db_path: str, cutoff_unix: int) -> str:
    """Format B — Markdown table, all rows in period."""
    con = open_db(db_path)
    cur = con.cursor()
    stats = dict(cur.execute(
        "SELECT COUNT(*) total, SUM(level='err') errs, SUM(level='warning') warns "
        "FROM logs WHERE time >= ?", (cutoff_unix,)
    ).fetchone())
    rows = cur.execute(
        "SELECT time, level, username, msg FROM logs WHERE time >= ? ORDER BY time ASC",
        (cutoff_unix,)
    ).fetchall()
    con.close()

    lines = [
        "## [SYNOSYSDB] System Events — Last 12 Months",
        f"Total: {stats['total']} | Errors: {stats['errs']} | Warnings: {stats['warns']}",
        "",
        "| Timestamp | Level | User | Event |",
        "|---|---|---|---|",
    ]
    for r in rows:
        msg = str(r['msg'] or '').replace('|', '\\|')
        lines.append(f"| {ts(r['time'])} | {r['level']} | {r['username'] or ''} | {msg[:120]} |")
    return '\n'.join(lines)

# ── [SYNODISKHEALTHDB] ─────────────────────────────────────────────────────
def extract_synodiskhealthdb(db_path: str, cutoff_date: str) -> str:
    """Format A — Raw JSON for both tables."""
    con = open_db(db_path)
    errors = [dict(r) for r in con.execute("SELECT * FROM disk_error ORDER BY BadSector DESC, UNC DESC").fetchall()]
    predictions = [dict(r) for r in con.execute(
        "SELECT date, serial, model, slot, fail, ui_score, score, threshold, factors, msg "
        "FROM prediction WHERE date >= ? ORDER BY date DESC", (cutoff_date,)
    ).fetchall()]
    con.close()

    out = {
        "source": "[SYNODISKHEALTHDB]",
        "note": "disk_error contains lifetime counters (no time filter). prediction filtered to last 12 months.",
        "disk_error": errors,
        "prediction": predictions
    }
    return json.dumps(out, indent=2)

# ── [SYNOCONNDB] ──────────────────────────────────────────────────────────
def extract_synoconndb(db_path: str, cutoff_unix: int) -> str:
    """Format C — Tiered: summary + failed logins + errors only."""
    con = open_db(db_path)

    # Summary aggregates
    total = con.execute("SELECT COUNT(*) FROM logs WHERE time >= ?", (cutoff_unix,)).fetchone()[0]
    by_level = {r[0]: r[1] for r in con.execute(
        "SELECT level, COUNT(*) FROM logs WHERE time >= ? GROUP BY level", (cutoff_unix,))}
    by_proto = {r[0]: r[1] for r in con.execute(
        "SELECT protocol, COUNT(*) FROM logs WHERE time >= ? GROUP BY protocol ORDER BY 2 DESC LIMIT 10",
        (cutoff_unix,))}
    ext_ips = con.execute(
        "SELECT COUNT(DISTINCT ip) FROM logs WHERE time >= ? "
        "AND ip NOT LIKE '192.168.%' AND ip NOT LIKE '10.%' "
        "AND ip NOT LIKE '172.1_.%' AND ip NOT LIKE 'fe80:%' "
        "AND ip IS NOT NULL AND ip != ''", (cutoff_unix,)
    ).fetchone()[0]

    # Error/warning detail
    err_rows = con.execute(
        "SELECT time, level, username, ip, protocol, msg FROM logs "
        "WHERE time >= ? AND level IN ('warning','err') ORDER BY time ASC",
        (cutoff_unix,)
    ).fetchall()

    # Brute-force aggregation
    bf_rows = con.execute(
        "SELECT ip, username, COUNT(*) attempts, MIN(time) first_seen, MAX(time) last_seen "
        "FROM logs WHERE time >= ? AND level='warning' AND msg LIKE '%failed%' "
        "GROUP BY ip, username HAVING attempts >= 3 ORDER BY attempts DESC",
        (cutoff_unix,)
    ).fetchall()
    con.close()

    proto_str = ', '.join(f"{k} ({v})" for k, v in by_proto.items())
    lines = [
        "## [SYNOCONNDB] Connection & Access Log — Last 12 Months",
        "",
        "### SUMMARY",
        f"  Total events       : {total}",
        f"  Info               : {by_level.get('info', 0)}",
        f"  Warning            : {by_level.get('warning', 0)}",
        f"  Error              : {by_level.get('err', 0)}",
        f"  Protocols          : {proto_str}",
        f"  External IPs       : {ext_ips}",
        "",
        "### ERRORS & WARNINGS (full detail)",
    ]
    if err_rows:
        lines += ["| Timestamp | Level | User | IP | Protocol | Event |", "|---|---|---|---|---|---|"]
        for r in err_rows:
            msg = str(r['msg'] or '').replace('|', '\\|')
            lines.append(f"| {ts(r['time'])} | {r['level']} | {r['username'] or ''} | {r['ip'] or ''} | {r['protocol'] or ''} | {msg[:100]} |")
    else:
        lines.append("  No errors or warnings in period.")

    if bf_rows:
        lines += ["", "### BRUTE-FORCE / REPEATED FAILURES (3+ attempts from same IP)"]
        for r in bf_rows:
            lines.append(f"  {r['ip']} | user={r['username']} | {r['attempts']} attempts | {ts(r['first_seen'])} → {ts(r['last_seen'])}")

    return '\n'.join(lines)

# ── [SYNODISKDB] ──────────────────────────────────────────────────────────
def extract_synodiskdb(db_path: str, cutoff_unix: int) -> str:
    """Format C — Tiered: summary + error/warning rows (exclude debug)."""
    con = open_db(db_path)
    stats = {r[0]: r[1] for r in con.execute(
        "SELECT level, COUNT(*) FROM logs WHERE time >= ? GROUP BY level", (cutoff_unix,))}
    per_drive = con.execute(
        "SELECT serial, model, slot, msg, COUNT(*) cnt FROM logs "
        "WHERE time >= ? AND level IN ('warning','err') GROUP BY serial, msg ORDER BY cnt DESC",
        (cutoff_unix,)
    ).fetchall()
    err_rows = con.execute(
        "SELECT time, level, model, serial, slot, msg, errtype, info FROM logs "
        "WHERE time >= ? AND level IN ('info','warning','err') ORDER BY time ASC",
        (cutoff_unix,)
    ).fetchall()
    con.close()

    lines = [
        "## [SYNODISKDB] Disk Event Log — Last 12 Months",
        "",
        "### SUMMARY",
        f"  Info: {stats.get('info',0)} | Warning: {stats.get('warning',0)} | Error: {stats.get('err',0)} | Debug (excluded): {stats.get('debug',0)}",
        "",
        "### ERROR FREQUENCY PER DRIVE",
    ]
    if per_drive:
        for r in per_drive:
            lines.append(f"  Serial={r['serial']} Model={r['model']} Slot={r['slot']} | msg={r['msg']} | count={r['cnt']}")
    else:
        lines.append("  No drive errors in period.")

    lines += ["", "### DISK EVENTS (full detail, debug excluded)", "| Timestamp | Level | Model | Serial | Slot | Event | ErrType |", "|---|---|---|---|---|---|---|"]
    for r in err_rows:
        info_str = str(r['info'] or '')[:40].replace('|', '\\|')
        lines.append(f"| {ts(r['time'])} | {r['level']} | {r['model'] or ''} | {r['serial'] or ''} | {r['slot'] or ''} | {r['msg'] or ''} | {r['errtype'] or ''} |")
    return '\n'.join(lines)

# ── Master orchestrator ────────────────────────────────────────────────────
def extract_all_dbs(zip_path: str) -> dict:
    """
    Main entry point. Returns dict of { db_name: formatted_string }.
    Call this from Phase 1 extraction pipeline.
    """
    cutoff_unix, cutoff_date = get_cutoffs()
    results = {}

    with tempfile.TemporaryDirectory() as tmp:
        dbs = extract_dbs_from_zip(zip_path, tmp)

        extractors = {
            ".SYNOSYSDB":         lambda p: extract_synosysdb(p, cutoff_unix),
            ".SYNODISKHEALTHDB":  lambda p: extract_synodiskhealthdb(p, cutoff_date),
            ".SYNOCONNDB":        lambda p: extract_synoconndb(p, cutoff_unix),
            ".SYNODISKDB":        lambda p: extract_synodiskdb(p, cutoff_unix),
        }

        for db_name, extractor in extractors.items():
            if db_name in dbs:
                try:
                    results[db_name] = extractor(dbs[db_name])
                except Exception as e:
                    results[db_name] = f"[EXTRACTION ERROR: {db_name}] {e}"
            else:
                results[db_name] = f"[NOT FOUND: {db_name} not present in archive]"

    return results
```

---

## 9. AI Handoff Block Format

When assembling the final prompt payload for the AI model (Groq), concatenate the database extracts in this order:

```
<database_evidence>

{results[".SYNOSYSDB"]}

---

{results[".SYNODISKHEALTHDB"]}

---

{results[".SYNOCONNDB"]}

---

{results[".SYNODISKDB"]}

</database_evidence>
```

**Instructions to include in the AI system prompt:**

```
The <database_evidence> block above contains structured data extracted from 
four Synology internal SQLite databases. Each section is tagged with its source 
database type:

[SYNOSYSDB]       — System event log. Contains service events, RAID status 
                    changes, power events, and package alerts.
[SYNODISKHEALTHDB]— Drive health tracking. disk_error contains lifetime error 
                    counters per drive. prediction contains Synology's own 
                    failure prediction scores (empty = prediction not run).
[SYNOCONNDB]      — Connection and access log. Contains login events, file 
                    access by protocol and IP, failed authentication attempts.
[SYNODISKDB]      — Disk-level I/O event log. Contains per-drive error events 
                    attributed by model, serial number, and physical slot.

When identifying issues, you MUST cite the specific database, timestamp, and 
event text as evidence. Do not state conclusions without log proof.
```

---

## 10. Networking Extraction — Ports, IP Configuration & Interface Health

**File type tag:** `[NETWORK]`

**Confirmed present in:** All sample archives tested (DSM 6.x and 7.x). Files are snapshot-at-capture — they reflect the live state of the NAS at the moment the debug file was generated.

---

### 10.1 Available Network Data Sources (by file)

All files sit under `dsm/result/` or `dsm/etc/sysconfig/` inside the ZIP archive.

| File | Present | Content | Diagnostic Value |
|---|---|---|---|
| `dsm/result/ifconfig.result` | Both DSM 6.x & 7.x | IP addresses, MACs, TX/RX packets, errors, MTU, interface flags | HIGH — primary IP config snapshot |
| `dsm/result/ethtool.ethN.result` | Both (per interface) | Link speed, duplex, auto-negotiation, link detected, WoL, MDI-X | HIGH — detects disconnected/misconfigured ports |
| `dsm/result/ethtool_stats.ethN.result` | Both (per interface) | NIC hardware counters: CRC errors, dropped, flow control, missed | HIGH — detects physical layer errors |
| `dsm/result/ethtool_info.ethN.result` | Both (per interface) | NIC driver name, version, firmware, PCI bus slot | MEDIUM — driver/firmware identification |
| `dsm/result/route.result` | Both | Kernel routing table: destination, gateway, genmask, iface | HIGH — detects routing misconfiguration |
| `dsm/result/iproute.result` | DSM 7.x only | `ip route` output — includes `dead linkdown` flag per route | HIGH — directly flags disconnected routes |
| `dsm/result/ip6route.result` | DSM 7.x only | IPv6 routing table | LOW — unless IPv6 is in use |
| `dsm/result/netstat.result` | Both | Open ports, listening services, established connections | HIGH — service port audit |
| `dsm/result/chrony.result` | DSM 7.x only | NTP sync status, stratum, reference server, drift | HIGH — desynchronised NTP causes log timestamp corruption |
| `dsm/var/log/systemd/rc-network.service.log` | Both | Network interface startup events | MEDIUM — interface bring-up errors |
| `dsm/var/log/systemd/syno-network-check.service.log` | Both | iptables init, DNS resolution check, speed detection | MEDIUM — firewall and DNS bootstrap |
| `dsm/etc/sysconfig/network` | DSM 6.x | Hostname, NETWORKING flag | LOW |
| `dsm/etc/sysconfig/network-scripts/ifcfg-ethN` | DSM 6.x | Per-interface: BOOTPROTO (dhcp/static), BRIDGE config | HIGH — IP assignment method |
| `SMBService/etc/samba/smb.conf` | Both | Samba server config: workgroup, security, interfaces | MEDIUM — SMB binding and security level |
| `SMBService/result/smbstatus.result` | Both | Active SMB sessions at capture time | MEDIUM — concurrent SMB connections |

---

### 10.2 Interface Enumeration Strategy

Interfaces must be discovered dynamically — do not hardcode `eth0`/`eth1`.

```python
import zipfile, re

def enumerate_interfaces(zip_path: str) -> list[str]:
    """
    Returns list of physical interface names found in the archive.
    Example: ['eth0', 'eth1', 'eth2', 'eth3']
    Excludes: lo, sit0, docker0, ovs-system, tun
    """
    SKIP = {'lo', 'sit0', 'docker0', 'ovs-system', 'tun', 'ovs_eth0', 'ovs_eth1'}
    ifaces = set()
    pattern = re.compile(r'dsm/result/ethtool\.([^.]+)\.result$')
    with zipfile.ZipFile(zip_path, 'r') as zf:
        for name in zf.namelist():
            m = pattern.match(name)
            if m:
                iface = m.group(1)
                if iface not in SKIP:
                    ifaces.add(iface)
    return sorted(ifaces)
```

---

### 10.3 Extraction Queries per File

#### `ifconfig.result` — IP Configuration Snapshot

```python
import re, zipfile

def parse_ifconfig(zip_path: str) -> list[dict]:
    """
    Parse ifconfig.result — works on both DSM 6.x (old-style) and 7.x output.
    Returns list of interface dicts.
    """
    results = []
    with zipfile.ZipFile(zip_path, 'r') as zf:
        candidates = [n for n in zf.namelist() if n.endswith('ifconfig.result')]
        if not candidates: return results
        text = zf.read(candidates[0]).decode('utf-8', errors='replace')

    # Split by interface blocks (name at start of line, no leading spaces)
    blocks = re.split(r'\n(?=\S)', text.strip())
    for block in blocks:
        iface = {}
        # Interface name
        m = re.match(r'^(\S+)\s+Link encap:(\S+)', block)
        if m:
            iface['name'] = m.group(1)
            iface['encap'] = m.group(2)
        # MAC
        m = re.search(r'HWaddr\s+([\dA-Fa-f:]{17})', block)
        if m: iface['mac'] = m.group(1)
        # IPv4
        m = re.search(r'inet addr:([\d.]+)', block)
        if m: iface['ipv4'] = m.group(1)
        m = re.search(r'Mask:([\d.]+)', block)
        if m: iface['mask'] = m.group(1)
        m = re.search(r'Bcast:([\d.]+)', block)
        if m: iface['broadcast'] = m.group(1)
        # IPv6
        m = re.search(r'inet6 addr:\s*(\S+)', block)
        if m: iface['ipv6'] = m.group(1)
        # Flags
        iface['flags'] = re.findall(r'\b(UP|BROADCAST|RUNNING|MULTICAST|SLAVE|LOOPBACK)\b', block)
        # MTU
        m = re.search(r'MTU:(\d+)', block)
        if m: iface['mtu'] = int(m.group(1))
        # Packet counters
        m = re.search(r'RX packets:(\d+) errors:(\d+) dropped:(\d+)', block)
        if m: iface['rx_packets'], iface['rx_errors'], iface['rx_dropped'] = int(m.group(1)), int(m.group(2)), int(m.group(3))
        m = re.search(r'TX packets:(\d+) errors:(\d+) dropped:(\d+)', block)
        if m: iface['tx_packets'], iface['tx_errors'], iface['tx_dropped'] = int(m.group(1)), int(m.group(2)), int(m.group(3))
        # Byte counters
        m = re.search(r'RX bytes:(\d+)', block)
        if m: iface['rx_bytes'] = int(m.group(1))
        m = re.search(r'TX bytes:(\d+)', block)
        if m: iface['tx_bytes'] = int(m.group(1))

        if 'name' in iface:
            results.append(iface)
    return results
```

**Diagnostic flags to raise automatically:**
- `RUNNING` absent from flags → interface is UP but has no carrier (cable unplugged or switch port down)
- `ipv4` matches `169.254.x.x` (APIPA) → DHCP failed on this interface — it self-assigned a link-local address
- `rx_errors > 0` or `tx_errors > 0` → packet-level errors — suspect cable, NIC, or switch port
- Interface has `SLAVE` in flags → it is a bond member, not independently addressed

---

#### `ethtool.ethN.result` — Link Speed & Physical State

```python
def parse_ethtool(zip_path: str, iface: str) -> dict:
    result = {}
    path = f'dsm/result/ethtool.{iface}.result'
    with zipfile.ZipFile(zip_path, 'r') as zf:
        if path not in zf.namelist(): return {'name': iface, 'error': 'not found'}
        text = zf.read(path).decode('utf-8', errors='replace')

    result['name'] = iface
    m = re.search(r'Speed:\s*(.+)', text)
    result['speed'] = m.group(1).strip() if m else 'Unknown'
    m = re.search(r'Duplex:\s*(.+)', text)
    result['duplex'] = m.group(1).strip() if m else 'Unknown'
    m = re.search(r'Link detected:\s*(\w+)', text)
    result['link'] = m.group(1) if m else 'Unknown'
    m = re.search(r'Auto-negotiation:\s*(\w+)', text)
    result['autoneg'] = m.group(1) if m else 'Unknown'
    m = re.search(r'Port:\s*(.+)', text)
    result['port_type'] = m.group(1).strip() if m else 'Unknown'

    return result
```

**Diagnostic flags to raise automatically:**
- `Link detected: no` → port not connected — flag by interface name for the report
- `Speed: Unknown!` → link negotiation failed — often accompanies `Link detected: no`
- `Duplex: Half` on a GbE port → duplex mismatch with switch — causes severe throughput degradation and high collision counts
- `Auto-negotiation: off` → manually forced speed — may cause mismatch with switch

---

#### `ethtool_stats.ethN.result` — NIC Hardware Error Counters

```python
def parse_ethtool_stats(zip_path: str, iface: str) -> dict:
    path = f'dsm/result/ethtool_stats.{iface}.result'
    stats = {'name': iface}
    with zipfile.ZipFile(zip_path, 'r') as zf:
        if path not in zf.namelist(): return stats
        text = zf.read(path).decode('utf-8', errors='replace')
    for line in text.splitlines():
        m = re.match(r'\s+(\w+):\s*(\d+)', line)
        if m:
            stats[m.group(1)] = int(m.group(2))
    return stats

# Key counters to check:
CRITICAL_COUNTERS = [
    'rx_crc_errors',      # >0 = physical layer error (cable quality, EMI)
    'rx_errors',          # >0 = general receive errors
    'tx_errors',          # >0 = transmit errors
    'rx_missed_errors',   # >0 = NIC buffer overflow — CPU can't keep up
    'rx_no_buffer_count', # >0 = driver buffer exhaustion
    'rx_fifo_errors',     # >0 = FIFO overflow
    'rx_frame_errors',    # >0 = frame alignment error (hardware issue)
    'tx_carrier_errors',  # >0 = carrier lost during transmit
    'rx_over_errors',     # >0 = receive ring buffer overflow
]
FLOW_CONTROL_COUNTERS = [
    'rx_flow_control_xon',   # >0 = switch is sending pause frames (upstream congestion)
    'rx_flow_control_xoff',  # >0 = switch is throttling NAS receive rate
    'tx_flow_control_xon',   # >0 = NAS is sending pause frames upstream
    'tx_flow_control_xoff',
]
```

**Diagnostic rules:**
- `rx_crc_errors > 0` → physical layer problem: bad cable, damaged SFP, noisy patch panel. The NAS received frames with bad checksums.
- `rx_missed_errors > 0` or `rx_no_buffer_count > 0` → the NIC is dropping packets because the CPU is too slow to drain the receive ring. Indicates system overload.
- `rx_flow_control_xoff > 0` → the upstream switch is rate-limiting this port. Indicates network congestion or a full switch buffer.
- Both `rx_flow_control_xon` and `rx_flow_control_xoff` > 0 together → active flow control negotiation happening — investigate switch-side congestion.

---

#### `route.result` + `iproute.result` — Routing Table

```python
def parse_route(zip_path: str) -> list[dict]:
    routes = []
    # Try iproute first (DSM 7.x — more detailed, includes dead linkdown)
    for fname in ['dsm/result/iproute.result', 'dsm/result/route.result']:
        with zipfile.ZipFile(zip_path, 'r') as zf:
            if fname not in zf.namelist(): continue
            text = zf.read(fname).decode('utf-8', errors='replace')

        if 'iproute' in fname:
            # Format: "default via x.x.x.x dev ethN  src x.x.x.x [dead linkdown]"
            for line in text.splitlines():
                r = {'raw': line.strip()}
                r['linkdown'] = 'linkdown' in line or 'dead' in line
                m = re.search(r'via\s+([\d.]+)', line)
                if m: r['gateway'] = m.group(1)
                m = re.search(r'dev\s+(\S+)', line)
                if m: r['iface'] = m.group(1)
                m = re.search(r'src\s+([\d.]+)', line)
                if m: r['src_ip'] = m.group(1)
                routes.append(r)
        else:
            # Old-style route output
            for line in text.splitlines():
                if line.startswith('0.0.0.0') or re.match(r'\d+\.\d+', line):
                    parts = line.split()
                    if len(parts) >= 8:
                        routes.append({'dest': parts[0], 'gateway': parts[1],
                                       'mask': parts[2], 'iface': parts[7]})
        break
    return routes
```

**Diagnostic flags:**
- Multiple routes to the same destination subnet via different interfaces (same subnet, two interfaces) → IP conflict or misconfigured dual-IP — causes unpredictable routing
- `dead linkdown` on a route → interface has a route configured but no physical link — the route is dead weight
- No default route (`0.0.0.0`) → NAS has no gateway — cannot reach internet (DDNS, QuickConnect, email alerts all fail)
- Default route via a non-primary interface → traffic may be asymmetrically routed

---

#### `chrony.result` — NTP Time Synchronisation (DSM 7.x)

```python
def parse_chrony(zip_path: str) -> dict:
    result = {'available': False}
    path = 'dsm/result/chrony.result'
    with zipfile.ZipFile(zip_path, 'r') as zf:
        if path not in zf.namelist(): return result
        text = zf.read(path).decode('utf-8', errors='replace')
    result['available'] = True
    m = re.search(r'Stratum\s+:\s+(\d+)', text)
    result['stratum'] = int(m.group(1)) if m else None
    m = re.search(r'Leap status\s+:\s+(.+)', text)
    result['leap_status'] = m.group(1).strip() if m else None
    m = re.search(r'Ref time \(UTC\)\s+:\s+(.+)', text)
    result['ref_time'] = m.group(1).strip() if m else None
    m = re.search(r'System time\s+:\s+(.+)', text)
    result['system_time_offset'] = m.group(1).strip() if m else None
    # Count unreachable servers (Reach: 0)
    result['unreachable_servers'] = text.count(' 0     -     ')
    result['raw'] = text[:600]
    return result
```

**Diagnostic flags:**
- `Leap status: Not synchronised` → NAS clock is not synced to any NTP server — all log timestamps are unreliable for forensic analysis
- `Ref time: Thu Jan 01 00:00:00 1970` → NTP has never successfully synced since boot — clock is at epoch zero
- `Stratum: 0` → not connected to any time source
- All servers show `Reach: 0` → NTP packets are not reaching any server — DNS resolution or UDP/123 blocked
- `system_time_offset` > 1 second → clock drift is significant — events in logs may not correlate correctly across files

> **Critical note:** If chrony shows "Not synchronised", any timestamp-based cross-correlation (Level 2 multi-file analysis) between this debug file and others will be unreliable. This must be flagged prominently in the report.

---

#### `netstat.result` — Open Ports & Listening Services

```python
def parse_listening_ports(zip_path: str) -> list[dict]:
    """Extract only LISTEN-state sockets from netstat output."""
    ports = []
    path = 'dsm/result/netstat.result'
    with zipfile.ZipFile(zip_path, 'r') as zf:
        if path not in zf.namelist(): return ports
        text = zf.read(path).decode('utf-8', errors='replace')

    for line in text.splitlines():
        if 'LISTEN' in line:
            parts = line.split()
            if len(parts) >= 4:
                proto = parts[0]
                local = parts[3]
                # Split address:port
                addr_port = local.rsplit(':', 1)
                ports.append({
                    'proto': proto,
                    'address': addr_port[0] if len(addr_port) == 2 else local,
                    'port': addr_port[1] if len(addr_port) == 2 else '',
                    'raw': line.strip()
                })
    return ports
```

**Diagnostic flags:**
- Port 22 (SSH) listening on `0.0.0.0` → SSH exposed on all interfaces, not just management. Security risk if NAS is internet-accessible.
- Port 23 (Telnet) listening → unencrypted remote access enabled. Critical security issue.
- Port 80 listening when only HTTPS should be active → HTTP not redirected to HTTPS.
- Unexpected ports (anything above 1024 that is not a known DSM service) → potential malware or rogue package.
- Port 111 (RPC/portmapper) visible → NFS enabled. Flag if NFS is not an expected service.

---

#### `SMBService/etc/samba/smb.conf` — Samba Configuration

```python
def parse_smb_conf(zip_path: str) -> dict:
    result = {}
    with zipfile.ZipFile(zip_path, 'r') as zf:
        candidates = [n for n in zf.namelist() if n.endswith('samba/smb.conf')]
        if not candidates: return result
        text = zf.read(candidates[0]).decode('utf-8', errors='replace')

    m = re.search(r'workgroup\s*=\s*(.+)', text, re.I)
    if m: result['workgroup'] = m.group(1).strip()
    m = re.search(r'server string\s*=\s*(.+)', text, re.I)
    if m: result['server_string'] = m.group(1).strip()
    m = re.search(r'min protocol\s*=\s*(\S+)', text, re.I)
    if m: result['min_protocol'] = m.group(1)
    m = re.search(r'max protocol\s*=\s*(\S+)', text, re.I)
    if m: result['max_protocol'] = m.group(1)
    m = re.search(r'ntlm auth\s*=\s*(.+)', text, re.I)
    if m: result['ntlm_auth'] = m.group(1).strip()
    m = re.search(r'interfaces\s*=\s*(.+)', text, re.I)
    if m: result['bound_interfaces'] = m.group(1).strip()
    result['smb1_enabled'] = 'SMB1' in text.upper() or ('min protocol' in text.lower() and 'NT1' in text.upper())
    return result
```

**Diagnostic flags:**
- `min_protocol = NT1` or `SMB1` → SMB1 is enabled. Deprecated, insecure (WannaCry vector), and the cause of `SMB1 not permitted` connection failures seen in `.SYNOCONNDB`.
- `ntlm auth = ntlmv1-permitted` → NTLMv1 allowed — weak authentication protocol.
- `interfaces` not set → Samba binds on all interfaces including any internet-facing ones.

---

### 10.4 Confirmed Real Findings from Sample Files

These are actual issues detected from the provided sample archives — use these as validation test cases.

#### debug2.dat (DSM 6.x — 2022)

| Finding | Evidence | Severity |
|---|---|---|
| eth0 has physical layer errors | `ethtool_stats.eth0.result`: `rx_crc_errors: 4`, `rx_errors: 5` | MEDIUM — suspect cable or switch port |
| eth1 has active flow control | `ethtool_stats.eth1.result`: `rx_flow_control_xon: 293`, `rx_flow_control_xoff: 293` | MEDIUM — upstream switch congestion |
| Two interfaces on identical subnet | `ifconfig.result`: ovs_eth0=192.168.0.61, ovs_eth1=192.168.0.51, both on 192.168.0.0/24 | MEDIUM — asymmetric routing, duplicate routes in table |
| `syno_ovs_bonds` query fails | `ethtool.syno_ovs_bonds.result`: "Cannot get device settings: No such device" | LOW — bond interface not active |
| iptables not initialised at boot | `syno-network-check.service.log`: "can't initialize iptables table 'filter': iptables who?" | MEDIUM — firewall rules may not be applied |

#### debug_2070NTN152900.dat (DSM 7.x — 2026)

| Finding | Evidence | Severity |
|---|---|---|
| 3 of 4 ports disconnected | `ethtool.eth1/2/3.result`: "Link detected: no" / `iproute.result`: "dead linkdown" on eth1, eth2, eth3 | INFO — expected on single-homed rack unit |
| eth2 APIPA address (DHCP failed) | `ifconfig.result`: eth2 = 169.254.202.84. `SYNOSYSDB`: "IP address [169.254.202.84] assigned to DHCP client" | HIGH — DHCP failure, eth2 is non-functional |
| NTP not synchronised | `chrony.result`: Leap status: "Not synchronised", Ref time: "Thu Jan 01 00:00:00 1970", all 4 NTP servers unreachable | HIGH — log timestamps unreliable, alerting may be affected |
| eth1 has static IP on unusual /24 | `ifconfig.result`: eth1 = 1.1.1.2/24 — suspicious IP (1.1.1.x is Cloudflare's public DNS range) | MEDIUM — likely misrouted or intentional isolated network |

---

### 10.5 Packaging Format for Network Data

Network data is snapshot-based (point-in-time, not time-series like the SQLite logs). Use a structured JSON block per interface, plus a findings summary.

**Recommended output format for AI injection:**

```
## [NETWORK] Interface Configuration & Health Snapshot

### Interface Summary
| Interface | IP Address | Mask | MAC | Speed | Duplex | Link | Flags |
|---|---|---|---|---|---|---|---|
| eth0 | 192.168.0.126 | 255.255.255.0 | 00:11:32:D2:E9:8A | 1000Mb/s | Full | YES | UP BROADCAST RUNNING |
| eth1 | 1.1.1.2 | 255.255.255.0 | 00:11:32:D2:E9:8B | Unknown | Unknown | NO | UP BROADCAST |
| eth2 | 169.254.202.84 | 255.255.0.0 | 00:11:32:D2:E9:8C | Unknown | Unknown | NO | UP BROADCAST |
| eth3 | 192.168.2.51 | 255.255.255.0 | 00:11:32:D2:E9:8D | Unknown | Unknown | NO | UP BROADCAST |

### Hardware Error Counters (non-zero only)
  eth0: rx_crc_errors=4  rx_errors=5  rx_no_buffer_count=1
  eth1: rx_flow_control_xon=293  rx_flow_control_xoff=293

### Routing Table
  default → 192.168.0.1 via eth0
  1.1.1.0/24 via eth1  [LINKDOWN]
  169.254.0.0/16 via eth2  [LINKDOWN]
  192.168.0.0/24 via eth0
  192.168.2.0/24 via eth3  [LINKDOWN]

### NTP Status
  Status: NOT SYNCHRONISED
  Reference Time: Thu Jan 01 00:00:00 1970 (never synced)
  Stratum: 0 — no time source

### Automatic Findings
  [HIGH]   eth2 has APIPA address 169.254.202.84 — DHCP failure detected.
  [HIGH]   NTP is not synchronised — log timestamps are unreliable.
  [MEDIUM] eth0 has rx_crc_errors=4 — physical layer error on this port.
  [MEDIUM] eth1 flow control active (xon=293, xoff=293) — upstream congestion.
  [INFO]   eth1, eth2, eth3 show no link — only eth0 is active.
```

**Generate the `Automatic Findings` block server-side** (in Python, before AI injection). Pre-compute these rule-based checks so the AI receives structured findings, not raw data to reason over from scratch. This improves consistency and reduces hallucination.

---

### 10.6 Detection Rules Reference Table

| Check | Source File | Condition | Severity | Flag Text |
|---|---|---|---|---|
| APIPA address | ifconfig.result | IP matches `169.254.x.x` | HIGH | DHCP failure on {iface} — self-assigned link-local |
| Link down | ethtool.ethN | `Link detected: no` | INFO/HIGH | Port {iface} has no cable/link |
| CRC errors | ethtool_stats.ethN | `rx_crc_errors > 0` | MEDIUM | Physical layer errors on {iface} — check cable/switch |
| RX packet errors | ifconfig.result | `rx_errors > 0` | MEDIUM | Receive errors on {iface} |
| Flow control active | ethtool_stats.ethN | `rx_flow_control_xoff > 0` | MEDIUM | Switch sending pause frames — upstream congestion |
| NIC buffer overflow | ethtool_stats.ethN | `rx_missed_errors > 0` | HIGH | NIC dropping packets — system overload |
| Half-duplex on GbE | ethtool.ethN | `Duplex: Half` and speed ≥ 100Mb/s | HIGH | Duplex mismatch — severe throughput degradation |
| Duplicate subnet routes | route.result | Two routes to same destination via different ifaces | MEDIUM | Routing conflict — asymmetric traffic path |
| No default gateway | route.result | No `0.0.0.0` route | HIGH | No gateway — internet-dependent services will fail |
| NTP not synced | chrony.result | `Leap status: Not synchronised` | HIGH | Clock unsynchronised — timestamps unreliable |
| NTP at epoch | chrony.result | Ref time contains `1970` | HIGH | NTP has never successfully synced |
| iptables failure | syno-network-check.log | `can't initialize iptables` | MEDIUM | Firewall rules may not be applied |
| SMB1 enabled | smb.conf | `min protocol = NT1` | MEDIUM | Deprecated SMB1 protocol active — security risk |

---

*End of extended.md — v1.1*
