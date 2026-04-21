# DeepDive — Isolated Diagnostic Engine Implementation Plan

**Status:** draft v0.2 (revised for non-destructive isolation)
**Principle:** zero risk to existing AI Diagnostic flow. Everything new lives in its own folders, its own tables, its own worker. A failed experiment is removed by deleting a directory and reverting a migration.

---

## 1. Isolation contract

The existing system keeps working exactly as it does today. DeepDive is *additive*, not a replacement. We touch existing code only at four well-defined surfaces; everything else is new and self-contained.

### Touches existing code (minimal, surgical)

| Surface | Change | Why it's safe |
|---|---|---|
| `composer.json` | Add PSR-4 entry `"App\\DeepDive\\": "src/DeepDive/"`; add `mpdf/mpdf` and `symfony/yaml` | Additive only. No existing package versions move. |
| `src/AppBootstrap.php` | Single commented block `// --- DeepDive routes ---` registering ~6 new routes under `/deepdive/*` | Additive. Existing routes untouched. Remove block = feature gone. |
| `templates/tenant/project_view.twig` | One `{% include 'tenant/deepdive/panel.twig' %}` directive near the existing scan button | Panel is self-contained; if include is removed the page still renders. |
| `migrations/` | One new migration file creating `deepdive_*` tables | Does not alter existing tables. Reversible by a companion `down` migration. |

No other existing file is modified. No existing table, row, or column is changed.

### Fully isolated (deletable as a unit)

```
src/DeepDive/                  # all new PHP code; namespace App\DeepDive
  Controllers/
  Services/
  Parsers/                     # DeepDive owns its parser layer; no reuse of src/Parsers/
  Detectors/                   # rule engine runtime
  Narrator/                    # AI narration service (uses existing Groq key, own class)
  Reports/                     # HTML + PDF render
  Models/
  Worker/                      # worker entry point and pipeline steps
config/deepdive/
  detectors/                   # YAML rule catalogue
  report_template.yaml         # report layout config
storage/deepdive/
  extracted/{job_id}/          # ephemeral extraction target — deleted at end of job
  reports/{job_id}.html        # persistent HTML report
  reports/{job_id}.pdf         # persistent PDF report
  tmp/{job_id}/                # intermediate fact JSONs during run (deleted at end)
  logs/{job_id}.log            # per-job run log (kept for audit, small)
workers/deepdive_worker.php    # separate long-running daemon
public/api/deepdive_progress.php   # separate SSE endpoint (mirrors existing pattern)
templates/tenant/deepdive/
  panel.twig                   # injected into project_view.twig
  progress.twig                # stepped progress UI
  report.twig                  # inline HTML report view
  report_print.twig            # print/PDF-oriented template
tests/deepdive/                # fixtures + unit tests
```

### Reuses existing infrastructure (read-only dependencies)

- **AuthMiddleware / CSRF / tenant context** — security is one source of truth; DeepDive sits behind the existing middleware stack. Reusing it is the right call.
- **Database connection** — same Postgres, different tables.
- **Twig environment** — shared renderer, separate templates.
- **Audit log** — DeepDive writes to `audit_log` (one-way); `audit_log` doesn't depend on DeepDive.
- **Groq API key from `.env`** — same key, separate `Narrator` service class; the existing `AiService` is untouched.

---

## 2. Data model — new tables only

```sql
CREATE TABLE deepdive_jobs (
  id                  UUID PRIMARY KEY,
  tenant_id           UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  project_id          UUID NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  debug_file_ids      UUID[] NOT NULL,
  status              TEXT NOT NULL DEFAULT 'queued',  -- queued|running|completed|failed|cancelled
  steps_json          JSONB,                           -- ordered step list with status/timing
  progress_percent    SMALLINT DEFAULT 0,
  progress_stage      TEXT,
  report_html_path    TEXT,
  report_pdf_path     TEXT,
  report_format       TEXT,                            -- 'html' | 'html+pdf'
  engine_version      TEXT NOT NULL,                   -- e.g. 'deepdive-0.1'
  rule_catalogue_ver  TEXT,
  error_message       TEXT,
  queued_at           TIMESTAMPTZ DEFAULT now(),
  started_at          TIMESTAMPTZ,
  completed_at        TIMESTAMPTZ,
  cleanup_status      TEXT DEFAULT 'pending'           -- pending|done|failed
);

CREATE TABLE deepdive_incidents (
  id                  UUID PRIMARY KEY,
  deepdive_job_id     UUID NOT NULL REFERENCES deepdive_jobs(id) ON DELETE CASCADE,
  tenant_id           UUID NOT NULL,
  priority            TEXT NOT NULL,                   -- P1|P2|P3|P4
  actionability       TEXT NOT NULL,                   -- user_fixable|upgrade_recommended|vendor_issue|informational
  title               TEXT NOT NULL,
  summary             TEXT,
  narrative           TEXT,                            -- AI-generated plain-English explanation
  recommended_actions JSONB,                           -- ordered list of concrete steps
  root_cause_finding_id UUID,
  created_at          TIMESTAMPTZ DEFAULT now()
);

CREATE TABLE deepdive_findings (
  id                  UUID PRIMARY KEY,
  deepdive_job_id     UUID NOT NULL REFERENCES deepdive_jobs(id) ON DELETE CASCADE,
  incident_id         UUID REFERENCES deepdive_incidents(id),
  tenant_id           UUID NOT NULL,
  rule_id             TEXT NOT NULL,                   -- e.g. STG-RAID-001
  rule_version        INT NOT NULL,
  severity            TEXT NOT NULL,                   -- info|warn|high|critical
  confidence          NUMERIC(3,2),
  actionability       TEXT NOT NULL,
  title               TEXT NOT NULL,
  entities            JSONB,                           -- {device, array, volume, ...}
  citations           JSONB,                           -- [{file, line_range, ts, snippet}, ...]
  cause_chain_refs    JSONB,                           -- {upstream: [...], downstream: [...]}
  created_at          TIMESTAMPTZ DEFAULT now()
);

CREATE INDEX ON deepdive_jobs(tenant_id, status);
CREATE INDEX ON deepdive_findings(deepdive_job_id);
CREATE INDEX ON deepdive_incidents(deepdive_job_id, priority);
```

RLS policies mirror the existing `scan_*` policies — tenant-scoped, identical pattern, separate table names. Plus a per-tenant feature flag so we can rollout carefully:

```sql
ALTER TABLE tenants ADD COLUMN deepdive_enabled BOOLEAN DEFAULT FALSE;
```

Rolling back the whole feature: `DROP TABLE deepdive_findings, deepdive_incidents, deepdive_jobs; ALTER TABLE tenants DROP COLUMN deepdive_enabled;`. Done.

---

## 3. Pipeline — separate worker, fail-soft per step

`workers/deepdive_worker.php` is a standalone daemon, launched the same way the existing `scan_worker.php` is (systemd unit or whatever the deploy uses — one new unit file). It polls `deepdive_jobs` only. A crash in this worker cannot affect the existing scan worker.

Pipeline steps (each writes to `steps_json`; a step failure does not necessarily fail the whole job — where possible, mark step failed and continue):

1. **Validate** — job row, file exists, tenant flag enabled. *1%*
2. **Ensure extracted** — extract to `storage/deepdive/extracted/{job_id}/` (separate from the existing `storage/extracted/`). Never re-uses existing extraction. *5→15%*
3. **Decompress** — expand `*.log.N.xz` in place. *15→22%*
4. **Parse facts** — DeepDive's own parsers produce typed records with `{file, line_range}` provenance. *22→55%*
5. **Evaluate rules** — load YAML catalogue from `config/deepdive/detectors/`, run rules. Each rule is sandboxed: rule crash → log + continue. *55→75%*
6. **Correlate** — walk cause-chain graph, emit incidents. *75→82%*
7. **Narrate** — optional AI pass. Skipped if `report_plan.ai_model='none'` or if token budget hits zero. *82→90%*
8. **Render report** — HTML (always), PDF (optional based on config). Stored under `storage/deepdive/reports/`. *90→97%*
9. **Cleanup** — delete `storage/deepdive/extracted/{job_id}/` and `storage/deepdive/tmp/{job_id}/`. Mark `cleanup_status='done'`. *97→100%*

Cleanup is *immediate* per your direction — no 24-hour retention. If the user re-runs, the pipeline re-extracts. Raw uploaded `.dat` stays in its existing location; we only manage our own extraction.

**Cleanup safety net:** on worker startup, any job with `cleanup_status='pending'` older than 1 hour whose extraction tree still exists gets cleaned. Handles crash-after-report-but-before-cleanup.

**Concurrency:** start with a single-job worker. Existing scan worker is unaffected regardless. Can bump concurrency later if demand requires.

---

## 4. UI — one button, one panel, one report view

**Project page change (one include):**

```twig
{# templates/tenant/project_view.twig — existing file, one line added near the scan section #}
{% include 'tenant/deepdive/panel.twig' %}
```

The panel renders only if `tenant.deepdive_enabled` is true; otherwise it returns nothing. So on tenants where the feature isn't enabled, the UI is identical to today.

**The panel (`tenant/deepdive/panel.twig`):**
- Title: "Deep Diagnostic (Beta)"
- File checkboxes (re-uses the same `debug_file` listing the main scan uses, read-only).
- One button: **"Run Deep Dive"**.
- Below: status area. When a DeepDive job is running, shows stepped progress (reads `steps_json`). When complete, shows action counts + buttons: **View Report**, **Download HTML**, **Download PDF**.
- History: small list "Past deep dives" with date, health score, and re-download links.

**Progress UX:**

Instead of a shimmer bar with mystery text, the progress panel shows each pipeline step as a row: a spinner on `running`, a check on `done`, a dash on `pending`, a warning on `skipped`, an X on `failed`. Sub-detail under the current step (e.g. "Parsing kern.log... 23 of 47 log files"). Driven by the SSE endpoint `public/api/deepdive_progress.php` that streams `steps_json`.

**Report view (`tenant/deepdive/report.twig`):**
- Header: NAS model, serial, DSM version, report date, health grade.
- **Executive summary** — one paragraph.
- **Incidents** ordered by priority, grouped by actionability:
  - "What you should do" section (P1/P2 + `user_fixable` / `upgrade_recommended`) — leads the report.
  - "Known vendor behaviour — no action required" (`vendor_issue`) — collapsed.
  - "Informational" (`informational`) — collapsed.
- Each incident card: *What's happening / Why it matters / What to do / Evidence chips*.
- Appendix: raw detections table with rule IDs for engineering.

**Print / PDF template (`report_print.twig`)** is the same content in print-friendly CSS, fed to mPDF.

---

## 5. Actionability classification — the heart of the value proposition

Every finding carries an `actionability` tag. The report puts the first two at the top and collapses the last two.

| Tag | Meaning | Shown as |
|---|---|---|
| `user_fixable` | Customer can resolve with a specific action (replace disk, block IP, free space, fix cable). | "What you should do" — ordered action list. |
| `upgrade_recommended` | Current config is working but constrained; upgrade improves it (add RAM, swap SMR→CMR, add UPS). | "Recommended improvements". |
| `vendor_issue` | Known Synology/DSM behaviour or bug; customer can't fix, may be fixed in a DSM version. | Collapsed under "Known DSM behaviours". |
| `informational` | Factual observations (package inventory, DSM version). | Appendix only. |

Rules author the tag in their YAML: `actionability: user_fixable`. The report layout is driven by it.

This is how we stay honest: we never promise to fix things we can't fix, but we prominently surface the ones we can.

---

## 6. Options / suggestions you asked for

**Option A — HTML first, PDF later.**
Skip mPDF in the first sprint. Render HTML only; let users view and print from the browser. Add PDF in sprint 3 once content is stable. Saves 1–2 days and avoids fighting a renderer before the content is right. **My recommendation: do this.**

**Option B — Per-tenant feature flag (`tenants.deepdive_enabled`).**
Roll out to your own tenant first, then trusted customers, then everyone. The panel/button don't render if the flag is off — zero footprint for non-enrolled tenants. **My recommendation: do this.** Single boolean column, trivial to toggle.

**Option C — Debug mode on a job.**
A per-job flag (`deepdive_jobs.debug_mode`) that retains the intermediate fact JSONs and the extraction tree *only if enabled*, for rule authoring. Off by default so your cleanup rule holds. Useful when a customer says "the report missed X" and you want to re-run the detectors against the captured facts without re-extracting. **My recommendation: add it but leave off by default.**

**Option D — Read-only DeepDive for admin tenants.**
Admins can view any tenant's DeepDive reports for support. Reuses existing admin-role logic. Zero new auth code. **Low cost, recommend including.**

**Option E — Rule-author CLI tool.**
A tiny CLI (`bin/deepdive-eval <rule_id> <sample.dat>`) that runs a single rule against a fixture and prints the finding JSON. No UI impact. Makes rule iteration fast. **Recommend including in sprint 2.**

**Option F — Rate limit / single-active-job-per-tenant.**
Prevent the same tenant queueing 50 DeepDive jobs at once while we iterate on performance. Simple guard in the controller: reject if tenant has a `queued` or `running` job. **Recommend.**

**Option G — Keep existing scan worker out of the loop entirely.**
Do not add an `engine` column to `scan_jobs`. Do not touch `scan_worker.php`. DeepDive has its own worker and its own queue table. **This is what the updated plan does.**

**Option H — Shared fixture corpus.**
Both systems read from `sample/*.dat`. That's already the case and stays that way — no change, no conflict.

---

## 7. Risks to existing system — and how each is mitigated

| Risk | Mitigation |
|---|---|
| New worker crashes and stalls existing queue | They're separate daemons. Cannot happen. |
| DeepDive migration fails partway | New migration only *creates* tables and *adds* one nullable column. Easy rollback; no existing data at risk. |
| Composer dependency conflict | `mpdf/mpdf` and `symfony/yaml` have no shared ancestors with existing deps. Verify with `composer update --dry-run` before merge. |
| Routes in AppBootstrap conflict | All DeepDive routes under `/deepdive/*` prefix; no existing routes use that prefix. |
| DeepDive pipeline consumes too much disk via extraction | Separate directory `storage/deepdive/extracted/`; immediate cleanup; can be mounted on a different volume if needed. |
| Runaway DeepDive job burns CPU | Single-job concurrency + per-tenant single-active guard (Option F). Can set `nice` on the worker process. |
| Template include errors break project page | Panel template guards on `tenant.deepdive_enabled`; if anything goes wrong inside, `{% include ... ignore missing %}` keeps the page rendering. |
| AI narrator leaks tenant data to Groq | Narrator only ever sees the structured incidents JSON, never raw logs. PII redaction pass runs before the call. |
| Worker memory growth over long uptime | Worker processes one job then exits if memory > threshold; supervisor restarts it. Classic pattern; keeps leaks harmless. |

If at any point we decide DeepDive isn't working: delete `src/DeepDive/`, `config/deepdive/`, `storage/deepdive/`, `workers/deepdive_worker.php`, `public/api/deepdive_progress.php`, `templates/tenant/deepdive/`, `tests/deepdive/`; revert the four surgical edits; drop the three tables and one column. Existing system is byte-identical to pre-DeepDive.

---

## 8. Build order (revised)

**Sprint 1 — foundation (isolated, no UI yet)  ≈ 5 days**
- Composer PSR-4 namespace + `symfony/yaml` dep.
- Migration for `deepdive_*` tables + `tenants.deepdive_enabled`.
- `App\DeepDive\Worker\Daemon` skeleton with claim-lease + stepped progress.
- Extraction + .xz decompression into the isolated directory.
- Cleanup step with self-healing orphan sweep.
- Two-token end-to-end smoke test: job goes `queued → running → completed` producing a stub HTML report that says "hello world, job ran on bundle X".

**Sprint 2 — parsers + rules  ≈ 6 days**
- DeepDive parsers for: kern.log, messages, scemd.log, mdstat, SMART DB (`.SYNODISKHEALTHDB`), btrfs result files, auth.log, vmstat, top, df.
- YAML rule loader + detector runtime (`regex`, `sqlite`, `aggregate`, `absence` signature types).
- Authoring 20 priority rules from the catalogue (the RAID/disk/FS/memory/network core).
- CLI tool `bin/deepdive-eval` for rule authoring (Option E).
- Fixture tests against `sample/*.dat`.

**Sprint 3 — correlation, narrator, report  ≈ 4 days**
- Cause-chain walker + incidents table populated.
- Narrator service with redaction + strict-prompt AI call.
- HTML report template (executive summary, prioritised incidents, evidence chips, appendix).
- Download-HTML button.

**Sprint 4 — UI panel + progress + PDF  ≈ 3 days**
- `tenant/deepdive/panel.twig` + the one include in `project_view.twig`.
- Stepped progress UI with SSE.
- mPDF integration and **Download PDF** button (Option A: deferred to here).
- Past-reports history list.

**Sprint 5 — rollout + telemetry  ≈ 2 days**
- Admin toggle for `deepdive_enabled` per tenant.
- Internal-tenant enable → iterate on rules from real bundles.
- Small telemetry: rule fire rates, job duration, narrator token spend per job.

Total: ~20 working days for one engineer to a credible beta that shipsalongside the existing system without any risk to it.

---

## 9. What I still need from you before starting

1. **Button label** — "Run Deep Dive" or something else? ("Deep Diagnostic", "Forensic Scan", "Health Analysis"…)
2. **Initial rollout scope** — which tenant(s) get `deepdive_enabled=true` first? Your own? A specific trusted customer?
3. **Report branding** — logo from `/logo/` in the header? Tenant name visible? Any fixed disclaimer text?
4. **AI narrator cost** — stay free during beta (we eat the Groq cost) or charge flat per DeepDive report? I'd suggest free during beta, flat fee once stable.
5. **Debug-mode retention** (Option C) — keep the per-job debug retain toggle, or strict-cleanup-always? I'd keep the toggle; it pays for itself the first time a rule misfires.
6. **Sign-off on the 20 Phase-2 rules before I author them**, or let me pick and you review the output? Your call; 15-minute review saves rework.

Say go, tell me any of the above to change, and I'll start Sprint 1.
