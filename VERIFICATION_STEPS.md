# How to Verify the Historical Section PDF Fix

## Overview

You reported: "PDF edition and standard view reporting structures are completely different — the historical section doesn't appear in PDFs."

**Root cause:** mPDF doesn't support CSS Grid or Flexbox.  
**Fix:** Added inline-block fallback styles for mPDF compatibility.

## Verification Checklist

### ✅ Pre-Flight Check

1. **Confirm the fix is deployed**
   ```bash
   grep "display:inline-block;width:calc(50%" src/DeepDive/Report/ReportRenderer.php
   ```
   Should see: `.trend-card{display:inline-block;width:calc(50% - 8px);...`

2. **Verify database has baseline profiles**
   ```sql
   SELECT * FROM nas_baseline_profiles 
   WHERE tenant_id = 'your-tenant-id' 
   LIMIT 5;
   ```
   Should show entries like: `memory.used_percent`, `network.total_errors`, etc.

### 🔍 HTML Report Test

1. **Generate a new report**
   - Run the pipeline with a test bundle containing power/thermal data
   - Get the HTML report path from the output

2. **Open in browser**
   - Open the HTML file
   - Scroll to "Historical Analysis & Trends" section
   - Check responsiveness by resizing window
   - Verify:
     - ✓ Section header "Historical Analysis & Trends" appears
     - ✓ Recurring issues table (if any exist)
     - ✓ Metric trends cards display in responsive grid
     - ✓ Forecast cards display in responsive grid
     - ✓ Before/after comparison cards (if applicable)
     - ✓ Cards wrap properly when window is narrow

3. **Browser developer tools**
   - Open DevTools → Inspect element on a card
   - Check computed styles: should show `display: grid` (from .trend-cards container)
   - Grid layout should be active (Chrome shows grid overlay)

### 📄 PDF Report Test

1. **Generate the same report as PDF**
   - Pipeline generates both HTML and PDF
   - Check the PDF file exists and has size > 100KB

2. **Open PDF in viewer** (Adobe Reader, Preview, etc.)
   - Scroll through pages
   - Look for "Historical Analysis & Trends" heading
   - Check if it appears between "AI Anomaly & Root Cause Analysis" and incident sections

3. **Expected content visibility**

   ✓ **Recurring Issues** (if any):
   ```
   ┌─────────────────────────────────────────────────────────┐
   │ RECURRING ISSUES                                        │
   ├──────────┬──────────────┬──────────┬──────────┬─────────┤
   │Component │ Issue Type   │ Occurs   │ Severity │  ...    │
   ├──────────┼──────────────┼──────────┼──────────┼─────────┤
   │    eth0  │ network      │    3x    │ warning  │  ...    │
   │   md0    │ raid         │    2x    │ alarm    │  ...    │
   └──────────┴──────────────┴──────────┴──────────┴─────────┘
   ```

   ✓ **Metric Trends** (2-column card layout):
   ```
   ┌──────────────────────┐ ┌──────────────────────┐
   │ memory.used_percent  │ │ network.total_errors │
   │ 📈 increasing        │ │ 📈 increasing        │
   │ Current: 78.5%       │ │ Current: 42          │
   │ Change: +12.3%       │ │ Change: +5.2%        │
   └──────────────────────┘ └──────────────────────┘
   
   ┌──────────────────────┐ ┌──────────────────────┐
   │ thermal.cpu_temp...  │ │ raid.rebuild_progress│
   │ ➡️ stable            │ │ ➡️ stable            │
   │ Current: 65°C        │ │ Current: 0%          │
   │ Change: -2.1°C       │ │ Change: 0%           │
   └──────────────────────┘ └──────────────────────┘
   ```

   ✓ **Forecasts** (2-column card layout):
   ```
   ┌──────────────────────┐ ┌──────────────────────┐
   │ memory.used_percent  │ │ storage.used_percent │
   │ Status: increasing   │ │ Status: increasing   │
   │ Threshold: 95%       │ │ Threshold: 95%       │
   │ Will exceed in 45 d. │ │ Will exceed in 120 d.│
   │ Projected: 2026-06.. │ │ Projected: 2026-09..│
   └──────────────────────┘ └──────────────────────┘
   ```

4. **Text extraction test**
   ```bash
   pdftotext report.pdf - | grep -i "historical\|trends\|forecast"
   ```
   Should show:
   - "Historical Analysis & Trends"
   - "Recurring Issues" (if any)
   - "Metric Trends"
   - "Forecasts"
   - "Before/After Analysis" (if applicable)

### 🐛 Debug Checklist (if section still missing)

1. **Check if historicalData is being populated**
   - Add logging to RenderStep.php line 150:
     ```php
     $ctx->logger->info('Historical data: ' . json_encode($historicalData));
     ```
   - Run pipeline and check logs
   - Should show non-empty array with keys: recurring_issues, trends, forecasts

2. **Check if metrics are being extracted**
   - Add logging to MetricsExtractor.php line 89:
     ```php
     $this->log('debug', 'Extracted ' . count($metrics) . ' metrics');
     ```
   - Should show > 0 metrics extracted

3. **Check if baselines are being initialized**
   ```sql
   SELECT COUNT(*) FROM nas_baseline_profiles 
   WHERE tenant_id = 'your-tenant-id';
   ```
   - Should show > 0 if metrics were extracted

4. **Check HTML includes the section**
   ```bash
   grep -c "historical-analysis" report.html
   ```
   - Should be 1 or more

5. **Check if mPDF is stripping content**
   - Compare page counts: HTML vs PDF
   - If PDF is much shorter, content is being dropped

### 📊 Data Requirements

For the historical section to be useful:

- **At least 2 data points** per metric (for trend analysis)
- **Multiple occurrences** of the same issue (for recurring issues table)
- **Valid baselines** (auto-created if missing)
- **Anomalies detected** against baselines (isAnomaly() returns true)

If your test data has 66% completeness, make sure critical files are present:
- `/proc/meminfo` — for memory metrics
- `/proc/net/dev` — for network metrics
- `/proc/mdstat` — for RAID metrics
- Syslog entries — for thermal/service metrics

## Success Criteria

✅ **HTML Report:**
- Historical Analysis & Trends section visible
- Cards render in responsive grid
- Tables display properly
- Content is clickable and interactive

✅ **PDF Report:**
- Historical Analysis & Trends section visible (2-column layout)
- Cards render as inline-block layout
- Tables display with proper borders and alignment
- Text is extractable (pdftotext works)
- No garbled or overlapping content

✅ **Cross-Format Consistency:**
- Same data displayed in both HTML and PDF
- Different layouts (grid vs inline-block) but content identical
- No data loss between formats

## Expected Page Structure

**Before fix (PDF broken):**
```
1. Header
2. Hardware
3. Executive Summary
4. AI Anomaly Analysis
5. [MISSING: Historical Analysis]
6. Incidents
7. Appendix
```

**After fix (PDF working):**
```
1. Header
2. Hardware
3. Executive Summary
4. AI Anomaly Analysis
5. ✅ Historical Analysis & Trends
   - Recurring Issues table
   - Metric Trends (2-col cards)
   - Forecasts (2-col cards)
   - Before/After (2-col cards)
6. Incidents
7. Appendix
```

## Commands for Quick Testing

```bash
# Verify CSS fix is present
grep "display:inline-block" src/DeepDive/Report/ReportRenderer.php

# Check for historical data in HTML
grep "historical-analysis" /path/to/report.html

# Check for historical data in PDF
pdftotext /path/to/report.pdf - | grep -A 5 "Historical Analysis"

# Compare file sizes (HTML should include historical content)
ls -lh /path/to/report.{html,pdf}

# Extract all sections from PDF
pdftotext /path/to/report.pdf - | grep -E "^[A-Z][A-Z ]+$"
```

## Common Issues & Solutions

| Issue | Symptom | Fix |
|-------|---------|-----|
| Section still missing from PDF | No "Historical Analysis" heading | Check if `historicalData` is empty (see debug checklist) |
| Cards overlapping in PDF | Text runs together | Width calculation off — may indicate mPDF version issue |
| Only 1 column rendering | Cards stack vertically | Check `:nth-child(2n)` is being applied |
| Content cut off at margins | Text disappears at page edges | Check PDF margins (14-16pt in PdfExporter) |
| Missing table borders | Recurring issues table looks plain | mPDF table styling works but may be minimal |

## Next Steps After Verification

1. ✅ Confirm historical section renders in both HTML and PDF
2. 🔍 If section still missing: debug data extraction (check MetricsExtractor logs)
3. 🧪 Test with multiple bundles to ensure consistency
4. 📝 Document any remaining issues for follow-up

---

**Questions?**
- Check `HISTORICAL_SECTION_PDF_FIX.md` for detailed technical explanation
- Check `CSS_CHANGES_SUMMARY.txt` for exact CSS modifications
- Check `QUICK_REFERENCE.md` for quick overview

