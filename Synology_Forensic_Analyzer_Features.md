# Synology Forensic Analyzer — Feature Specifications (v3.0)

This document provides a comprehensive breakdown of the application features, technical capabilities, and forensic integrity standards of the AI DebugScan platform. It is designed for use by web developers as the definitive "Source of Truth" for creating the product website.

---

## 1. Core Vision & Value Proposition
**The Ultimate Synology Diagnostic Companion.**  
AI DebugScan v3 is a high-integrity, multi-tenant platform designed to transform raw Synology `debug.dat` archives into actionable, human-readable forensic insights. It combines deep heuristic parsing with state-of-the-art AI to identify hardware failures, volume degradation, and security vulnerabilities before they become catastrophic.

### Why It Matters:
- **Zero Configuration**: Simply upload the debug file. No agent installation required.
- **Evidence-Based Results**: Every finding is backed by cited log entries from the raw data.
- **Forensic Transparency**: A complete, immutable history of all actions and financial transactions.

---

## 2. Multi-Tier Analysis Engine
The platform offers three distinct levels of insight to balance speed with depth.

### A. Instant Hardware Inventory (Standard)
*Automatic upon upload.*  
- **Identity**: Model name, Serial Number, and current DSM version.
- **Resources**: CPU architecture, RAM capacity, and thermal status.
- **Storage Topology**: Full mapping of drive bays, physical disk health, and RAID configuration.

### B. Level 1 Scan: Rapid Insight
*Uses 1 Scan Token. Optimized for quick health checks.*  
- **AI Focus**: Identifies surface-level hardware warnings and obvious system errors.
- **Speed**: Results generated in under 120 seconds.
- **Best For**: Routine maintenance and high-level triage.

### C. Level 2 Scan: Deep Forensic Audit
*Uses 2 Scan Tokens. The ultimate diagnostic tool.*  
- **AI Focus**: Deep multi-file cross-referencing. Analyzes years of log history to find intermittent failures, throughput bottlenecks, and "silent" corruption indicators.
- **Speed**: Comprehensive report generated in 3–5 minutes.
- **Best For**: Disaster recovery, legal compliance, and critical troubleshooting.

---

## 3. The 16+ Deep-Dive Parsing Modules
Our proprietary parsing engine analyzes every facet of the Synology ecosystem:

| Module | Forensic Focus |
| :--- | :--- |
| **Btrfs Integrity** | Scrub history, checksum errors, and metadata consistency. |
| **RAID & LVM** | `mdstat` verification, physical volume alignment, and logical mapping. |
| **Disk Telemetry** | S.M.A.R.T. health, I/O latency profiling, and bad sector tracking. |
| **Database Health** | Integrity of internal Synology databases (PostgreSQL/SQLlite). |
| **Network Hardware** | Link status, interface errors, routing table integrity, and driver stability. |
| **Performance (Vmstat/Top)** | CPU load spikes, memory pressure, and D-state process detection. |
| **System Integrity** | DSM version history, package stability, and kernel-level log auditing. |

---

## 4. Forensic Integrity & Compliance Features
Designed for professional MSPs and high-security environments.

- **365-Day Retention**: Every scan report, uploaded file, and system interaction is preserved for one full year.
- **Immutable Audit Trail**: A complete history of activity, including logins (with IP/User Agent), file management, and results generation.
- **Accountability Snapshots**: Every token allocation or deduction is timestamped with a "Before vs. After" balance snapshot to ensure financial integrity.
- **Secure Isolation**: Built on PostgreSQL Row-Level Security (RLS) for absolute tenant data separation.

---

## 5. Experience & Interface (ActiveDesign)
The platform follows the "ActiveDesign" philosophy—premium, data-dense, and professional.

- **KPI Dashboards**: Instant visual feedback on total scans, token utilization, and system health scores.
- **Interactive Reports**: Filterable findings sorted by severity (**Critical**, **Warning**, **Info**, **OK**).
- **Project-Based Organization**: Group logs by customer or device for long-term health tracking.
- **Self-Service Exports**: Professional CSV exports of all activity logs for billing, auditing, or compliance reporting.

---

## Technical Specifications (Behind the Scenes)
- **Engine**: PHP 8.2+ High-Performance Core.
- **Intelligence**: Integrated with Groq AI (GPT-4 class diagnostic reasoning).
- **Concurrency**: Dedicated background workers with multi-job queuing.
- **Scalability**: Multi-tenant architecture designed to manage hundreds of independent NAS units.
