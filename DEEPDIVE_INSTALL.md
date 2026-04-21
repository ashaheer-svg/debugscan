# DeepDive install checklist

Everything below is safe to run against the existing `ai-debugscan3` deployment — DeepDive is additive only, and every step is reversible.

## 1. Composer install

One new runtime dependency was added to `composer.json`: `mpdf/mpdf ^8.2` (PDF export). `symfony/yaml ^6.4` was already added earlier for the rule engine.

```bash
composer install
```

If `composer install` is not possible in production, DeepDive degrades cleanly:

- mPDF missing → HTML-only reports, the "pdf" link in the panel just won't render.
- `symfony/yaml` missing → the rule engine will fail to load the catalogue, and `NarrateStep` / `RenderStep` will fall back to empty results. Rule evaluation is effectively disabled, but nothing else breaks.

## 2. Database migrations

Two migrations to apply, in order:

```bash
psql "$DATABASE_URL" -f migrations/017_deepdive_schema.sql
psql "$DATABASE_URL" -f migrations/018_deepdive_telemetry.sql
```

- `017` — adds `users.deepdive_enabled`, plus tables `deepdive_jobs`, `deepdive_incidents`, `deepdive_findings` with matching RLS policies.
- `018` — adds `deepdive_jobs.narrator_tokens_used` for Sprint 5 telemetry.

Reverse scripts live next to each (`*.down.sql`).

## 3. Filesystem prep

Worker writes under `storage/deepdive/`. Directories are auto-created, but the parent must be writable by the PHP process:

```bash
mkdir -p storage/deepdive/{extracted,tmp,reports,logs}
chmod 775 storage/deepdive
```

## 4. Environment

Optional (everything has a safe default when unset):

- `GROQ_API_KEY` — enables the AI narrator. If unset, the report uses rule description/remediation text as the narrative/actions. This is the intended beta default.
- `DEEPDIVE_RULES_DIR` — override catalogue path. Defaults to `config/deepdive/rules`.

## 5. Worker

The worker is a long-running daemon (separate from the existing scan worker):

```bash
php workers/deepdive_worker.php
```

Run it under the same supervisor you use for `workers/` today (systemd / supervisord). On boot it rescues jobs whose lease has expired (crash recovery).

## 6. Enable for a tenant

Admin UI → Tenants → edit a tenant → tick **Enable DeepDive**. The project view for that tenant will now show the "Deep Diagnostic" panel beneath the AI Diagnostic.

Or via SQL for a quick pilot:

```sql
UPDATE users SET deepdive_enabled = true WHERE email = 'pilot@customer.com';
```

## 7. Smoke test

1. Log in as the enabled tenant.
2. Open any project with at least one uploaded debug bundle.
3. Click **Run Deep Dive** in the new panel.
4. The progress page (`/deepdive/view/<job_id>`) should stream steps: validate → extract → decompress → parse → evaluate → correlate → narrate → render → cleanup.
5. When complete, click **view** to render the HTML report inline, or **html / pdf** to download.

## 8. Ops tools

```bash
# Rule authoring: run the catalogue against an extracted bundle, no DB touch
bin/deepdive-eval /path/to/extracted-bundle
bin/deepdive-eval --only=storage.raid_degraded /path/to/extracted-bundle

# Telemetry: job volume, duration percentiles, token spend, rule fire rates
bin/deepdive-telemetry                 # last 14 days
bin/deepdive-telemetry --days=30 --top=20
```

## 9. Rip-out procedure

If DeepDive needs to be removed with zero residue:

1. Stop `deepdive_worker.php`.
2. Apply reverse migrations in opposite order:
   ```bash
   psql "$DATABASE_URL" -f migrations/018_deepdive_telemetry.down.sql
   psql "$DATABASE_URL" -f migrations/017_deepdive_schema.down.sql
   ```
3. Remove the route block in `src/AppBootstrap.php` (bracketed by
   `--- DeepDive wiring ---` / `--- end DeepDive wiring ---` and
   `--- DeepDive routes (isolated; remove this block to disable feature) ---`).
4. Delete `src/DeepDive/`, `config/deepdive/`, `templates/tenant/deepdive/`, `workers/deepdive_worker.php`, `public/api/deepdive_progress.php`, `bin/deepdive-*`, `migrations/017_*`, `migrations/018_*`, `storage/deepdive/`.
5. Revert the three lines added to `TenantController::projectView` (the `deepdive_history` block) and the DeepDive checkbox block in `templates/admin/tenants.twig`.
6. Revert the `deepdive_enabled` tenant read in `ViewDataMiddleware.php`.
7. Revert the `mpdf/mpdf` line in `composer.json` and re-run `composer update --lock`.

The rest of the application is untouched.

## Files touched outside `src/DeepDive/`

Exactly seven:

- `composer.json` — added `mpdf/mpdf` and `symfony/yaml`
- `src/AppBootstrap.php` — wired controller + routes
- `src/Controllers/AdminController.php` — `deepdive_enabled` on tenant create/edit
- `src/Controllers/TenantController.php` — fetches `deepdive_history` for project view
- `src/Middleware/ViewDataMiddleware.php` — exposes `tenant.deepdive_enabled` to Twig
- `templates/tenant/project_view.twig` — single `{% include ... ignore missing %}` of the DeepDive panel
- `templates/admin/tenants.twig` — DeepDive checkbox in create/edit modals

Everything else is inside isolated DeepDive-owned directories.
