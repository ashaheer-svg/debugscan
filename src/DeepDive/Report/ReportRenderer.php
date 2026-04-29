<?php

declare(strict_types=1);

namespace App\DeepDive\Report;

use App\DeepDive\Correlation\Incident;
use App\DeepDive\Rules\FindingRecord;
use App\DeepDive\Rules\RuleCatalogue;
use App\DeepDive\Support\Engine;
use App\DeepDive\Visualization\BayLayoutRenderer;

/**
 * ReportRenderer: Generate comprehensive HTML analysis reports
 *
 * PURPOSE:
 * Converts incident analysis results into professional HTML report
 * Produces single-file HTML with embedded CSS (no external assets)
 * Compatible with mPDF for PDF export (Sprint 4)
 *
 * REPORT DESIGN PHILOSOPHY:
 * - User-centric organization: Group by actionability first (user fixable, upgrade, vendor issue)
 * - Evidence-driven: Always show log lines and data supporting findings
 * - Narrative-rich: Use AI-generated narratives when available, fallback to rule text
 * - Professional: Color-coded sections, clear hierarchy, appendix with metadata
 *
 * REPORT SECTIONS:
 * 1. Header: Job ID, timestamp, system info
 * 2. Hardware Configuration: Device specs, drive inventory, RAID status
 * 3. Summary: Quick statistics (findings by priority)
 * 4. Grouped Incidents:
 *    - What you can fix yourself (user-actionable)
 *    - Hardware upgrades recommended (expansion needed)
 *    - Possible vendor/firmware issues (out of control)
 *    - Informational (FYI)
 * 5. Appendix: Bundle details, rule versions, evaluation errors
 *
 * INCIDENT GROUPING:
 * Primary: By actionability (reflects how admins read reports)
 * Secondary: By priority (P1/P2/P3/P4)
 * Each group color-coded for visual scanning
 *
 * NARRATIVE OVERLAY:
 * Narratives (from NarrateStep) preferred when available
 * Fallback to rule's built-in description + remediation
 * Both paths produce complete, valid reports
 *
 * SELF-CONTAINED HTML:
 * All CSS inlined in <style> tag
 * No external JavaScript or stylesheets
 * Images as data: URIs (base64)
 * Renders identically in browsers and mPDF
 *
 * OUTPUT FORMAT:
 * Returns HTML5 document string ready for:
 * - Direct browser display
 * - File download
 * - mPDF conversion to PDF
 * - Archival and email distribution
 *
 * @package App\DeepDive\Report
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
     * Render incidents into comprehensive HTML report
     *
     * FLOW:
     * 1. Generate inline CSS styles
     * 2. Render header block (job info, metadata)
     * 3. Render hardware configuration block
     * 4. Generate summary statistics
     * 5. Group incidents by actionability
     * 6. Render grouped incident sections
     * 7. Render appendix (bundles, versions, errors)
     * 8. Assemble HTML5 document
     * 9. Return complete HTML string
     *
     * PARAMETERS:
     * - $incidents: List of Incident objects from CorrelateStep
     * - $context: Metadata for report:
     *   - job_id: Unique job identifier
     *   - tenant_id: Tenant context
     *   - project_id: Project context
     *   - generated_at: Timestamp
     *   - engine_version: DeepDive version
     *   - catalogue_version: Rule catalogue version
     *   - bundles[]: List of processed bundles with metadata
     *   - evaluator_errors[]: Rule evaluation failures
     * - $catalogue: Optional RuleCatalogue for rule descriptions
     *
     * OUTPUT:
     * Returns complete HTML5 document as string
     * Single-file, no external assets
     * CSS inlined in <style> tag
     * Ready for browser display or mPDF conversion
     *
     * EMPTY REPORT:
     * If no incidents: Shows "No issues detected" message
     * Suggests checking bundle completeness
     *
     * @param list<Incident> $incidents Analyzed incidents to report
     * @param array<string,mixed> $context Job metadata and configuration
     * @param ?RuleCatalogue $catalogue Rule catalogue for descriptions
     *
     * @return string Complete HTML5 document ready for display/export
     */
    public function render(array $incidents, array $context, ?RuleCatalogue $catalogue = null): string
    {
        $css  = $this->styles();
        $head = $this->headerBlock($context);

        // Hardware configuration section (moved to top of report)
        $hardware = $this->hardwareConfigurationBlock($context);

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

        $appendix = $this->appendixBlock($context, $incidents);

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
{$hardware}
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
        $rep  = htmlspecialchars((string)($c['report_version']    ?? ''),                    ENT_QUOTES);
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
    <div class="hdr-kv"><span>Report Version</span><code>{$rep}</code></div>
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
        $days = Engine::withinDays();
        $window = $days > 0
            ? "Showing events from the last {$days} days only. Older events are excluded from this report."
            : "Showing all events regardless of age (date filter disabled).";
        $window = htmlspecialchars($window, ENT_QUOTES);
        return <<<HTML
<section class="summary">
  <h2>Executive summary</h2>
  <div class="summary-row">
    <div class="summary-headline"><strong>{$total}</strong> {$totalLabel} identified</div>
    <div class="summary-chips">{$chips}</div>
  </div>
  <div class="summary-window">{$window}</div>
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
            // Show up to 5 citations when the finding aggregates multiple
            // occurrences, else up to 3 — matches RegexMatcher's cap.
            $maxCites = $f->occurrenceCount > 1 ? 5 : 3;

            // Derive first-seen / last-seen from this finding's citations.
            $tsList = [];
            foreach ($f->citations as $c) {
                $t = (string)($c['timestamp'] ?? '');
                if ($t === '') continue;
                $u = strtotime($t);
                if ($u !== false) $tsList[] = $u;
            }
            $firstSeen = $tsList !== [] ? date('Y-m-d', min($tsList)) : null;
            $lastSeen  = $tsList !== [] ? date('Y-m-d', max($tsList)) : null;

            $ruleLabel = htmlspecialchars($f->ruleId, ENT_QUOTES);
            if ($f->occurrenceCount > 1) {
                $ruleLabel .= ' <span class="occ-badge" title="Total matching events">×'
                            . (int)$f->occurrenceCount . '</span>';
                if ($firstSeen !== null && $lastSeen !== null && $firstSeen !== $lastSeen) {
                    $ruleLabel .= '<div class="occ-range">' .
                        htmlspecialchars($firstSeen, ENT_QUOTES) . ' → ' .
                        htmlspecialchars($lastSeen,  ENT_QUOTES) . '</div>';
                }
            }

            foreach (array_slice($f->citations, 0, $maxCites) as $c) {
                $file = htmlspecialchars(basename((string)($c['file'] ?? '')), ENT_QUOTES);
                $line = (int)($c['line_number'] ?? 0);
                $ts   = htmlspecialchars((string)($c['timestamp'] ?? ''), ENT_QUOTES);
                $ex   = htmlspecialchars(mb_substr((string)($c['excerpt'] ?? ''), 0, 260), ENT_QUOTES);
                $rows .= "<tr><td class=\"mono\">{$ruleLabel}</td><td class=\"mono\">{$file}:{$line}</td><td class=\"mono\">{$ts}</td><td>{$ex}</td></tr>";
                // Only label the first row per finding; subsequent rows blank
                // so the badge+range aren't repeated on every citation line.
                $ruleLabel = '';
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

    /**
     * Render hardware configuration block (displayed at top of report)
     * @param array<string,mixed> $c
     */
    private function hardwareConfigurationBlock(array $c): string
    {
        $hwBlock = '';
        foreach (($c['bundles'] ?? []) as $b) {
            $hw = $b['hardware_spec'] ?? null;
            if ($hw === null) continue;
            $hwBlock .= $this->renderHardwareSpecs($hw, $b['data_completeness'] ?? []);
        }

        if ($hwBlock === '') {
            return '';
        }

        return <<<HTML
<section class="hw-section">
  <h2>Hardware Configuration</h2>
  {$hwBlock}
</section>
HTML;
    }

    /** @param array<string,mixed> $c */
    /**
     * TIER 1: Volume and RAID detail tables
     */
    private function renderVolumeDetailsTable(object $hwSpec): string
    {
        $volumes = $hwSpec->volumes ?? [];
        if (empty($volumes)) {
            return '';
        }

        $rows = '';
        foreach ($volumes as $vol) {
            $name = htmlspecialchars((string)($vol['name'] ?? ''), ENT_QUOTES);
            $mount = htmlspecialchars((string)($vol['mount_point'] ?? ''), ENT_QUOTES);
            // Check both 'filesystem' and 'fs_type' field names
            $fsValue = $vol['filesystem'] ?? $vol['fs_type'] ?? 'unknown';
            $fs = htmlspecialchars((string)$fsValue, ENT_QUOTES);
            $total = (float)($vol['total_gb'] ?? 0);
            $used = (float)($vol['used_gb'] ?? 0);
            $avail = (float)($vol['available_gb'] ?? 0);
            $pct = (int)($vol['usage_percent'] ?? 0);

            $pctColor = match(true) {
                $pct >= 90 => '#dc2626',
                $pct >= 75 => '#ea580c',
                $pct >= 50 => '#ca8a04',
                default => '#16a34a',
            };

            $rows .= "<tr><td>{$name}</td><td class=\"mono\">{$mount}</td><td>{$fs}</td><td>{$total}</td><td>{$used}</td><td>{$avail}</td><td style=\"background-color:{$pctColor};color:white;font-weight:600\">{$pct}%</td></tr>";
        }

        return <<<HTML
<div class="apx-block">
  <h3>Storage Volumes - Detailed</h3>
  <table class="apx-table">
    <thead><tr><th>Volume</th><th>Mount Point</th><th>Filesystem</th><th>Total GB</th><th>Used GB</th><th>Available GB</th><th>Usage %</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</div>
HTML;
    }

    /**
     * TIER 1: RAID arrays detail table
     */
    private function renderRaidDetailsTable(object $hwSpec): string
    {
        $config = $hwSpec->raidConfig ?? [];
        $arrays = $config['arrays'] ?? [];
        if (empty($arrays)) {
            return '';
        }

        $rows = '';
        foreach ($arrays as $arr) {
            $name = htmlspecialchars((string)($arr['name'] ?? ''), ENT_QUOTES);
            $level = htmlspecialchars((string)($arr['level'] ?? ''), ENT_QUOTES);
            $state = htmlspecialchars((string)($arr['state'] ?? ''), ENT_QUOTES);
            $type = htmlspecialchars((string)($arr['type'] ?? ''), ENT_QUOTES);
            $members = (int)($arr['members'] ?? 0);
            $healthy = (int)($arr['healthy_members'] ?? 0);
            $missing = (int)($arr['missing_members'] ?? 0);
            $progress = $arr['rebuild_progress'] !== null ? round((float)($arr['rebuild_progress']), 1) : '-';
            $action = htmlspecialchars((string)($arr['sync_action'] ?? 'idle'), ENT_QUOTES);
            $devices = htmlspecialchars(implode(', ', (array)($arr['devices'] ?? [])), ENT_QUOTES);

            $stateColor = match($state) {
                'degraded' => '#dc2626',
                'recovering' => '#f59e0b',
                default => '#16a34a',
            };

            $rows .= "<tr><td><strong>{$name}</strong><br><small>{$type}</small></td><td>{$level}</td><td style=\"color:{$stateColor};font-weight:600\">{$state}</td><td>{$members}</td><td>{$healthy}</td><td>{$missing}</td><td>{$progress}%</td><td>{$action}</td><td class=\"mono\"><small>{$devices}</small></td></tr>";
        }

        return <<<HTML
<div class="apx-block">
  <h3>RAID Arrays - Detailed</h3>
  <table class="apx-table">
    <thead><tr><th>Array</th><th>Level</th><th>State</th><th>Members</th><th>Healthy</th><th>Missing</th><th>Rebuild %</th><th>Sync Action</th><th>Devices</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</div>
HTML;
    }

    /**
     * TIER 2: Citation index from findings
     * @param list<Incident> $incidents
     */
    private function renderCitationIndex(array $incidents): string
    {
        $citations = [];

        // Aggregate all citations from all findings in all incidents
        foreach ($incidents as $incident) {
            foreach ($incident->findings as $finding) {
                foreach ($finding->citations as $cite) {
                    $file = (string)($cite['file'] ?? 'unknown');
                    if (!isset($citations[$file])) {
                        $citations[$file] = [];
                    }
                    $citations[$file][] = [
                        'rule_id' => $finding->ruleId,
                        'line_number' => $cite['line_number'] ?? null,
                        'excerpt' => $cite['excerpt'] ?? null,
                    ];
                }
            }
        }

        if (empty($citations)) {
            return '';
        }

        ksort($citations); // Sort by filename

        $rows = '';
        foreach ($citations as $file => $cites) {
            $fileEsc = htmlspecialchars($file, ENT_QUOTES);
            $ruleRefs = [];
            $lineRefs = [];

            foreach ($cites as $c) {
                if (!in_array($c['rule_id'], $ruleRefs, true)) {
                    $ruleRefs[] = $c['rule_id'];
                }
                if ($c['line_number'] !== null && !in_array($c['line_number'], $lineRefs, true)) {
                    $lineRefs[] = $c['line_number'];
                }
            }

            sort($lineRefs);
            $ruleList = htmlspecialchars(implode(', ', $ruleRefs), ENT_QUOTES);
            $lineList = !empty($lineRefs) ? htmlspecialchars(implode(', ', array_slice($lineRefs, 0, 5)), ENT_QUOTES) : 'various';
            $lineExtra = count($lineRefs) > 5 ? ' (+' . (count($lineRefs) - 5) . ' more)' : '';

            $rows .= "<tr><td class=\"mono\">{$fileEsc}</td><td>{$ruleList}</td><td class=\"mono\"><small>{$lineList}{$lineExtra}</small></td></tr>";
        }

        return <<<HTML
<div class="apx-block">
  <h3>Evidence Citations Index</h3>
  <p>This table shows which evidence files contributed to rule findings.</p>
  <table class="apx-table">
    <thead><tr><th>Evidence File</th><th>Rules Referenced</th><th>Lines</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</div>
HTML;
    }

    /**
     * TIER 3: Event timeline from findings
     * @param list<Incident> $incidents
     */
    private function renderEventTimeline(array $incidents): string
    {
        $events = [];

        // Extract timestamped events from citations
        foreach ($incidents as $incident) {
            foreach ($incident->findings as $finding) {
                foreach ($finding->citations as $cite) {
                    if (!empty($cite['timestamp'])) {
                        $events[] = [
                            'timestamp' => $cite['timestamp'],
                            'rule_id' => $finding->ruleId,
                            'severity' => $finding->severity,
                            'file' => $cite['file'] ?? 'unknown',
                            'line' => $cite['line_number'] ?? null,
                            'excerpt' => $cite['excerpt'] ?? null,
                        ];
                    }
                }
            }
        }

        if (empty($events)) {
            return '';
        }

        // Sort by timestamp (newest first)
        usort($events, static fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']));

        $rows = '';
        $maxEvents = min(50, count($events)); // Show up to 50 most recent events
        for ($i = 0; $i < $maxEvents; $i++) {
            $evt = $events[$i];
            $ts = htmlspecialchars($evt['timestamp'], ENT_QUOTES);
            $rule = htmlspecialchars($evt['rule_id'], ENT_QUOTES);
            $severity = htmlspecialchars($evt['severity'], ENT_QUOTES);
            $file = htmlspecialchars($evt['file'], ENT_QUOTES);
            $line = $evt['line'] !== null ? htmlspecialchars((string)($evt['line']), ENT_QUOTES) : '—';

            $severityColor = match($evt['severity']) {
                'critical' => '#dc2626',
                'high' => '#ea580c',
                'warning' => '#f59e0b',
                default => '#06b6d4',
            };

            $rows .= "<tr><td class=\"mono\">{$ts}</td><td style=\"color:{$severityColor};font-weight:600\">{$severity}</td><td class=\"mono\">{$rule}</td><td class=\"mono\"><small>{$file}:{$line}</small></td></tr>";
        }

        $totalMsg = count($events) > $maxEvents ? " (showing {$maxEvents} of " . count($events) . ' events)' : '';

        return <<<HTML
<div class="apx-block">
  <h3>Event Timeline{$totalMsg}</h3>
  <p>Chronological view of log events that triggered rule matches (newest first).</p>
  <table class="apx-table">
    <thead><tr><th>Timestamp</th><th>Severity</th><th>Rule ID</th><th>File:Line</th></tr></thead>
    <tbody>{$rows}</tbody>
  </table>
</div>
HTML;
    }

    private function appendixBlock(array $c, array $incidents = []): string
    {
        // Bundle metadata
        $bundleRows = '';
        $hwSpecs = []; // Collect hardware specs from bundles for TIER 1

        foreach (($c['bundles'] ?? []) as $b) {
            $id    = htmlspecialchars((string)($b['debug_file_id'] ?? ''), ENT_QUOTES);
            $root  = htmlspecialchars((string)($b['root'] ?? ''), ENT_QUOTES);
            $nfi   = (int)($b['file_count'] ?? 0);
            $size  = htmlspecialchars($this->humanBytes((int)($b['size_bytes'] ?? 0)), ENT_QUOTES);
            $log   = htmlspecialchars(implode(', ', (array)($b['sources_log']    ?? [])), ENT_QUOTES);
            $sql   = htmlspecialchars(implode(', ', (array)($b['sources_sqlite'] ?? [])), ENT_QUOTES);
            $bundleRows .= "<tr><td class=\"mono\">{$id}</td><td class=\"mono\">{$root}</td><td>{$nfi}</td><td>{$size}</td><td class=\"mono\">{$log}</td><td class=\"mono\">{$sql}</td></tr>";

            // Collect hardware spec for TIER 1 tables
            if (isset($b['hardware_spec'])) {
                $hwSpecs[] = $b['hardware_spec'];
            }
        }
        $bundleRows = $bundleRows ?: '<tr><td colspan="6" class="muted">No bundle metadata recorded.</td></tr>';

        // Rule evaluation errors
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

        // === TIER 1: Volume and RAID detail tables ===
        $volumeDetails = '';
        $raidDetails = '';
        if (!empty($hwSpecs)) {
            $hwSpec = $hwSpecs[0]; // Use first bundle's hardware spec
            $volumeDetails = $this->renderVolumeDetailsTable($hwSpec);
            $raidDetails = $this->renderRaidDetailsTable($hwSpec);
        }

        // === TIER 2: Citation index ===
        $citationIndex = $this->renderCitationIndex($incidents);

        // === TIER 3: Event timeline ===
        $eventTimeline = $this->renderEventTimeline($incidents);

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
  {$volumeDetails}
  {$raidDetails}
  {$citationIndex}
  {$eventTimeline}
  {$errorBlock}
</section>
HTML;
    }

    /**
     * Render comprehensive hardware specifications block
     */
    private function renderHardwareSpecs(object $spec, array $completeness): string
    {
        $model     = htmlspecialchars((string)($spec->model ?? ''), ENT_QUOTES);
        $serial    = htmlspecialchars((string)($spec->serial ?? ''), ENT_QUOTES);
        $location  = htmlspecialchars((string)($spec->location ?? ''), ENT_QUOTES);
        $cpuModel  = htmlspecialchars((string)($spec->cpu['model'] ?? ''), ENT_QUOTES);
        $cpuCores  = (int)($spec->cpu['cores'] ?? 0);
        $ramTotal  = (float)($spec->ram['total_gb'] ?? 0);
        $ramAvail  = (float)($spec->ram['available_gb'] ?? 0);
        $uptime    = (float)($spec->uptime_days ?? 0);

        // Device card
        $deviceCard = <<<HTML
<div class="hw-device-card">
  <h4>NAS Device</h4>
  <div class="hw-spec-grid">
    <div class="hw-spec-item"><span class="hw-spec-label">Model:</span><span class="hw-spec-value">{$model}</span></div>
    <div class="hw-spec-item"><span class="hw-spec-label">Serial:</span><span class="hw-spec-value">{$serial}</span></div>
    <div class="hw-spec-item"><span class="hw-spec-label">CPU:</span><span class="hw-spec-value">{$cpuModel} ({$cpuCores} cores)</span></div>
    <div class="hw-spec-item"><span class="hw-spec-label">RAM:</span><span class="hw-spec-value">{$ramTotal} GB ({$ramAvail} GB available)</span></div>
    <div class="hw-spec-item"><span class="hw-spec-label">Uptime:</span><span class="hw-spec-value">{$uptime} days</span></div>
    {$this->renderCompletenessStatus($completeness)}
  </div>
</div>
HTML;

        // Drive bay info - now supports expansion units
        $mainBays = (int)($spec->driveBays['main_unit_bays'] ?? 0);
        $mainUsed = (int)($spec->driveBays['main_unit_used'] ?? 0);
        $expBays = (int)($spec->driveBays['expansion_unit_bays'] ?? 0);
        $expUsed = (int)($spec->driveBays['expansion_unit_used'] ?? 0);
        $bayTotal = (int)($spec->driveBays['total_bays'] ?? $mainBays + $expBays);
        $bayUsedTotal = (int)($spec->driveBays['total_used'] ?? $mainUsed + $expUsed);

        $driveTable = '';
        if ($mainBays > 0 || $expBays > 0) {
            $driveTable = "<h4>Storage Capacity</h4>";
            $driveTable .= "<div class=\"hw-spec-grid\">";
            $driveTable .= "<div class=\"hw-spec-item\"><span class=\"hw-spec-label\">Main Unit Bays:</span><span class=\"hw-spec-value\">{$mainUsed}/{$mainBays}</span></div>";
            if ($expBays > 0) {
                $driveTable .= "<div class=\"hw-spec-item\"><span class=\"hw-spec-label\">Expansion Bays:</span><span class=\"hw-spec-value\">{$expUsed}/{$expBays}</span></div>";
            }
            $driveTable .= "<div class=\"hw-spec-item\"><span class=\"hw-spec-label\">Total Capacity:</span><span class=\"hw-spec-value\">{$bayUsedTotal}/{$bayTotal} bays</span></div>";
            $driveTable .= "</div>";
        }

        // Bay layout diagrams (visual drive positions)
        $bayLayoutDiagrams = '';
        $drives = $spec->drives ?? [];
        if (!empty($drives)) {
            $bayLayoutDiagrams = $this->renderBayLayoutDiagrams($drives, $spec);
        }

        // Drive details table with location information
        $driveDetailsTable = '';
        $driveHistorySection = '';
        $badSectorGrowthSection = '';

        if (!empty($drives)) {
            $driveRows = '';
            $driveHistoryData = [];
            $badSectorData = [];

            foreach ($drives as $drive) {
                $bay = (int)($drive['bay'] ?? 0);
                $location = htmlspecialchars((string)($drive['location'] ?? 'main'), ENT_QUOTES);
                $device = htmlspecialchars((string)($drive['device'] ?? ''), ENT_QUOTES);
                $model = htmlspecialchars((string)($drive['model'] ?? 'Unknown'), ENT_QUOTES);
                $serial = htmlspecialchars((string)($drive['serial'] ?? ''), ENT_QUOTES);
                $capacity = (float)($drive['capacity_gb'] ?? 0);
                $smart = htmlspecialchars((string)($drive['smart_status'] ?? 'unknown'), ENT_QUOTES);
                $temp = (int)($drive['temperature_celsius'] ?? 0);
                $poh = (int)($drive['power_on_hours'] ?? 0);
                $isSsd = (bool)($drive['is_ssd'] ?? false);
                $badSectors = (int)($drive['bad_sectors'] ?? 0);
                $healthStatus = (string)($drive['health_status'] ?? '');
                $installDate = htmlspecialchars((string)($drive['installation_date'] ?? ''), ENT_QUOTES);
                $replacementCount = (int)($drive['replacement_count'] ?? 0);

                // Determine health badge based on SMART attributes and bad sector count
                if (!empty($healthStatus)) {
                    // New logic: use health_status from SMART analysis
                    $smartBadge = match($healthStatus) {
                        'healthy' => '<span class="hw-status-badge hw-status-healthy">✓ Healthy</span>',
                        'caution' => '<span class="hw-status-badge hw-status-warning">⚠ Monitor</span>',
                        'warning' => '<span class="hw-status-badge hw-status-warning">⚠ Replace Soon</span>',
                        'critical' => '<span class="hw-status-badge hw-status-critical">✕ Critical</span>',
                        default => match($smart) {
                            'passed', 'ok' => '<span class="hw-status-badge hw-status-healthy">✓ Healthy</span>',
                            'warning', 'failing' => '<span class="hw-status-badge hw-status-warning">⚠ Warning</span>',
                            'failed' => '<span class="hw-status-badge hw-status-critical">✕ Failed</span>',
                            default => htmlspecialchars($smart, ENT_QUOTES),
                        }
                    };
                    // Add bad sector information to status if present
                    if ($badSectors > 0 && $healthStatus !== 'healthy') {
                        $smartBadge .= '<div style="font-size: 11px; color: #666; margin-top: 2px;">(' .
                            htmlspecialchars((string)$badSectors, ENT_QUOTES) . ' sectors)</div>';
                    }
                } else {
                    // Fallback: original logic using smart_status
                    $smartBadge = match($smart) {
                        'passed', 'ok' => '<span class="hw-status-badge hw-status-healthy">✓ Healthy</span>',
                        'warning', 'failing' => '<span class="hw-status-badge hw-status-warning">⚠ Warning</span>',
                        'failed' => '<span class="hw-status-badge hw-status-critical">✕ Failed</span>',
                        default => htmlspecialchars($smart, ENT_QUOTES),
                    };
                }

                $typeLabel = $isSsd ? 'SSD' : 'HDD';
                $locationLabel = $location === 'main' ? 'Main' : preg_replace('/[^a-z0-9]/i', ' ', $location);

                // Collect history and growth data for separate sections
                if ($installDate || $replacementCount > 0) {
                    $driveHistoryData[] = [
                        'serial' => $serial,
                        'model' => $model,
                        'location' => $locationLabel,
                        'bay' => $bay,
                        'install_date' => $installDate,
                        'replacements' => $replacementCount,
                    ];
                }

                if ($badSectors > 0) {
                    $badSectorData[] = [
                        'serial' => $serial,
                        'model' => $model,
                        'location' => $locationLabel,
                        'bay' => $bay,
                        'sectors' => $badSectors,
                        'status' => $healthStatus,
                    ];
                }

                $driveRows .= "<tr><td><strong>{$bay}</strong><br><small class=\"hw-location\">{$locationLabel}</small></td><td class=\"mono\">{$device}</td><td>{$model}<br><small>{$typeLabel}</small></td><td class=\"mono\">{$serial}</td><td>{$capacity} GB</td><td>{$poh}h</td><td>{$temp}°C</td><td>{$smartBadge}</td></tr>";
            }

            // Add disconnected drives from history (drives that were used but are now removed)
            if (!empty($spec->driveHistory)) {
                $currentSerials = array_column($drives, 'serial');
                foreach ($spec->driveHistory as $histSerial => $histData) {
                    if (!in_array($histSerial, $currentSerials)) {
                        // This drive was seen before but is not currently plugged in
                        $firstSeen = $histData['first_seen'] ?? 'Unknown';
                        $lastSeen = $histData['last_seen'] ?? 'Unknown';
                        $driveHistoryData[] = [
                            'serial' => htmlspecialchars((string)$histSerial, ENT_QUOTES),
                            'model' => 'Disconnected',  // We don't have the model for removed drives
                            'location' => 'Removed',
                            'bay' => 0,
                            'install_date' => htmlspecialchars((string)$firstSeen, ENT_QUOTES),
                            'replacements' => 0,
                            'last_seen' => htmlspecialchars((string)$lastSeen, ENT_QUOTES),
                        ];
                    }
                }
            }

            $driveDetailsTable = <<<HTML
<h4>Drives</h4>
<table class="hw-drive-table">
  <thead><tr><th>Bay</th><th>Device</th><th>Model</th><th>Serial</th><th>Capacity</th><th>Hours</th><th>Temp</th><th>Status</th></tr></thead>
  <tbody>{$driveRows}</tbody>
</table>
HTML;

            // Build drive history section
            if (!empty($driveHistoryData)) {
                $historyRows = '';
                foreach ($driveHistoryData as $hist) {
                    $histSerial = $hist['serial'];  // Already escaped
                    $histModel = $hist['model'];     // Already escaped
                    $histLocation = $hist['location']; // Already escaped
                    $histBay = (int)$hist['bay'];
                    $histDate = $hist['install_date']; // Already escaped
                    $histReplacements = (int)$hist['replacements'];
                    $lastSeen = $hist['last_seen'] ?? '';

                    $replacementBadge = '';
                    // Mark disconnected drives
                    if ($histLocation === 'Removed') {
                        $replacementBadge = "<span class=\"hw-status-badge\" style=\"background:#e5e7eb;color:#374151;\">⊘ Disconnected</span>";
                        if (!empty($lastSeen)) {
                            $replacementBadge .= "<br><small style=\"color:#666;\">Last: {$lastSeen}</small>";
                        }
                    } elseif ($histReplacements > 0) {
                        $badgeClass = $histReplacements >= 3 ? 'hw-status-critical' : 'hw-status-warning';
                        $replacementBadge = "<span class=\"hw-status-badge {$badgeClass}\" style=\"font-size:11px;padding:2px 6px;\">{$histReplacements} replaced</span>";
                    }

                    $bayDisplay = $histBay > 0 ? "{$histBay}" : '—';
                    $historyRows .= "<tr><td>{$bayDisplay}</td><td>{$histLocation}</td><td class=\"mono\">{$histSerial}</td><td>{$histModel}</td><td>{$histDate}</td><td>{$replacementBadge}</td></tr>";
                }
                $driveHistorySection = <<<HTML
<h4>Drive Installation & Replacement History</h4>
<table class="hw-drive-table">
  <thead><tr><th>Bay</th><th>Location</th><th>Serial</th><th>Model</th><th>Installation Date</th><th>Replacement History</th></tr></thead>
  <tbody>{$historyRows}</tbody>
</table>
HTML;
            }

            // Build bad sector growth section
            if (!empty($badSectorData)) {
                $growthRows = '';
                foreach ($badSectorData as $growth) {
                    $growthSerial = htmlspecialchars($growth['serial'], ENT_QUOTES);
                    $growthModel = htmlspecialchars($growth['model'], ENT_QUOTES);
                    $growthLocation = htmlspecialchars($growth['location'], ENT_QUOTES);
                    $growthBay = (int)$growth['bay'];
                    $growthSectors = (int)$growth['sectors'];
                    $growthStatus = htmlspecialchars($growth['status'], ENT_QUOTES);

                    $statusBadge = match($growthStatus) {
                        'caution' => '<span class="hw-status-badge" style="background:#fef3c7;color:#92400e;">⚠ Caution</span>',
                        'warning' => '<span class="hw-status-badge hw-status-warning">⚠ Warning</span>',
                        'critical' => '<span class="hw-status-badge hw-status-critical">✕ Critical</span>',
                        default => htmlspecialchars($growthStatus, ENT_QUOTES),
                    };
                    $growthRows .= "<tr><td>{$growthBay}</td><td>{$growthLocation}</td><td class=\"mono\">{$growthSerial}</td><td>{$growthModel}</td><td>{$growthSectors}</td><td>{$statusBadge}</td></tr>";
                }
                $driveHistorySection .= <<<HTML

<h4>Bad Sector Analysis</h4>
<table class="hw-drive-table">
  <thead><tr><th>Bay</th><th>Location</th><th>Serial</th><th>Model</th><th>Bad Sectors</th><th>Health Status</th></tr></thead>
  <tbody>{$growthRows}</tbody>
</table>
HTML;
            }
        }

        // Main unit information section
        $mainUnitTable = '';
        $mainUnitModel = htmlspecialchars((string)($spec->model ?? 'Unknown'), ENT_QUOTES);
        $mainUnitSerial = htmlspecialchars((string)($spec->serial ?? ''), ENT_QUOTES);
        $mainUnitBayCount = (int)($spec->driveBays['main_unit_bays'] ?? 0);
        $mainUnitUsedCount = (int)($spec->driveBays['main_unit_used'] ?? 0);

        // Count main unit drives with bay references (not serials)
        $mainUnitDrives = [];
        if (!empty($drives)) {
            foreach ($drives as $drive) {
                if (($drive['location'] ?? 'Main') === 'Main') {
                    $bay = (int)($drive['bay'] ?? 0);
                    if ($bay > 0) {
                        $mainUnitDrives[] = "Bay " . $bay;
                    }
                }
            }
        }
        $mainDrivesList = implode(', ', $mainUnitDrives);

        // Main unit status is always Active if it has bays
        $mainStatusBadge = '<span class="hw-status-badge hw-status-healthy">✓ Active</span>';

        $mainUnitTable = <<<HTML
<h4>Main Unit</h4>
<table class="hw-expansion-table">
  <thead><tr><th>Model</th><th>Serial</th><th>Bays</th><th>Status</th><th>Drives</th></tr></thead>
  <tbody><tr><td>{$mainUnitModel}</td><td class="mono">{$mainUnitSerial}</td><td>{$mainUnitUsedCount}/{$mainUnitBayCount}</td><td>{$mainStatusBadge}</td><td class="mono" style="font-size:11px;max-width:200px;word-break:break-all">{$mainDrivesList}</td></tr></tbody>
</table>
HTML;

        // Expansion units section
        $expansionTable = '';
        $expansions = $spec->expansion ?? [];
        if (!empty($expansions)) {
            $expRows = '';
            foreach ($expansions as $exp) {
                $encId = htmlspecialchars((string)($exp['enclosure_id'] ?? ''), ENT_QUOTES);
                $expModel = htmlspecialchars((string)($exp['model'] ?? 'Unknown'), ENT_QUOTES);
                $expSerial = htmlspecialchars((string)($exp['serial'] ?? ''), ENT_QUOTES);
                $expBayCount = (int)($exp['bay_count'] ?? 0);
                $expUsedCount = (int)($exp['installed_drives'] ?? 0);
                $expStatus = htmlspecialchars((string)($exp['status'] ?? 'unknown'), ENT_QUOTES);
                $expPower = htmlspecialchars((string)($exp['power_status'] ?? 'unknown'), ENT_QUOTES);
                $drivesList = implode(', ', array_map('htmlspecialchars', (array)($exp['drives'] ?? [])));

                $statusBadge = match($expStatus) {
                    'active', 'ok' => '<span class="hw-status-badge hw-status-healthy">✓ Active</span>',
                    'standby' => '<span class="hw-status-badge" style="background:#bfdbfe;color:#0369a1;">⏸ Standby</span>',
                    'error', 'failed' => '<span class="hw-status-badge hw-status-warning">⚠ Error</span>',
                    'detected', 'capable' => '<span class="hw-status-badge" style="background:#dbeafe;color:#0284c7;">◎ Detected</span>',
                    default => htmlspecialchars($expStatus, ENT_QUOTES),
                };

                $expRows .= "<tr><td>{$encId}</td><td>{$expModel}</td><td class=\"mono\">{$expSerial}</td><td>{$expUsedCount}/{$expBayCount}</td><td>{$statusBadge}</td><td class=\"mono\" style=\"font-size:11px;max-width:200px;word-break:break-all\">{$drivesList}</td></tr>";
            }
            $expansionTable = <<<HTML
<h4>Expansion Units</h4>
<table class="hw-expansion-table">
  <thead><tr><th>Enclosure ID</th><th>Model</th><th>Serial</th><th>Bays</th><th>Status</th><th>Drives</th></tr></thead>
  <tbody>{$expRows}</tbody>
</table>
HTML;
        }

        // RAID configuration table
        $raidTable = '';
        $arrays = $spec->raidConfig['arrays'] ?? [];
        if (!empty($arrays)) {
            $raidRows = '';
            foreach ($arrays as $arr) {
                $name = htmlspecialchars((string)($arr['name'] ?? ''), ENT_QUOTES);
                $state = htmlspecialchars((string)($arr['state'] ?? ''), ENT_QUOTES);
                $level = htmlspecialchars((string)($arr['level'] ?? ''), ENT_QUOTES);
                $members = (int)($arr['members'] ?? 0);
                $healthy = (int)($arr['healthy_members'] ?? 0);
                $missing = (int)($arr['missing_members'] ?? 0);
                $progress = $arr['rebuild_progress'] ?? null;

                $stateBadge = match($state) {
                    'active' => '<span class="hw-status-badge hw-status-healthy">Active</span>',
                    'degraded' => '<span class="hw-status-badge hw-status-warning">Degraded</span>',
                    'recovering', 'resync' => '<span class="hw-status-badge" style="background:#dbeafe;color:#0369a1;">↻ Rebuilding</span>',
                    default => htmlspecialchars($state, ENT_QUOTES),
                };

                $progressStr = $progress !== null ? sprintf(' (%.1f%%)', $progress) : '';
                $raidRows .= "<tr><td>{$name}</td><td>{$level}</td><td>{$members}</td><td>{$healthy}</td><td>{$missing}</td><td>{$stateBadge} {$progressStr}</td></tr>";
            }
            $raidTable = <<<HTML
<h4>RAID Arrays</h4>
<table class="hw-raid-table">
  <thead><tr><th>Array</th><th>Level</th><th>Members</th><th>Healthy</th><th>Missing</th><th>State</th></tr></thead>
  <tbody>{$raidRows}</tbody>
</table>
HTML;
        }

        // Volumes table
        $volumeTable = '';
        $volumes = $spec->volumes ?? [];
        if (!empty($volumes)) {
            $volRows = '';
            foreach ($volumes as $vol) {
                $name = htmlspecialchars((string)($vol['name'] ?? ''), ENT_QUOTES);
                $mount = htmlspecialchars((string)($vol['mount_point'] ?? ''), ENT_QUOTES);
                $total = (float)($vol['total_gb'] ?? 0);
                $used = (float)($vol['used_gb'] ?? 0);
                $pct = (int)($vol['usage_percent'] ?? 0);

                $pctColor = match(true) {
                    $pct >= 90 => '#f87171',
                    $pct >= 75 => '#fb923c',
                    default => '#86efac',
                };

                $volRows .= "<tr><td>{$name}</td><td class=\"mono\">{$mount}</td><td>{$total} GB</td><td>{$used} GB</td><td><div style=\"width:100%;height:16px;background:#f3f4f6;border-radius:2px;overflow:hidden\"><div style=\"width:{$pct}%;height:100%;background:{$pctColor};display:flex;align-items:center;justify-content:center;font-size:10px;color:white;font-weight:600\">{$pct}%</div></div></td></tr>";
            }
            $volumeTable = <<<HTML
<h4>Volumes</h4>
<table class="hw-volume-table">
  <thead><tr><th>Name</th><th>Mount</th><th>Total</th><th>Used</th><th>Usage</th></tr></thead>
  <tbody>{$volRows}</tbody>
</table>
HTML;
        }

        // RAID failure analysis section
        $failureAnalysisTable = '';
        $failures = $spec->failures ?? [];
        if (!empty($failures)) {
            $failureRows = '';
            $failuresFound = false;

            foreach ($failures as $device => $failure) {
                // Only show drives with failure history or current failures
                if (empty($failure['failure_history']) && !$failure['is_currently_failed']) {
                    continue;
                }
                $failuresFound = true;

                $device = htmlspecialchars((string)($device ?? ''), ENT_QUOTES);
                $bay = htmlspecialchars((string)($failure['bay'] ?? ''), ENT_QUOTES);
                $location = htmlspecialchars((string)($failure['location'] ?? 'main'), ENT_QUOTES);
                $model = htmlspecialchars((string)($failure['model'] ?? ''), ENT_QUOTES);
                $serial = htmlspecialchars((string)($failure['current_serial'] ?? ''), ENT_QUOTES);
                $classification = htmlspecialchars((string)($failure['failure_classification'] ?? ''), ENT_QUOTES);
                $detail = htmlspecialchars((string)($failure['status_detail'] ?? ''), ENT_QUOTES);

                // Determine badge color
                $badgeColor = match($classification) {
                    'currently_failed' => '<span class="hw-status-badge hw-status-critical">✕ Currently Failed</span>',
                    'replaced_after_failure' => '<span class="hw-status-badge hw-status-warning">⚠ Replaced After Failure</span>',
                    'historically_failed_now_operational' => '<span class="hw-status-badge" style="background:#fcd34d;color:#78350f;">◈ Recovered</span>',
                    'current_failure_no_log_record' => '<span class="hw-status-badge hw-status-warning">⚠ Unrecorded Failure</span>',
                    default => htmlspecialchars($classification, ENT_QUOTES),
                };

                $failureRows .= "<tr><td><strong>{$bay}</strong><br><small class=\"hw-location\">{$location}</small></td><td class=\"mono\">{$device}</td><td>{$model}</td><td class=\"mono\">{$serial}</td><td>{$badgeColor}<br><small>{$detail}</small></td></tr>";
            }

            if ($failuresFound) {
                $failureAnalysisTable = <<<HTML
<h4>Drive Failure Analysis</h4>
<table class="hw-failure-table">
  <thead><tr><th>Bay</th><th>Device</th><th>Model</th><th>Serial</th><th>Status & Details</th></tr></thead>
  <tbody>{$failureRows}</tbody>
</table>
HTML;
            }
        }

        // Failure pattern analysis section
        $failurePatternTable = '';
        $patterns = $spec->failurePatterns ?? [];
        if (!empty($patterns)) {
            $patternRows = '';
            foreach ($patterns as $arrayName => $pattern) {
                $arrayName = htmlspecialchars((string)($arrayName ?? ''), ENT_QUOTES);
                $patternType = htmlspecialchars((string)($pattern['pattern_type'] ?? ''), ENT_QUOTES);
                $deviceCount = (int)($pattern['device_count'] ?? 0);
                $totalFailures = (int)($pattern['total_failures'] ?? 0);
                $cause = htmlspecialchars((string)($pattern['presumed_cause'] ?? ''), ENT_QUOTES);
                $devices = htmlspecialchars(implode(', ', (array)($pattern['affected_devices'] ?? [])), ENT_QUOTES);

                // Determine pattern badge
                $patternBadge = match($patternType) {
                    'systemic' => '<span class="hw-status-badge hw-status-critical">⚡ Systemic</span>',
                    'staggered' => '<span class="hw-status-badge hw-status-warning">⚠ Staggered</span>',
                    'single_device' => '<span class="hw-status-badge" style="background:#bfdbfe;color:#0369a1;">◎ Single</span>',
                    default => htmlspecialchars($patternType, ENT_QUOTES),
                };

                $patternRows .= "<tr><td>{$arrayName}</td><td>{$patternBadge}</td><td>{$deviceCount}</td><td>{$totalFailures}</td><td>{$cause}<br><small class=\"mono\">{$devices}</small></td></tr>";
            }

            $failurePatternTable = <<<HTML
<h4>Failure Patterns</h4>
<table class="hw-pattern-table">
  <thead><tr><th>Array</th><th>Pattern Type</th><th>Devices Affected</th><th>Total Failures</th><th>Presumed Cause & Devices</th></tr></thead>
  <tbody>{$patternRows}</tbody>
</table>
HTML;
        }

        return <<<HTML
<div class="apx-block">
  <h3>System Configuration</h3>
  {$deviceCard}
  {$mainUnitTable}
  {$expansionTable}
  {$driveTable}
  {$bayLayoutDiagrams}
  {$driveDetailsTable}
  {$driveHistorySection}
  {$raidTable}
  {$failureAnalysisTable}
  {$failurePatternTable}
  {$volumeTable}
</div>
HTML;
    }

    /**
     * Render data completeness status indicator
     */
    private function renderCompletenessStatus(array $completeness): string
    {
        $score = (int)($completeness['hardware_score'] ?? 0);
        $assessment = htmlspecialchars((string)($completeness['hardware_assessment'] ?? 'Unknown'), ENT_QUOTES);

        $color = match(true) {
            $score === 100 => '#10b981',
            $score >= 80 => '#f59e0b',
            $score >= 50 => '#ef4444',
            default => '#6b7280',
        };

        return <<<HTML
<div class="hw-spec-item">
  <span class="hw-spec-label">Data Completeness:</span>
  <span class="hw-spec-value" style="color:{$color};font-weight:500" title="{$assessment}">{$score}%</span>
</div>
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
.summary-window{margin-top:10px;font-size:12px;color:#6b7280;font-style:italic}
.occ-badge{display:inline-block;background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:700;margin-left:6px;font-family:-apple-system,BlinkMacSystemFont,system-ui,sans-serif}
.occ-range{font-family:-apple-system,BlinkMacSystemFont,system-ui,sans-serif;font-size:10px;color:#6b7280;margin-top:2px;font-weight:normal}
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
.hw-device-card{background:#f0f9ff;border-left:4px solid #0369a1;padding:14px;margin-bottom:16px;border-radius:6px}
.hw-device-card h4{margin-top:0;margin-bottom:10px;color:#0369a1}
.hw-spec-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;font-size:13px}
.hw-spec-item{display:flex;justify-content:space-between;padding:6px;background:#fff;border-radius:4px;border:1px solid #dbeafe}
.hw-spec-label{font-weight:500;color:#0369a1}
.hw-spec-value{color:#1e293b;text-align:right;font-weight:600}
.hw-drive-table,.hw-raid-table,.hw-volume-table,.hw-expansion-table,.hw-failure-table,.hw-pattern-table{width:100%;border-collapse:collapse;margin:10px 0;font-size:12px}
.hw-drive-table th,.hw-raid-table th,.hw-volume-table th,.hw-expansion-table th,.hw-failure-table th,.hw-pattern-table th{background:#f3f4f6;padding:8px;text-align:left;border-bottom:2px solid #e5e7eb;font-weight:600;font-size:11px;color:#4b5563;text-transform:uppercase;letter-spacing:.03em}
.hw-drive-table td,.hw-raid-table td,.hw-volume-table td,.hw-expansion-table td,.hw-failure-table td,.hw-pattern-table td{padding:8px;border-bottom:1px solid #f3f4f6}
.hw-location{display:block;color:#6b7280;font-size:10px;margin-top:2px}
.hw-status-badge{display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600}
.hw-status-healthy{background:#d1fae5;color:#047857}
.hw-status-warning{background:#fef3c7;color:#b45309}
.hw-status-critical{background:#fee2e2;color:#dc2626}
.bay-layout-container{margin:20px 0;padding:15px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0}
.bay-layout-legend{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-top:20px;padding:15px;background:#fff;border-radius:8px;border:1px solid #e2e8f0}
.bay-legend-item{display:flex;gap:12px;align-items:flex-start;padding:10px;background:#f9fafb;border-radius:6px;border:1px solid #f1f5f9;transition:all .2s ease}
.bay-legend-item:hover{background:#f1f5f9;border-color:#e2e8f0;box-shadow:0 2px 8px rgba(0,0,0,.05)}
.bay-legend-color{width:40px;height:40px;border-radius:6px;flex-shrink:0;box-shadow:0 2px 4px rgba(0,0,0,.1)}
.bay-legend-item div:last-child{flex:1}
.bay-legend-item strong{display:block;color:#1e293b;font-size:13px;margin-bottom:2px}
.bay-legend-item p{margin:0;color:#64748b;font-size:11px;line-height:1.4}
CSS;
    }

    /**
     * Render bay layout diagrams for all storage units
     */
    private function renderBayLayoutDiagrams(array $drives, object $spec): string
    {
        if (empty($drives)) {
            return '';
        }

        $renderer = new BayLayoutRenderer();

        // Group drives by location/container
        $drivesByLocation = [];

        foreach ($drives as $drive) {
            $location = $drive['location'] ?? 'Unknown';
            if (!isset($drivesByLocation[$location])) {
                $drivesByLocation[$location] = [];
            }
            $drivesByLocation[$location][] = $drive;
        }

        // Render diagrams
        $html = '<h4>Drive Bay Layout</h4>';
        $html .= '<div class="bay-layout-container" style="margin: 15px 0;">';

        // Render Main unit first - use ACTUAL bay count from hardware spec, not assumptions
        if (isset($drivesByLocation['Main'])) {
            $mainModel = htmlspecialchars((string)($spec->model ?? 'NAS Device'), ENT_QUOTES);
            // Use actual bay count from hardware detection (load_info, synoinfo, etc)
            $mainTotalBays = (int)($spec->driveBays['main_unit_bays'] ?? 0);
            if ($mainTotalBays <= 0) {
                // Fallback: count actual drives if hardware bay count unavailable
                $mainTotalBays = max(count($drivesByLocation['Main'] ?? []), 1);
            }
            $location = "Main Unit ({$mainModel})";
            $locationDrivesToSort = $drivesByLocation['Main'];
            $html .= $renderer->renderBayLayout($location, $locationDrivesToSort, $mainTotalBays);
        }

        // Render expansion units - use ACTUAL bay counts from hardware spec
        foreach ($drivesByLocation as $location => $locationDrives) {
            if ($location === 'Main') {
                continue; // Already rendered
            }

            // For expansion units, find matching unit in spec->expansion by model name
            // and use its actual bay_count from hardware detection
            $totalBays = 0;
            if (!empty($spec->expansion)) {
                foreach ($spec->expansion as $expansionUnit) {
                    if (isset($expansionUnit['model']) && $expansionUnit['model'] === $location) {
                        $totalBays = (int)($expansionUnit['bay_count'] ?? 0);
                        break;
                    }
                }
            }
            // Fall back to count of drives if metadata unavailable
            if ($totalBays <= 0) {
                $totalBays = max(count($locationDrives ?? []), 1);
            }
            $displayName = $location;
            $html .= $renderer->renderBayLayout($displayName, $locationDrives, $totalBays);
        }

        $html .= '</div>';

        // Modern legend with better visual design
        $html .= '<div class="bay-layout-legend">';
        $html .= '<div class="bay-legend-item"><div class="bay-legend-color" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);"></div><div><strong>Healthy</strong><p>Operational, 0 bad sectors</p></div></div>';
        $html .= '<div class="bay-legend-item"><div class="bay-legend-color" style="background: linear-gradient(135deg, #fcd34d 0%, #f59e0b 100%);"></div><div><strong>Caution</strong><p>Minor issues, 10-50 sectors</p></div></div>';
        $html .= '<div class="bay-legend-item"><div class="bay-legend-color" style="background: linear-gradient(135deg, #fb923c 0%, #ea580c 100%);"></div><div><strong>Warning</strong><p>Moderate issues, 50+ sectors</p></div></div>';
        $html .= '<div class="bay-legend-item"><div class="bay-legend-color" style="background: linear-gradient(135deg, #f87171 0%, #dc2626 100%);"></div><div><strong>Critical</strong><p>Failed or 100+ sectors</p></div></div>';
        $html .= '</div>';

        return $html;
    }
}
