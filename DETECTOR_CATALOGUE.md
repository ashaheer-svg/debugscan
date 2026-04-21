# Synology Debug Analyzer — Detector Catalogue & Rule Engine Spec

**Status:** draft v0.1
**Audience:** backend engineers, parser authors, AI integration
**Goal:** turn the analyzer from a log viewer into a diagnostician with cited, cause-chained findings.

---

## 1. Architecture

Five layers, strict separation:

1. **Extraction layer** — unzips `.dat`, decompresses rotated `.xz`, exposes a virtual filesystem rooted at the bundle. Already present; needs `.xz` support and a safe SQLite opener.
2. **Parser layer** (`src/Parsers/*`) — produces *structured facts* per source file. Each parser returns a typed record (timestamps, metrics, events) plus `{file, line_range}` provenance. Parsers do NOT make judgements.
3. **Detector layer** — the subject of this document. Rules match over parser output + raw files. Each detector produces a `Finding` with a confidence score and evidence.
4. **Correlation layer** — walks the cause-chain graph to merge related findings into *incidents* and identify root causes vs. consequences.
5. **Presentation layer** — UI rendering, AI prompt construction with citations, report export (docx/pdf), alerting.

Rules are data, not code. They live in `config/detectors/*.yaml`, are versioned, and hot-reloadable.

---

## 2. Rule DSL (YAML)

### 2.1 Top-level schema

```yaml
id: <DOMAIN>-<SUBDOMAIN>-<SEQ>       # e.g. STG-RAID-001 — stable, never reused
name: <human-readable one-liner>
version: <integer>                   # bump on semantic change
domain: storage.raid | storage.fs | storage.disk | perf.io | perf.cpu | perf.mem |
        net.link | net.bond | net.proto | pwr.thermal | pwr.power |
        os.dsm | os.pkg | os.sched | sec.auth | sec.access |
        hw.fan | hw.psu | hw.mce | trend.longevity
severity: info | warn | high | critical
confidence_default: 0.0-1.0          # confidence when only primary signature matches

dsm_versions: ["<semver range>"]     # e.g. [">=6.0", "<8.0"]. null => all
tags: [raid, degraded, urgent]       # free-form, for filtering

sources:
  - path_hint: "**/kern.log"         # glob relative to bundle root
    required: true
    alternatives: ["**/messages"]    # fallback paths if primary missing
  - path_hint: "**/mdstat.result"
    required: false

signature_primary:                   # must match for the rule to fire
  type: regex | sqlite | sql | aggregate | cross_ref | absence
  target: <source path_hint or alias>
  pattern: <regex with named groups>  # for regex
  query:   <SQL>                      # for sqlite / sql
  params:  {<bind-parameters>}
  min_matches: 1                      # for regex/sqlite
  window: 3600                        # seconds — cluster matches within this window
  capture:                            # named groups to extract
    - ts
    - dev

signature_corroborating:             # zero or more; raise confidence if present
  - type: regex
    target: <source>
    pattern: <regex>
    weight: 0.0-1.0                  # additive confidence contribution
    relation: time_proximity         # optional: time_proximity | same_device | same_subsystem
    proximity_seconds: 600

cause_chain:
  upstream:  [<rule-id>, ...]        # possible root causes
  downstream:[<rule-id>, ...]        # likely consequences

citation_fields:                     # what evidence chips show in UI and AI prompt
  - name: timestamp
    from: primary.named_groups.ts
  - name: device
    from: primary.named_groups.dev
  - name: log_file
    value: kern.log
  - name: line_range
    from: primary.line_range

resolution:
  summary: <2-3 sentence operator guidance>
  steps: [ "<ordered actions>" ]
  kb_refs: [<KB-ID>, ...]            # links into KB layer
  runbook_id: <optional>

thresholds:                          # for trend/aggregate rules
  min_growth_per_day: 5
  baseline_window_days: 30
  alert_if_delta_gt: 10

suppression:
  suppressed_by: [<rule-id>]         # if downstream rule fires, silence this noisier one
  dedupe_key: "{{primary.named_groups.dev}}"
  cooldown_seconds: 86400

test_fixtures:
  - bundle: sample/debug1.dat
    expected: fire
    expected_confidence_min: 0.8
  - bundle: sample/debug_2070NTN152900.dat
    expected: no_fire
```

### 2.2 Confidence model

```
final_confidence = clamp(
  confidence_default
  + sum(weight of corroborating matches)
  - penalty_for_stale_evidence(age_days)
  , 0.0, 1.0
)
```

Corroborating signatures can specify a *relation* that must hold for the weight to count (e.g. `same_device`, `time_proximity`). Evidence older than `trend.baseline_window_days` is penalized.

### 2.3 Signature types

- `regex` — line-by-line match against a plaintext file, with named groups.
- `sqlite` — safe read-only query against one of the `.SYNO*DB` files. Schema introspection via `PRAGMA table_info` first; query is version-tolerant or skipped.
- `sql` — same, against the analyzer's own Postgres (for cross-bundle trend rules).
- `aggregate` — reduces parser output (e.g. SMART attribute delta, boot-phase duration) into a scalar and compares to thresholds.
- `cross_ref` — fires when two referenced rules both match with a time/device relation (enables composite rules without duplicating detectors).
- `absence` — fires when something that *should* be present is missing (e.g. no scrub in 180 days).

---

## 3. Cause-chain graph

The catalogue is a directed graph. Edges carry semantic:

- `upstream`: possible root cause — investigate it first
- `downstream`: likely consequence — silence or demote if upstream is confirmed

The correlation layer walks the graph from every fired finding until it finds a fired upstream; that upstream becomes the *incident root*, everything else is a *consequence*. This is how you prevent alert storms and give the user one clear answer per actual problem.

Example chain for the worked example from the previous report:

```
STG-DISK-003 (SMART pending sectors rising)
   ↓
STG-DISK-002 (SATA link resets on that device)
   ↓
STG-RAID-001 (RAID member kicked)
   ↓
STG-FS-001 (Btrfs csum errors)
   ↓
STG-FS-004 (Btrfs forced readonly)
   ↓
PERF-IO-001 (High iowait, app-level slowness)
```

Only `STG-DISK-003` is surfaced as "root cause"; the others are shown under it collapsed as "consequences", each with their own evidence chips.

---

## 4. Output contract (Finding / Incident)

### 4.1 Finding

```json
{
  "rule_id": "STG-RAID-001",
  "rule_version": 1,
  "severity": "critical",
  "confidence": 0.92,
  "title": "RAID array md2 degraded — member sdc4 kicked",
  "summary": "Array running on parity only; second failure risks data loss.",
  "citations": [
    {"file": "dsm/var/log/kern.log", "line_range": [34212, 34215],
     "timestamp": "2026-04-02T15:27:13Z", "snippet": "..."},
    {"file": "dsm/var/log/synolog/.SYNOSYSDB", "table": "log",
     "row_ids": [44211, 44212], "timestamp": "2026-04-02T15:27:41Z"}
  ],
  "entities": {"device": "sdc", "array": "md2", "volume": "volume1"},
  "cause_chain": {
    "upstream_refs": ["STG-DISK-003"],
    "downstream_refs": ["STG-FS-001", "STG-FS-004"]
  },
  "resolution_ref": "KB-RAID-DEGRADE"
}
```

### 4.2 Incident (post-correlation)

```json
{
  "incident_id": "inc_01HXY...",
  "root_cause": <Finding>,
  "consequences": [<Finding>, ...],
  "impact_summary": "...",
  "priority": "P1",
  "timeline": [ {"ts": "...", "event": "..."} ]
}
```

The AI layer receives *Incidents*, not raw findings — the cause-chain is already resolved. Its job is narrative explanation, not root-cause inference. This is what stops hallucinations.

---

## 5. Runtime behaviour

Per bundle scan:

1. Extraction → virtual FS.
2. Parsers run in parallel, produce fact tables keyed by source.
3. Detector engine loads rule catalogue, filters by DSM compatibility.
4. Rules execute in parallel where source sets are disjoint; dependent rules (cross_ref) run in a second pass.
5. Findings collected, deduped via `suppression.dedupe_key`.
6. Correlation layer walks cause-chain graph, produces incidents.
7. Output serialized to DB + passed to AI + rendered in UI.

Each run records: rules evaluated, rules skipped (and why), rules fired, correlation decisions. Fully auditable.

---

## 6. The catalogue

Format below is abbreviated — one YAML block per rule showing the distinctive parts. The full YAML lives in `config/detectors/`. Regex patterns are illustrative and will need refinement against real fixtures.

### 6.1 Storage — RAID (`STG-RAID-*`)

```yaml
id: STG-RAID-001
name: RAID member disk kicked (failure)
domain: storage.raid
severity: critical
sources: [kern.log, mdstat.result, scemd.log]
signature_primary:
  type: regex
  target: kern.log
  pattern: 'md/raid.*?Disk failure on (?P<dev>sd[a-z]+\d?), disabling device'
  capture: [dev]
signature_corroborating:
  - type: regex
    target: mdstat.result
    pattern: '\[U_*_U*\]'
    weight: 0.3
  - type: sqlite
    target: .SYNOSYSDB
    query: "SELECT COUNT(*) FROM log WHERE event LIKE '%disk%' AND time >= :since"
    weight: 0.2
confidence_default: 0.85
cause_chain:
  upstream: [STG-DISK-001, STG-DISK-002, STG-DISK-003, PWR-PSU-001, HW-BACKPLANE-001]
  downstream: [STG-RAID-002, STG-FS-001, STG-FS-004, PERF-IO-001]
resolution:
  summary: "Array degraded. Confirm drive identity by serial (not slot). Replace off-hours. Scrub after rebuild."
```

```yaml
id: STG-RAID-002
name: RAID rebuild in progress or stalled
signature_primary:
  type: regex
  target: kern.log
  pattern: 'md: (sync|resync) of RAID array (?P<array>md\d+)'
signature_corroborating:
  - type: aggregate
    target: mdstat.result
    expression: parse_sync_speed(content)
    threshold: { lt: 10485760 }    # <10 MB/s
    weight: 0.3
    meaning: "stalled or very slow rebuild"
cause_chain:
  upstream: [STG-DISK-002, PWR-THERMAL-002, STG-DISK-SMR]
  downstream: [STG-FS-001, PERF-IO-001]
```

```yaml
id: STG-RAID-003
name: RAID member flapping (intermittent)
signature_primary:
  type: regex
  target: kern.log
  pattern: 'md:\s+(bind|unbind)<(?P<dev>sd[a-z]+\d?)>'
  min_matches: 4
  window: 3600
confidence_default: 0.9
cause_chain:
  upstream: [STG-DISK-002, STG-DISK-005, HW-CABLE-001]
  downstream: [STG-RAID-001, STG-FS-001]
```

```yaml
id: STG-RAID-004
name: RAID URE during rebuild (double-fault risk)
signature_primary:
  type: cross_ref
  a: STG-RAID-002       # rebuilding
  b: STG-DISK-006       # read error on *another* member during rebuild
  relation: time_during
severity: critical
cause_chain:
  downstream: [STG-FS-001, STG-FS-004]
```

```yaml
id: STG-RAID-005
name: SHR expansion stuck / reshape paused
signature_primary:
  type: regex
  target: kern.log
  pattern: 'md: reshape.*(paused|stopped)'
  min_matches: 1
cause_chain:
  upstream: [STG-DISK-003, STG-FS-005]
```

### 6.2 Storage — Filesystem (`STG-FS-*`)

```yaml
id: STG-FS-001
name: Btrfs checksum error
signature_primary:
  type: regex
  target: kern.log
  pattern: 'BTRFS warning \(device (?P<vol>\w+)\):.*?(csum|checksum) (failed|mismatch)'
  capture: [vol]
  min_matches: 1
signature_corroborating:
  - type: regex
    target: datascrubbing.log
    pattern: 'uncorrectable_errors:\s*[1-9]\d*'
    weight: 0.3
cause_chain:
  upstream: [STG-RAID-001, STG-RAID-003, STG-DISK-003, STG-DISK-006]
  downstream: [STG-FS-004]
```

```yaml
id: STG-FS-002
name: Btrfs parent transid verify failed
signature_primary:
  type: regex
  target: kern.log
  pattern: 'BTRFS error.*parent transid verify failed on \d+ wanted \d+ found \d+'
severity: critical
cause_chain:
  upstream: [STG-RAID-001, STG-FS-001, PWR-POWER-001]
```

```yaml
id: STG-FS-003
name: Btrfs free-space cache invalid after unclean shutdown
signature_primary:
  type: regex
  target: kern.log
  pattern: 'BTRFS warning.*free space inode generation.*invalid'
cause_chain:
  upstream: [PWR-POWER-001]
```

```yaml
id: STG-FS-004
name: Filesystem forced read-only
signature_primary:
  type: regex
  target: kern.log
  pattern: 'BTRFS:? error.*forced readonly|Remounting filesystem read-only'
severity: critical
cause_chain:
  upstream: [STG-FS-001, STG-FS-002, STG-RAID-001]
  downstream: [OS-PKG-003, OS-PKG-DB-001]
```

```yaml
id: STG-FS-005
name: ENOSPC despite free space (Btrfs metadata imbalance)
signature_primary:
  type: regex
  target: kern.log
  pattern: 'BTRFS info.*no space left'
signature_corroborating:
  - type: aggregate
    target: btrfs/*.result
    expression: metadata_used_pct(content)
    threshold: { gt: 0.9 }
    weight: 0.4
resolution:
  summary: "Run btrfs balance with metadata filter during low-load window."
```

```yaml
id: STG-FS-006
name: Inode exhaustion (ext4)
signature_primary:
  type: aggregate
  target: df.result
  expression: max_inode_pct(content)
  threshold: { gt: 0.95 }
```

```yaml
id: STG-FS-007
name: md0 (system partition) filling
signature_primary:
  type: aggregate
  target: df.result
  expression: root_used_pct(content)
  threshold: { gt: 0.90 }
severity: high
cause_chain:
  downstream: [OS-PKG-001, OS-PKG-002, OS-LOG-001]
```

```yaml
id: STG-FS-008
name: Snapshot explosion (per-share)
signature_primary:
  type: sqlite
  target: .SYNOSYSDB
  query: "SELECT share, COUNT(*) c FROM snapshots GROUP BY share HAVING c > 1000"
severity: warn
```

```yaml
id: STG-FS-009
name: No recent Btrfs scrub (integrity stale)
signature_primary:
  type: absence
  target: datascrubbing.log
  criterion: "no successful scrub in last 90 days"
severity: warn
```

### 6.3 Storage — Disks / SMART (`STG-DISK-*`)

```yaml
id: STG-DISK-001
name: SMART reallocated sectors climbing
signature_primary:
  type: aggregate
  target: .SYNODISKHEALTHDB
  expression: smart_delta("Reallocated_Sector_Ct", window_days=30)
  threshold: { gt: 5 }
severity: warn
thresholds:
  min_growth_per_day: 0.2
  baseline_window_days: 30
cause_chain:
  downstream: [STG-DISK-006, STG-RAID-001]
```

```yaml
id: STG-DISK-002
name: SATA link resets (cable / port / drive)
signature_primary:
  type: regex
  target: kern.log
  pattern: 'ata(?P<port>\d+).*(hard resetting link|exception Emask.*frozen)'
  capture: [port]
  min_matches: 3
  window: 3600
```

```yaml
id: STG-DISK-003
name: SMART pending sectors non-zero and sticky
signature_primary:
  type: aggregate
  target: .SYNODISKHEALTHDB
  expression: smart_last("Current_Pending_Sector")
  threshold: { gt: 0 }
signature_corroborating:
  - type: aggregate
    target: .SYNODISKHEALTHDB
    expression: smart_trend("Current_Pending_Sector", window_days=14)
    threshold: { eq: "not_clearing" }
    weight: 0.3
```

```yaml
id: STG-DISK-004
name: UDMA CRC errors (cable / port fault, not drive)
signature_primary:
  type: aggregate
  target: .SYNODISKHEALTHDB
  expression: smart_delta("UDMA_CRC_Error_Count", window_days=30)
  threshold: { gt: 0 }
resolution:
  summary: "Reseat/replace cable. Swap drive to different bay; if counter pauses, slot/cable at fault."
```

```yaml
id: STG-DISK-005
name: Disk remove/insert events (flap)
signature_primary:
  type: regex
  target: scemd.log
  pattern: 'disk\s+(removed|inserted).*?(slot|bay)\s*(?P<slot>\d+)'
  min_matches: 4
  window: 86400
```

```yaml
id: STG-DISK-006
name: Kernel read error on specific device
signature_primary:
  type: regex
  target: kern.log
  pattern: '(read error|I/O error).*?(?P<dev>sd[a-z]+\d?)'
  min_matches: 2
  window: 3600
```

```yaml
id: STG-DISK-007
name: SMR drive detected in RAID array
signature_primary:
  type: aggregate
  target: hardware_parser.output
  expression: contains_smr_model(disks)
severity: warn
resolution:
  summary: "SMR drives should not be in RAID arrays. Plan replacement with CMR."
```

```yaml
id: STG-DISK-008
name: Self-test failure (short or extended)
signature_primary:
  type: sqlite
  target: .SYNODISKTESTDB
  query: "SELECT disk, test_type, result, time FROM disk_test WHERE result LIKE '%fail%' AND time >= :since"
```

```yaml
id: STG-DISK-009
name: Excessive head-load cycles (mechanical wear accelerator)
signature_primary:
  type: aggregate
  target: .SYNODISKHEALTHDB
  expression: smart_last("Load_Cycle_Count")
  threshold: { gt: 500000 }
severity: warn
cause_chain:
  upstream: [PERF-IO-006]    # spin-down / wake-up churn
```

```yaml
id: STG-DISK-010
name: SSD wear approaching end-of-life
signature_primary:
  type: aggregate
  target: .SYNODISKHEALTHDB
  expression: smart_last("Percentage_Used") or smart_last("Wear_Leveling_Count")
  threshold: { gt: 90 }
severity: high
```

### 6.4 Performance (`PERF-*`)

```yaml
id: PERF-IO-001
name: High IO wait
signature_primary:
  type: aggregate
  target: vmstat.result
  expression: avg_iowait_pct(content)
  threshold: { gt: 20 }
cause_chain:
  upstream: [STG-RAID-001, STG-RAID-002, STG-DISK-002, PERF-IO-002, PERF-IO-003, PERF-IO-004]
```

```yaml
id: PERF-IO-002
name: Runaway indexer (SynoSysIndex / Photos / Media)
signature_primary:
  type: regex
  target: top.result
  pattern: '^\s*\d+\s+.*\s+(synomkthumbd|synosysindex|synophoto-bin)\s'
  min_matches: 3
```

```yaml
id: PERF-IO-003
name: Antivirus scan loop
signature_primary:
  type: regex
  target: "**/av*.log"
  pattern: 'scan (started|completed).*'
  min_matches: 10
  window: 86400
```

```yaml
id: PERF-IO-004
name: Hyper Backup active during business hours
signature_primary:
  type: regex
  target: synobackup.log
  pattern: 'backup.*started at (?P<ts>\d{4}-\d{2}-\d{2} (09|10|11|12|13|14|15|16|17):)'
severity: info
```

```yaml
id: PERF-IO-005
name: Long D-state process chain
signature_primary:
  type: aggregate
  target: top.result
  expression: count_d_state(content)
  threshold: { gt: 10 }
cause_chain:
  upstream: [STG-DISK-002, STG-RAID-001]
```

```yaml
id: PERF-IO-006
name: Disk spin-down / spin-up thrash
signature_primary:
  type: aggregate
  target: scemd.log
  expression: count_power_events("disk", window_days=7)
  threshold: { gt: 200 }
cause_chain:
  downstream: [STG-DISK-009]
```

```yaml
id: PERF-CPU-001
name: Sustained CPU saturation
signature_primary:
  type: aggregate
  target: vmstat.result
  expression: avg_cpu_idle_pct(content)
  threshold: { lt: 10 }
```

```yaml
id: PERF-CPU-002
name: Crypto-bound CPU (SMB signing, encrypted shares, no AES-NI)
signature_primary:
  type: cross_ref
  a: PERF-CPU-001
  b: HW-CPU-NO-AESNI
  relation: always
```

### 6.5 Memory (`PERF-MEM-*`)

```yaml
id: PERF-MEM-001
name: OOM killer invoked
signature_primary:
  type: regex
  target: kern.log
  pattern: 'Out of memory: Killed process \d+ \((?P<proc>[^)]+)\)'
  capture: [proc]
severity: high
```

```yaml
id: PERF-MEM-002
name: Heavy swap activity
signature_primary:
  type: aggregate
  target: vmstat.result
  expression: avg_swap_io(content)
  threshold: { gt: 1000 }   # KB/s
```

```yaml
id: PERF-MEM-003
name: ECC / memory MCE events
signature_primary:
  type: regex
  target: mcelog.log
  pattern: '(Memory controller|Hardware error|MCE).*?(ecc|corrected|uncorrected)'
severity: high
cause_chain:
  downstream: [PERF-MEM-001, STG-FS-002]
```

```yaml
id: PERF-MEM-004
name: Package memory leak (cross-bundle)
signature_primary:
  type: sql
  target: analyzer_db
  query: |
    SELECT package, pct_growth FROM mem_trend
    WHERE device_id=:dev AND pct_growth > 0.5 AND window='30d'
severity: warn
```

### 6.6 Network — Link & physical (`NET-LINK-*`)

```yaml
id: NET-LINK-001
name: Link flap (physical)
signature_primary:
  type: regex
  target: kern.log
  pattern: '(?P<iface>eth\d+|enp\d+s\d+):\s+Link is (Down|Up)'
  capture: [iface]
  min_matches: 4
  window: 3600
severity: high
cause_chain:
  upstream: [NET-LINK-002, NET-LINK-003, HW-CABLE-001, PWR-PSU-001]
  downstream: [NET-BOND-001, NET-PROTO-ISCSI-001, NET-PROTO-SMB-001]
```

```yaml
id: NET-LINK-002
name: NIC CRC / frame errors climbing
signature_primary:
  type: aggregate
  target: ethtool.result
  expression: delta("rx_crc_errors") + delta("rx_frame_errors")
  threshold: { gt: 100 }
```

```yaml
id: NET-LINK-003
name: Speed negotiation mismatch (link came up slower than expected)
signature_primary:
  type: regex
  target: kern.log
  pattern: 'Link is Up (?P<speed>\d+)\s*Mbps'
  capture: [speed]
signature_corroborating:
  - type: aggregate
    target: ethtool.result
    expression: link_speed_below_advertised(content)
    weight: 0.4
```

```yaml
id: NET-LINK-004
name: NETDEV watchdog / NIC driver hang
signature_primary:
  type: regex
  target: kern.log
  pattern: 'NETDEV WATCHDOG:.*transmit queue.*timed out|(r8125|r8168|igb|ixgbe).*reset adapter'
severity: high
```

```yaml
id: NET-LINK-005
name: SFP+/transceiver issue
signature_primary:
  type: regex
  target: kern.log
  pattern: '(SFP|transceiver).*(module|unsupported|temperature)'
```

### 6.7 Network — Bonding (`NET-BOND-*`)

```yaml
id: NET-BOND-001
name: Bond member down
signature_primary:
  type: regex
  target: kern.log
  pattern: 'bond\d+:.*link status definitely down for interface (?P<iface>\w+)'
```

```yaml
id: NET-BOND-002
name: LACP partner unresponsive
signature_primary:
  type: regex
  target: kern.log
  pattern: 'bond\d+:.*No 802\.3ad response from the link partner'
severity: high
```

```yaml
id: NET-BOND-003
name: Bond running with only one active interface (silent half-speed)
signature_primary:
  type: regex
  target: kern.log
  pattern: 'bond\d+:.*now running without any active interface|with 1 active interface'
```

### 6.8 Network — Protocol (`NET-PROTO-*`)

```yaml
id: NET-PROTO-SMB-001
name: SMB session leak
signature_primary:
  type: aggregate
  target: smbstatus.result
  expression: session_count(content)
  threshold: { gt: 2000 }
```

```yaml
id: NET-PROTO-SMB-002
name: SMB signing forcing perf hit (no CPU AES-NI)
signature_primary:
  type: cross_ref
  a: smb_signing_required    # derived from smb.conf
  b: HW-CPU-NO-AESNI
severity: warn
```

```yaml
id: NET-PROTO-ISCSI-001
name: iSCSI session drops
signature_primary:
  type: regex
  target: iscsi.log
  pattern: '(connection.*closed|session.*reinstated|target.*disconnect)'
  min_matches: 3
  window: 3600
cause_chain:
  upstream: [NET-LINK-001, NET-BOND-001]
```

```yaml
id: NET-PROTO-DNS-001
name: DNS resolution failures
signature_primary:
  type: regex
  target: messages
  pattern: 'Temporary failure in name resolution'
  min_matches: 5
  window: 3600
cause_chain:
  downstream: [OS-PKG-001, SEC-AUTH-AD-001]
```

```yaml
id: NET-PROTO-NTP-001
name: NTP large time step
signature_primary:
  type: regex
  target: synosystemd.log
  pattern: 'ntpd.*time (reset|stepped) (?P<delta>-?\d+\.?\d*)\s*s'
  capture: [delta]
  threshold_on_capture:
    field: delta
    abs_gt: 60
cause_chain:
  downstream: [SEC-AUTH-AD-001, SEC-CERT-001]
```

```yaml
id: NET-PROTO-CERT-001
name: TLS certificate expired or about to expire
signature_primary:
  type: regex
  target: nginx/error.log
  pattern: '(certificate has expired|SSL_do_handshake.*expired)'
signature_corroborating:
  - type: aggregate
    target: certs_parser.output
    expression: min_days_to_expiry(content)
    threshold: { lt: 7 }
    weight: 0.4
```

```yaml
id: NET-PROTO-QC-001
name: QuickConnect relay flapping
signature_primary:
  type: regex
  target: synorelayd.log
  pattern: '(disconnect|reconnect|relay.*down)'
  min_matches: 10
  window: 86400
```

### 6.9 Power & thermal (`PWR-*`, `HW-*`)

```yaml
id: PWR-THERMAL-001
name: Fan failure or stuck RPM
signature_primary:
  type: regex
  target: scemd.log
  pattern: 'fan.*(stopped|failure|rpm\s*[:=]\s*0)'
severity: high
cause_chain:
  downstream: [PWR-THERMAL-002]
```

```yaml
id: PWR-THERMAL-002
name: Thermal throttling
signature_primary:
  type: regex
  target: kern.log
  pattern: '(thermal.*throttl|CPU\d+.*temperature above threshold)'
cause_chain:
  upstream: [PWR-THERMAL-001]
  downstream: [PERF-CPU-001, STG-RAID-002]
```

```yaml
id: PWR-PSU-001
name: PSU brownout (simultaneous multi-drive SATA reset)
signature_primary:
  type: aggregate
  target: kern.log
  expression: count_simultaneous_sata_resets(time_window=30s)
  threshold: { gt: 3 }
severity: critical
cause_chain:
  downstream: [STG-RAID-001, STG-DISK-002, PWR-POWER-001]
```

```yaml
id: PWR-POWER-001
name: Unexpected / improper shutdown
signature_primary:
  type: absence
  target: lastimproper.log
  criterion: "file present and non-empty"
cause_chain:
  downstream: [STG-FS-002, STG-FS-003]
```

```yaml
id: PWR-UPS-001
name: UPS battery degraded or disconnected
signature_primary:
  type: regex
  target: messages
  pattern: '(ups.*battery.*(low|failed)|NOCOMM|ups communication lost)'
```

```yaml
id: HW-BACKPLANE-001
name: Expansion unit (DX/RX) cable intermittent
signature_primary:
  type: regex
  target: kern.log
  pattern: '(expansion|eunit).*(link|cable).*(down|error)'
  min_matches: 2
  window: 3600
```

```yaml
id: HW-MCE-001
name: CPU machine check events
signature_primary:
  type: regex
  target: mcelog.log
  pattern: 'Hardware event.*(CPU|Processor)'
severity: high
```

### 6.10 OS / DSM / packages (`OS-*`)

```yaml
id: OS-UPGRADE-001
name: DSM upgrade failed mid-install
signature_primary:
  type: regex
  target: synoinstall.log
  pattern: '(install failed|error code\s*[1-9])'
severity: critical
```

```yaml
id: OS-PKG-001
name: Package post-install script failed
signature_primary:
  type: regex
  target: synopkg.log
  pattern: 'post-?inst.*exit (status|code)\s*[1-9]|install.*aborted'
```

```yaml
id: OS-PKG-002
name: Package repeatedly crashing
signature_primary:
  type: regex
  target: synosystemd.log
  pattern: 'pkg-(?P<pkg>\w+)\.service.*(failed|core-dumped)'
  min_matches: 3
  window: 86400
```

```yaml
id: OS-PKG-DB-001
name: PostgreSQL crash (DSM backbone)
signature_primary:
  type: regex
  target: postgresql.log
  pattern: '(PANIC|FATAL.*could not|server process.*terminated abnormally)'
severity: high
cause_chain:
  downstream: [OS-PKG-SVC-CALENDAR, OS-PKG-SVC-DRIVE, OS-PKG-SVC-PHOTOS]
```

```yaml
id: OS-SCHED-001
name: Scheduled task silently failing
signature_primary:
  type: regex
  target: synocrond-execute.log
  pattern: 'task\s+(?P<task>\S+).*exit (code|status)\s*[1-9]'
  min_matches: 3
  window: 604800
```

```yaml
id: OS-LOG-001
name: logrotate broken (stale status)
signature_primary:
  type: aggregate
  target: logrotate.status
  expression: max_age_days(content)
  threshold: { gt: 14 }
cause_chain:
  downstream: [STG-FS-007]
```

```yaml
id: OS-SQLITE-WAL-001
name: SYNO*DB-wal bloat
signature_primary:
  type: aggregate
  target: synolog_dir_listing
  expression: max_wal_size_mb()
  threshold: { gt: 200 }
```

```yaml
id: OS-APPARMOR-001
name: AppArmor DENIED events
signature_primary:
  type: regex
  target: apparmor.log
  pattern: 'apparmor="DENIED".*profile="(?P<profile>[^"]+)"'
  min_matches: 10
```

```yaml
id: OS-DOCKER-001
name: Container Manager / Docker storage driver errors
signature_primary:
  type: regex
  target: messages
  pattern: 'docker.*(layer|overlay2|graphdriver).*(error|fail)'
```

### 6.11 Security / auth (`SEC-*`)

```yaml
id: SEC-AUTH-BRUTE-001
name: SSH brute-force attempt cluster
signature_primary:
  type: regex
  target: auth.log
  pattern: 'Failed password for (invalid user )?(?P<user>\S+) from (?P<ip>\S+)'
  capture: [user, ip]
  min_matches: 50
  window: 3600
signature_corroborating:
  - type: sqlite
    target: .SYNOCONNDB
    query: "SELECT COUNT(*) FROM log_conn WHERE result='fail' AND ip=:ip AND time >= :since"
    weight: 0.3
```

```yaml
id: SEC-AUTH-NOVEL-IP-001
name: Successful admin login from novel source IP
signature_primary:
  type: sqlite
  target: .SYNOCONNDB
  query: |
    SELECT user, ip, time FROM log_conn
    WHERE result='success' AND user IN (SELECT name FROM admin_users)
      AND ip NOT IN (SELECT DISTINCT ip FROM log_conn WHERE time < :baseline_cutoff)
severity: high
```

```yaml
id: SEC-AUTH-AD-001
name: AD / Kerberos auth failures
signature_primary:
  type: regex
  target: synosystemd.log
  pattern: '(winbindd|sssd|kerberos).*(KRB_AP_ERR|CLOCK_SKEW|failed)'
cause_chain:
  upstream: [NET-PROTO-NTP-001, NET-PROTO-DNS-001]
```

```yaml
id: SEC-CERT-001
name: Certificate auto-renewal failing (Let's Encrypt)
signature_primary:
  type: regex
  target: synoscheduler.log
  pattern: 'letsencrypt.*(fail|error)'
  min_matches: 2
```

```yaml
id: SEC-SHARE-DRIFT-001
name: Share ACL drift after AD resync
signature_primary:
  type: sqlite
  target: .SYNOACCOUNTDB
  query: "SELECT share, old_acl_hash, new_acl_hash FROM share_audit WHERE delta_time < 86400"
severity: warn
```

### 6.12 Longevity / trend (`TREND-*`)

```yaml
id: TREND-BOOT-001
name: Boot time regression
signature_primary:
  type: sql
  target: analyzer_db
  query: |
    SELECT current_ms, baseline_ms FROM boot_phase
    WHERE device_id=:dev AND current_ms > baseline_ms * 1.25
```

```yaml
id: TREND-SMART-001
name: Per-drive SMART trend (cross-bundle)
signature_primary:
  type: sql
  target: analyzer_db
  query: |
    SELECT serial, attr, slope_per_day FROM smart_trend
    WHERE device_id=:dev AND slope_per_day > threshold_for_attr(attr)
```

```yaml
id: TREND-PKG-DRIFT-001
name: Package-version drift fleet-wide
signature_primary:
  type: sql
  target: analyzer_db
  query: |
    SELECT package, COUNT(DISTINCT version) v FROM package_state
    WHERE tenant_id=:t GROUP BY package HAVING v > 2
severity: info
```

```yaml
id: TREND-THERMAL-001
name: Disk temp creep per serial
signature_primary:
  type: sql
  target: analyzer_db
  query: |
    SELECT serial, delta_c_per_month FROM temp_trend
    WHERE device_id=:dev AND delta_c_per_month > 1.5
```

---

## 7. Integration with AI layer

The AI receives *incidents* (post-correlation), not raw findings. Prompt template:

```
You are a Synology NAS troubleshooting engineer. The following incidents have been
detected by a rule engine from a debug bundle dated {{bundle_date}} for device
{{device_model}} running DSM {{dsm_version}}.

For each incident, write:
- 1-paragraph plain-English explanation of what is happening and why it matters
- Recommended next action in priority order (do not invent new causes not in the input)

INCIDENTS:
{% for inc in incidents %}
INCIDENT {{inc.id}} (priority {{inc.priority}})
Root cause: {{inc.root_cause.title}} (rule {{inc.root_cause.rule_id}}, confidence {{inc.root_cause.confidence}})
Evidence:
{% for c in inc.root_cause.citations %}  - {{c.file}}:{{c.line_range}} @ {{c.timestamp}} — "{{c.snippet}}"
{% endfor %}
Consequences:
{% for con in inc.consequences %}  - {{con.title}} ({{con.rule_id}})
{% endfor %}
{% endfor %}

Return strict JSON: {"narratives": [{"incident_id": "...", "explanation": "...", "actions": ["..."]}]}

HARD RULES:
- Do not introduce facts not in the input.
- Every claim in "explanation" must be traceable to a rule_id in the input.
- If evidence is weak (confidence < 0.5), say so explicitly.
```

Redaction happens *before* this template is filled: serials, MACs, IPs, hostnames, emails replaced with stable aliases (`DISK_A`, `NAS_01`, `USER_42`) so the AI can reason without exfiltrating tenant PII.

---

## 8. UI / rendering

- **Triage view** — incidents grouped by priority, each collapsed. Root cause shown; consequences hidden under "n related findings" pill.
- **Evidence chips** — each citation renders as a clickable chip: file path + timestamp + "view in context" opens a log viewer with that line range highlighted.
- **Cause-chain diagram** — small DAG beside each incident showing the chain; nodes are findings, edges are cause_chain refs, colours = severity.
- **"Why not this?" affordance** — for suppressed or low-confidence findings, let the user expand to see why the engine didn't surface them.
- **Rule provenance** — every finding shows the rule that produced it, with a "view rule" link. Trust is built by transparency.

---

## 9. Testing strategy

- **Unit** — each rule tested against synthetic log snippets (`tests/detectors/<rule_id>/*.txt` + expected JSON).
- **Fixture** — each `sample/*.dat` has an expected `findings.json`; CI diffs actual vs expected and fails on drift.
- **Property-based** — random timestamp perturbations, random path variations across DSM versions, ensuring rules still fire correctly.
- **Negative** — explicit "clean bundle" fixtures assert *no* rules fire.
- **Regression corpus** — every customer bundle that triggered a false positive or false negative becomes a permanent fixture.

---

## 10. Versioning & rollout

- Rules are versioned individually; catalogue is versioned as a whole (semver).
- Each `Finding` records `rule_version` so historical reports stay meaningful.
- New rules ship in *shadow mode* first (produce findings, hidden from UI, compared to AI output) for N bundles before going live.
- Breaking schema changes require a migration in `findings` / `incidents` tables.
- Rule authors submit PRs against `config/detectors/` with required test fixtures.

---

## 11. Build order

Phase 0 — foundation: `.xz` decompression, safe SQLite reader, rule engine skeleton, YAML loader, test harness. ~1 week.

Phase 1 — high-leverage rules: RAID (5), Disk/SMART (10), FS (5), Link (3), Power (3), DSM upgrade/package (4), Auth brute-force (1). ~30 rules, most value. ~1.5 weeks.

Phase 2 — correlation + AI incidents with citations. ~1 week.

Phase 3 — full catalogue (80+ rules), trend rules requiring multi-bundle history, fleet mode. ~2–3 weeks.

Phase 4 — UI polish, evidence chips, cause-chain diagram, report export. ~1–2 weeks.

Total to a credible first product: ~7–8 weeks of focused work for one engineer, faster with two.

---

## 12. Open questions / decisions needed

- Rule DSL: YAML (chosen) vs JSON vs a Python/PHP internal DSL? YAML wins for rule-author ergonomics and diff-friendliness.
- Trend rules need multi-bundle storage — reuse existing Postgres `debug_files` / `scan_jobs` tables or a dedicated `metrics_history` schema? Recommend dedicated.
- KB content management — inline in rule YAML vs separate Markdown with `kb_refs`? Separate, so non-engineers can edit.
- Confidence calibration — initial weights are educated guesses; need a labelled corpus to tune. Start with hand-curated 20 bundles.
- How to handle bundles from DSM versions not in `dsm_versions` — skip or fire at low confidence? Recommend skip + record `not_evaluated`.
