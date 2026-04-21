<?php

declare(strict_types=1);

namespace App\DeepDive\Report;

use App\DeepDive\Correlation\Incident;
use App\DeepDive\Rules\FindingRecord;
use App\DeepDive\Rules\RuleCatalogue;

/**
 * Produces the single-file HTML DeepDive report. Kept deliberately free
 * of external CSS/JS and inline-only so the same artifact renders
 * identically in a browser and can be piped through mPDF in Sprint 4
 * without chasing missing assets.
 *
 * Design notes:
 *   - Incidents are grouped first by actionability (what the user does
 *     about it), then by priority. This reflects how a NAS admin actually
 *     reads the report: "what do I need to fix myself?" is the first
 *     question, not "what's the highest priority?".
 *   - Evidence is always shown — never hidden behind an expander. If a
 *     finding claims something is broken, the user should see the log
 *     line that says so.
 *   - Narratives are used when present (Sprint 3b), else we fall back to
 *     the rule's own description + remediation text. Both paths produce
 *     a valid report.
 */
final class ReportRenderer
{
    private const ACTIONABILITY_ORDER = [
        'user_fixable'        => ['label' => 'What you can fix yourself',     'colour' => '#b45309'],
        'upgrade_recommended' => ['label' => 'Hardware upgrades recommended',  'colour' => '#9333ea'],
        'vendor_issue'        => ['label' => 'Possible vendor / firmware issues','colour' => '#dc2626'],
        'informational'       => ['label' => 'For your information',           'colour' => '#0369a1'],
    ];

    private const PRIORITY_COLOUR = [
        'P1' => '#dc2626',
        'P2' => '#ea580c',
        'P3' => '#ca8a04',
        'P4' => '#0891b2',
    ];

    /**
     * @param list<Incident>        $incidents
     * @param array<string,mixed>   $context  job_id, tenant_id, project_id, generated_at, engine_version, catalogue_version, bundles[], evaluator_errors[]
     */
    public function render(array $incidents, array $context, ?RuleCatalogue $catalogue = null): string
    {
        $css  = $this->styles();
        $head = $this->headerBlock($context);
        $summary = $this->summaryBlock($incidents);

        $grouped = $this->groupByActionability($incidents);
        $body    = '';
        foreach (self::ACTIONABILITY_ORDER as $key => $meta) {
            $group = $grouped[$key] ?? [];
            if ($group === []) continue;
            $body .= $this->groupBlock($meta['label'], $meta['colour'], $group, $catalogue);
        }
        if ($incidents === []) {
            $body .= '<section class="empty"><h2>No issues detected</h2><p>The rule catalogue ran against this bundle and found nothing of concern. This is either excellent news or an indicator that the bundle is missing expected log sources — check the "bundles processed" table in the appendix.</p></section>';
        }

        $appendix = $this->appendixBlock($context);

        $title = htmlspecialchars('DeepDive Report — ' . ($context['job_id'] ?? ''), ENT_QUOTES);
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{$title}</title>
<style>{$css}</style>
</head>
<body>
<main class="report">
{$head}
{$summary}
{$body}
{$appendix}
</main>
</body>
</html>
HTML;
    }

    /** @param list<Incident> $incidents */
    private function groupByActionability(array $incidents): array
    {
        $out = [];
        foreach ($incidents as $inc) {
            $out[$inc->actionability][] = $inc;
        }
        foreach ($out as $k => $group) {
            usort($group, static fn(Incident $a, Incident $b): int => strcmp($a->priority, $b->priority));
            $out[$k] = $group;
        }
        return $out;
    }

    /** @param array<string,mixed> $c */
    private function headerBlock(array $c): string
    {
        $gen  = htmlspecialchars((string)($c['generated_at']      ?? date('Y-m-d H:i:s T')), ENT_QUOTES);
        $eng  = htmlspecialchars((string)($c['engine_version']    ?? ''),                    ENT_QUOTES);
        $cat  = htmlspecialchars((string)($c['catalogue_version'] ?? ''),                    ENT_QUOTES);
        $job  = htmlspecialchars((string)($c['job_id']            ?? ''),                    ENT_QUOTES);
        $proj = htmlspecialchars((string)($c['project_id']        ?? ''),                    ENT_QUOTES);
        return <<<HTML
<header class="hdr">
  <div class="hdr-left">
    <div class="hdr-brand">DeepDive</div>
    <div class="hdr-sub">Isolated diagnostic report</div>
  </div>
  <div class="hdr-right">
    <div class="hdr-kv"><span>Job</span><code>{$job}</code></div>
    <div class="hdr-kv"><span>Project</span><code>{$proj}</code></div>
    <div class="hdr-kv"><span>Generated</span>{$gen}</div>
    <div class="hdr-kv"><span>Engine / Catalogue</span>{$eng} &middot; {$cat}</div>
  </div>
</header>
HTML;
    }

    /** @param list<Incident> $incidents */
    private function summaryBlock(array $incidents): string
    {
        $byPri = ['P1' => 0, 'P2' => 0, 'P3' => 0, 'P4' => 0];
        foreach ($incidents as $inc) {
            $byPri[$inc->priority] = ($byPri[$inc->priority] ?? 0) + 1;
        }
        $chips = '';
        foreach ($byPri as $p => $n) {
            $col = self::PRIORITY_COLOUR[$p] ?? '#666';
            $chips .= "<div class=\"chip\" style=\"--chip:{$col}\"><b>{$p}</b><span>{$n}</span></div>";
        }
        $total = count($incidents);
        $totalLabel = $total === 1 ? 'incident' : 'incidents';
        return <<<HTML
<section class="summary">
  <h2>Executive summary</h2>
  <div class="summary-row">
    <div class="summary-headline"><strong>{$total}</strong> {$totalLabel} identified</div>
    <div class="summary-chips">{$chips}</div>
  </div>
</section>
HTML;
    }

    /** @param list<Incident> $group */
    private function groupBlock(string $label, string $colour, array $group, ?RuleCatalogue $cat): string
    {
        $label = htmlspecialchars($label, ENT_QUOTES);
        $items = '';
        foreach ($group as $inc) {
            $items .= $this->incidentBlock($inc, $cat);
        }
        return <<<HTML
<section class="group" style="--group:{$colour}">
  <h2 class="group-title">{$label}</h2>
  <div class="group-items">{$items}</div>
</section>
HTML;
    }

    private function incidentBlock(Incident $inc, ?RuleCatalogue $cat): string
    {
        $priCol = self::PRIORITY_COLOUR[$inc->priority] ?? '#666';
        $title  = htmlspecialchars($inc->title, ENT_QUOTES);
        $summary = htmlspecialchars($inc->summary, ENT_QUOTES);

        // Narrative: prefer AI-generated, fall back to rule description + remediation
        $narrativeHtml = $this->narrativeHtml($inc, $cat);
        $actionsHtml   = $this->actionsHtml($inc, $cat);
        $chainHtml     = $this->chainHtml($inc, $cat);
        $evidenceHtml  = $this->evidenceHtml($inc);
        $sharedHtml    = $this->sharedEntitiesHtml($inc);

        return <<<HTML
<article class="incident">
  <header class="incident-hdr">
    <span class="pri-pill" style="background:{$priCol}">{$inc->priority}</span>
    <h3 class="incident-title">{$title}</h3>
  </header>
  <p class="incident-sub">{$summary}</p>
  {$sharedHtml}
  {$narrativeHtml}
  {$actionsHtml}
  {$chainHtml}
  {$evidenceHtml}
</article>
HTML;
    }

    private function narrativeHtml(Incident $inc, ?RuleCatalogue $cat): string
    {
        $root = $inc->rootCause;
        $narrDb = $this->narrativeFromDb($inc); // populated in render context if loaded from DB
        if ($narrDb !== null && $narrDb !== '') {
            return '<div class="narrative"><h4>What happened</h4><p>' . nl2br(htmlspecialchars($narrDb, ENT_QUOTES)) . '</p></div>';
        }
        // Fallback: rule description
        if ($root !== null && $cat !== null) {
            $rule = $cat->byId($root->ruleId);
            if ($rule !== null && $rule->description !== null) {
                return '<div class="narrative"><h4>What happened</h4><p>' .
                    nl2br(htmlspecialchars($rule->description, ENT_QUOTES)) .
                    '</p><p class="fallback-note">No AI narrative — using rule description.</p></div>';
            }
        }
        return '';
    }

    private function actionsHtml(Incident $inc, ?RuleCatalogue $cat): string
    {
        $actions = $this->actionsFromDb($inc);
        if ($actions !== []) {
            $li = '';
            foreach ($actions as $a) {
                $li .= '<li>' . htmlspecialchars($a, ENT_QUOTES) . '</li>';
            }
            return "<div class=\"actions\"><h4>Recommended actions</h4><ol>{$li}</ol></div>";
        }
        // Fallback: rule remediation
        if ($inc->rootCause !== null && $cat !== null) {
            $rule = $cat->byId($inc->rootCause->ruleId);
            if ($rule !== null && $rule->remediation !== null) {
                return '<div class="actions"><h4>Recommended actions</h4><p>' .
                    nl2br(htmlspecialchars($rule->remediation, ENT_QUOTES)) . '</p></div>';
            }
        }
        return '';
    }

    private function chainHtml(Incident $inc, ?RuleCatalogue $cat): string
    {
        if (count($inc->causeChain) < 2) return '';
        $chips = '';
        foreach ($inc->causeChain as $i => $ruleId) {
            $title = $ruleId;
            if ($cat !== null && ($r = $cat->byId($ruleId)) !== null) $title = $r->title;
            $label = htmlspecialchars($ruleId, ENT_QUOTES);
            $tip   = htmlspecialchars($title,  ENT_QUOTES);
            $chips .= "<span class=\"chain-chip\" title=\"{$tip}\">{$label}</span>";
            if ($i < count($inc->causeChain) - 1) $chips .= '<span class="chain-arrow">→</span>';
        }
        return "<div class=\"chain\"><h4>Cause chain</h4><div class=\"chain-row\">{$chips}</div></div>";
    }

    private function evidenceHtml(Incident $inc): string
    {
        $rows = '';
        foreach ($inc->findings as $f) {
            foreach (array_slice($f->citations, 0, 3) as $c) {
                $file = htmlspecialchars(basename((string)($c['file'] ?? '')), ENT_QUOTES);
                $line = (int)($c['line_number'] ?? 0);
                $ts   = htmlspecialchars((string)($c['timestamp'] ?? ''), ENT_QUOTES);
                $ex   = htmlspecialchars(mb_substr((string)($c['excerpt'] ?? ''), 0, 260), ENT_QUOTES);
                $rid  = htmlspecialchars($f->ruleId, ENT_QUOTES);
                $rows .= "<tr><td class=\"mono\">{$rid}</td><td class=\"mono\">{$file}:{$line}</td><td class=\"mono\">{$ts}</td><td>{$ex}</td></tr>";
            }
        }
        if ($rows === '') return '';
        return <<<HTML
<div class="evidence">
  <h4>Evidence</h4>
  <table class="ev-table">
    <thead><tr><th>Rule</th><th>Location</th><th>Timestamp</th><th>Excerpt</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</div>
HTML;
    }

    private function sharedEntitiesHtml(Incident $inc): string
    {
        if ($inc->sharedEntities === []) return '';
        $chips = '';
        foreach ($inc->sharedEntities as $k => $v) {
            $chips .= '<span class="ent-chip"><b>' . htmlspecialchars($k, ENT_QUOTES) . '</b> ' . htmlspecialchars((string)$v, ENT_QUOTES) . '</span>';
        }
        return "<div class=\"ent-row\">{$chips}</div>";
    }

    /** @param array<string,mixed> $c */
    private function appendixBlock(array $c): string
    {
        $bundleRows = '';
        foreach (($c['bundles'] ?? []) as $b) {
            $id    = htmlspecialchars((string)($b['debug_file_id'] ?? ''), ENT_QUOTES);
            $root  = htmlspecialchars((string)($b['root'] ?? ''), ENT_QUOTES);
            $nfi   = (int)($b['file_count'] ?? 0);
            $size  = htmlspecialchars($this->humanBytes((int)($b['size_bytes'] ?? 0)), ENT_QUOTES);
            $log   = htmlspecialchars(implode(', ', (array)($b['sources_log']    ?? [])), ENT_QUOTES);
            $sql   = htmlspecialchars(implode(', ', (array)($b['sources_sqlite'] ?? [])), ENT_QUOTES);
            $bundleRows .= "<tr><td class=\"mono\">{$id}</td><td class=\"mono\">{$root}</td><td>{$nfi}</td><td>{$size}</td><td class=\"mono\">{$log}</td><td class=\"mono\">{$sql}</td></tr>";
        }
        $bundleRows = $bundleRows ?: '<tr><td colspan="6" class="muted">No bundle metadata recorded.</td></tr>';

        $errorRows = '';
        foreach (($c['evaluator_errors'] ?? []) as $e) {
            $r = htmlspecialchars((string)($e['rule_id'] ?? ''), ENT_QUOTES);
            $m = htmlspecialchars((string)($e['error']   ?? ''), ENT_QUOTES);
            $errorRows .= "<tr><td class=\"mono\">{$r}</td><td>{$m}</td></tr>";
        }
        $errorBlock = $errorRows === '' ? '' : <<<HTML
<div class="apx-block">
  <h3>Rule evaluation errors</h3>
  <table class="apx-table"><thead><tr><th>Rule</th><th>Error</th></tr></thead><tbody>{$errorRows}</tbody></table>
</div>
HTML;

        return <<<HTML
<section class="appendix">
  <h2>Appendix</h2>
  <div class="apx-block">
    <h3>Bundles processed</h3>
    <table class="apx-table">
      <thead><tr><th>Debug file</th><th>Root</th><th>Files</th><th>Size</th><th>Log sources</th><th>SQLite sources</th></tr></thead>
      <tbody>{$bundleRows}</tbody>
    </table>
  </div>
  {$errorBlock}
</section>
HTML;
    }

    /**
     * The narrator and correlator persist narrative + recommended_actions to
     * deepdive_incidents, but at render time we only have the in-memory
     * Incident. We stash the DB-loaded fields on the Incident via two extra
     * properties so the RenderStep can pass them through. Since Incident is
     * readonly, we stash them on the PipelineContext bag and look them up here.
     *
     * To keep the renderer self-contained, it accepts a side-channel map
     * through the `context` array's `db_overlay` key, e.g.:
     *   $ctx['db_overlay'][$inc->id] = ['narrative' => ..., 'recommended_actions' => [...]]
     */
    private array $dbOverlay = [];

    public function setDbOverlay(array $overlay): void { $this->dbOverlay = $overlay; }

    private function narrativeFromDb(Incident $inc): ?string
    {
        $row = $this->dbOverlay[$inc->id] ?? null;
        if (!is_array($row)) return null;
        $n = $row['narrative'] ?? null;
        return is_string($n) && $n !== '' ? $n : null;
    }

    /** @return list<string> */
    private function actionsFromDb(Incident $inc): array
    {
        $row = $this->dbOverlay[$inc->id] ?? null;
        if (!is_array($row)) return [];
        $a = $row['recommended_actions'] ?? null;
        if (is_string($a)) $a = json_decode($a, true);
        if (!is_array($a)) return [];
        return array_values(array_filter(array_map('strval', $a)));
    }

    private function humanBytes(int $n): string
    {
        $units = ['B','KB','MB','GB','TB'];
        $i = 0; $v = (float)$n;
        while ($v >= 1024 && $i < count($units) - 1) { $v /= 1024; $i++; }
        return sprintf('%.1f %s', $v, $units[$i]);
    }

    private function styles(): string
    {
        return <<<CSS
*{box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#1f2937;background:#f9fafb;margin:0;padding:32px 16px;font-size:14px;line-height:1.55}
.report{max-width:960px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:40px}
.hdr{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #111827;padding-bottom:18px;margin-bottom:24px;gap:24px;flex-wrap:wrap}
.hdr-brand{font-size:24px;font-weight:800;letter-spacing:-.02em}
.hdr-sub{color:#6b7280;font-size:13px;margin-top:2px}
.hdr-right{display:grid;grid-template-columns:1fr 1fr;gap:6px 18px;font-size:12px}
.hdr-kv span{display:block;color:#6b7280;text-transform:uppercase;font-size:10px;letter-spacing:.08em;font-weight:600}
.hdr-kv code{font-family:"SF Mono",Menlo,Consolas,monospace;font-size:12px;color:#111827}
h2{font-size:16px;text-transform:uppercase;letter-spacing:.06em;color:#374151;border-bottom:1px solid #e5e7eb;padding-bottom:8px;margin:32px 0 18px}
h3{font-size:15px;margin:0}
h4{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin:14px 0 6px}
.summary-row{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:20px}
.summary-headline{font-size:16px}.summary-headline strong{font-size:28px;color:#111827;margin-right:6px}
.summary-chips{display:flex;gap:10px;flex-wrap:wrap}
.chip{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:600;background:color-mix(in srgb,var(--chip) 12%,white);color:var(--chip);border:1px solid color-mix(in srgb,var(--chip) 40%,white)}
.chip b{font-weight:700}
.group{border-left:3px solid var(--group);padding-left:16px;margin:28px 0}
.group-title{color:var(--group);border-bottom-color:color-mix(in srgb,var(--group) 25%,#e5e7eb)}
.incident{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-bottom:16px}
.incident-hdr{display:flex;align-items:center;gap:12px}
.pri-pill{color:#fff;font-weight:700;font-size:11px;padding:2px 9px;border-radius:4px;letter-spacing:.04em}
.incident-title{flex:1}
.incident-sub{color:#4b5563;font-size:13px;margin:6px 0 0}
.ent-row{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.ent-chip{background:#f3f4f6;border:1px solid #e5e7eb;border-radius:4px;padding:2px 8px;font-size:11px;color:#374151}
.ent-chip b{color:#111827;margin-right:4px}
.narrative p{margin:0 0 4px}
.fallback-note{color:#9ca3af;font-size:11px;font-style:italic}
.actions ol{margin:0;padding-left:22px}
.actions li{margin-bottom:4px}
.chain-row{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.chain-chip{background:#eff6ff;border:1px solid #bfdbfe;color:#1d4ed8;padding:2px 8px;border-radius:4px;font-size:11px;font-family:"SF Mono",Menlo,Consolas,monospace}
.chain-arrow{color:#9ca3af;font-weight:700}
.evidence{margin-top:16px}
.ev-table{width:100%;border-collapse:collapse;font-size:12px}
.ev-table th,.ev-table td{text-align:left;padding:6px 8px;border-bottom:1px solid #f3f4f6;vertical-align:top}
.ev-table th{background:#f9fafb;font-weight:600;color:#6b7280;text-transform:uppercase;font-size:10px;letter-spacing:.05em}
.mono{font-family:"SF Mono",Menlo,Consolas,monospace;font-size:11px;color:#374151;word-break:break-all}
.empty{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:18px 20px;color:#166534}
.empty h2{border:none;color:#166534;margin-top:0}
.appendix{margin-top:40px}
.apx-block{margin-bottom:20px}
.apx-block h3{color:#374151;margin-bottom:10px;font-size:13px;text-transform:uppercase;letter-spacing:.04em}
.apx-table{width:100%;border-collapse:collapse;font-size:12px}
.apx-table th,.apx-table td{text-align:left;padding:6px 8px;border-bottom:1px solid #f3f4f6;vertical-align:top}
.apx-table th{background:#f9fafb;font-weight:600;color:#6b7280;text-transform:uppercase;font-size:10px;letter-spacing:.05em}
.muted{color:#9ca3af;font-style:italic}
CSS;
    }
}
