# AI Root Cause Analysis - Implementation Framework

**Status**: Ready for Phase 1 Development
**Estimated Timeline**: 8-12 weeks total (4 phases)

---

## Quick Reference: What This Adds

```
Current System (Rules-Based):
  Input: Debug bundle
  Process: Match against rules
  Output: "Found RAID degradation" (if rule matches)
  Unknown Issues: Missed silently

WITH AI Enhancement:
  Input: Debug bundle
  Process: 1) Match rules 2) Analyze anomalies 3) Find correlations
  Output: "Found RAID degradation" + "Likely caused by power sag
           during heavy I/O (confidence 82%)"
  Unknown Issues: Detected and analyzed
```

---

## Phase 1: Anomaly Detection (2-4 weeks)

### What Gets Built

```
AnomalyDetector
├─ Statistical baseline learning
├─ Deviation detection
└─ Event clustering
```

### Key Files to Create

```
src/AI/
├── AnomalyDetector.php
│   ├─ buildBaseline(events) → statistical model
│   ├─ findAnomalies(events) → deviations >2σ
│   ├─ clusterAnomalies(events) → group related
│   └─ scoreAnomaly(event) → 0.0-1.0
│
└── TimeSeriesAnalyzer.php
    ├─ extractTimeSeries(logs) → [timestamp, value]
    ├─ smoothData(series) → remove noise
    ├─ detectTrends(series) → increasing/decreasing
    └─ findSeasonality(series) → daily/weekly patterns
```

### Code Example: Phase 1

```php
<?php

namespace App\AI;

class AnomalyDetector
{
    /**
     * Build baseline statistics from normal system behavior
     */
    public function buildBaseline(array $events): array
    {
        // Example: learn what "normal" error rate looks like
        $errorCounts = [];
        
        foreach ($events as $event) {
            $hour = date('H', strtotime($event['timestamp']));
            $errorCounts[$hour][] = $event['severity'] === 'error' ? 1 : 0;
        }
        
        // Calculate statistics per hour
        $baseline = [];
        foreach ($errorCounts as $hour => $counts) {
            $baseline[$hour] = [
                'mean' => array_sum($counts) / count($counts),
                'stddev' => $this->stddev($counts),
                'min' => min($counts),
                'max' => max($counts),
            ];
        }
        
        return $baseline;
    }

    /**
     * Find deviations from baseline
     */
    public function findAnomalies(array $events, array $baseline): array
    {
        $anomalies = [];
        
        $errorCounts = [];
        foreach ($events as $event) {
            $hour = date('H', strtotime($event['timestamp']));
            $errorCounts[$hour][] = $event['severity'] === 'error' ? 1 : 0;
        }
        
        foreach ($errorCounts as $hour => $counts) {
            $current = array_sum($counts) / count($counts);
            $baseline_stats = $baseline[$hour] ?? null;
            
            if (!$baseline_stats) continue;
            
            // Z-score: (value - mean) / stddev
            $zscore = ($current - $baseline_stats['mean']) / max($baseline_stats['stddev'], 0.01);
            
            // Flag if deviation > 2σ (95th percentile)
            if (abs($zscore) > 2.0) {
                $anomalies[] = [
                    'hour' => $hour,
                    'expected' => $baseline_stats['mean'],
                    'actual' => $current,
                    'zscore' => $zscore,
                    'severity' => abs($zscore) > 3.0 ? 'high' : 'medium',
                ];
            }
        }
        
        return $anomalies;
    }

    private function stddev(array $values): float
    {
        if (count($values) < 2) return 0.0;
        
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(
            fn($x) => pow($x - $mean, 2),
            $values
        )) / count($values);
        
        return sqrt($variance);
    }
}
```

**Testing Phase 1**:
```php
$detector = new AnomalyDetector();

// Learn from "normal" bundle
$normal_events = parse_bundle('jjeth-normal.tar.gz');
$baseline = $detector->buildBaseline($normal_events);

// Find anomalies in current bundle
$current_events = parse_bundle('jjeth-current.tar.gz');
$anomalies = $detector->findAnomalies($current_events, $baseline);

// Output
foreach ($anomalies as $anom) {
    echo "Anomaly: {$anom['hour']}:00 - Error rate {$anom['actual']} "
         . "(expected {$anom['expected']}, zscore={$anom['zscore']})\n";
}
```

---

## Phase 2: Root Cause Analysis (2-4 weeks)

### What Gets Built

```
EventCorrelator
├─ Find temporal relationships
├─ Identify event chains
└─ Score causality
```

### Key Files to Create

```
src/AI/
├── EventCorrelator.php
│   ├─ buildTimeline(logs) → ordered events
│   ├─ findCorrelations(events) → related pairs
│   ├─ buildCausalChain(events) → A→B→C→...
│   └─ scoreCausality(chainA, chainB) → 0.0-1.0
│
└── RootCauseAnalyzer.php
    ├─ findRootCause(anomalies, correlations) → cause
    ├─ buildDependencyGraph(chain) → visual
    └─ explainCausality(cause, chain) → narrative
```

### Code Example: Phase 2

```php
<?php

namespace App\AI;

class EventCorrelator
{
    /**
     * Build chronological event timeline
     */
    public function buildTimeline(array $logs): array
    {
        $events = [];
        
        foreach ($logs as $log) {
            if (preg_match('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(.+)/i', 
                           $log, $m)) {
                
                $timestamp = strtotime($m[1]);
                $message = $m[2];
                
                // Categorize event type
                $type = $this->categorizeEvent($message);
                $severity = $this->extractSeverity($message);
                
                $events[] = [
                    'timestamp' => $timestamp,
                    'unix_time' => $timestamp,
                    'message' => $message,
                    'type' => $type,
                    'severity' => $severity,
                ];
            }
        }
        
        // Sort by timestamp
        usort($events, fn($a, $b) => $a['unix_time'] <=> $b['unix_time']);
        
        return $events;
    }

    /**
     * Find events that are correlated (happen together)
     */
    public function findCorrelations(array $events): array
    {
        $correlations = [];
        
        // Look for pairs of events that frequently occur together
        for ($i = 0; $i < count($events) - 1; $i++) {
            $eventA = $events[$i];
            $eventB = $events[$i + 1];
            
            // Time proximity: within 30 seconds?
            $timeDiff = $eventB['unix_time'] - $eventA['unix_time'];
            if ($timeDiff > 30) continue;
            
            // Domain causality: do types suggest causality?
            if ($this->hasCausalitySignal($eventA['type'], $eventB['type'])) {
                $correlations[] = [
                    'eventA' => $eventA,
                    'eventB' => $eventB,
                    'timeDiff' => $timeDiff,
                    'causality_score' => $this->getCausalityScore(
                        $eventA['type'], 
                        $eventB['type']
                    ),
                ];
            }
        }
        
        return $correlations;
    }

    /**
     * Build chain of events: A → B → C → ...
     */
    public function buildCausalChain(array $events): array
    {
        $chains = [];
        $visited = [];
        
        for ($i = 0; $i < count($events); $i++) {
            if (isset($visited[$i])) continue;
            
            $chain = [$events[$i]];
            $visited[$i] = true;
            $j = $i + 1;
            
            // Keep adding events that correlate
            while ($j < count($events) && count($chain) < 10) {
                $correlation = $this->findCorrelation(
                    $chain[count($chain)-1],
                    $events[$j]
                );
                
                if ($correlation && $correlation['causality_score'] > 0.6) {
                    $chain[] = $events[$j];
                    $visited[$j] = true;
                }
                
                $j++;
            }
            
            if (count($chain) > 1) {
                $chains[] = $chain;
            }
        }
        
        return $chains;
    }

    private function categorizeEvent(string $message): string
    {
        if (stripos($message, 'error') !== false) return 'error';
        if (stripos($message, 'thermal') !== false) return 'thermal';
        if (stripos($message, 'power') !== false) return 'power';
        if (stripos($message, 'psu') !== false) return 'power';
        if (stripos($message, 'voltage') !== false) return 'power';
        if (stripos($message, 'disk') !== false || 
            stripos($message, 'sda') !== false) return 'disk';
        if (stripos($message, 'io') !== false) return 'io';
        return 'other';
    }

    private function extractSeverity(string $message): string
    {
        if (stripos($message, 'critical') !== false) return 'critical';
        if (stripos($message, 'error') !== false) return 'error';
        if (stripos($message, 'warning') !== false) return 'warning';
        return 'info';
    }

    private function hasCausalitySignal(string $typeA, string $typeB): bool
    {
        // Known causal relationships
        $signals = [
            'thermal' => ['power', 'io', 'disk', 'error'],
            'power' => ['io', 'disk', 'error'],
            'io' => ['error', 'disk'],
        ];
        
        return isset($signals[$typeA]) && 
               in_array($typeB, $signals[$typeA]);
    }

    private function getCausalityScore(string $typeA, string $typeB): float
    {
        // Known strong relationships
        $scores = [
            'thermal' => ['power' => 0.9, 'io' => 0.7, 'error' => 0.8],
            'power' => ['io' => 0.85, 'error' => 0.8],
            'io' => ['error' => 0.75],
        ];
        
        return $scores[$typeA][$typeB] ?? 0.5;
    }

    private function findCorrelation(array $eventA, array $eventB): ?array
    {
        $timeDiff = $eventB['unix_time'] - $eventA['unix_time'];
        
        if ($timeDiff < 0 || $timeDiff > 30) return null;
        if (!$this->hasCausalitySignal($eventA['type'], $eventB['type'])) {
            return null;
        }
        
        return [
            'timeDiff' => $timeDiff,
            'causality_score' => $this->getCausalityScore(
                $eventA['type'],
                $eventB['type']
            ),
        ];
    }
}
```

---

## Phase 3: Integration (1-2 weeks)

### New Pipeline Step

```php
// In Pipeline/AIAnalysisStep.php

class AIAnalysisStep implements StepInterface
{
    public function run(PipelineContext $ctx): void
    {
        $ctx->startStep('ai_analysis');
        
        // Get parsed data from previous steps
        $bundle_data = $ctx->bag['bundles'][0] ?? null;
        if (!$bundle_data) {
            $ctx->completeStep('ai_analysis');
            return;
        }
        
        // Extract logs
        $logs = $this->extractLogs($bundle_data['extracted_path']);
        
        // Run AI analysis
        $detector = new AnomalyDetector();
        $correlator = new EventCorrelator();
        $analyzer = new RootCauseAnalyzer();
        
        // Find anomalies
        $baseline = $detector->buildBaseline($logs);
        $anomalies = $detector->findAnomalies($logs, $baseline);
        
        // Find correlations
        $timeline = $correlator->buildTimeline($logs);
        $chains = $correlator->buildCausalChain($timeline);
        
        // Identify root causes
        $root_causes = $analyzer->findRootCauses($anomalies, $chains);
        
        // Generate explanations
        $explanations = $this->generateExplanations($root_causes, $chains);
        
        // Score confidence
        foreach ($explanations as &$explanation) {
            $explanation['confidence'] = $this->scoreConfidence(
                $explanation,
                $bundle_data
            );
        }
        
        // Store results
        $ctx->bag['ai_analysis'] = [
            'anomalies' => $anomalies,
            'causal_chains' => $chains,
            'root_causes' => $root_causes,
            'explanations' => $explanations,
        ];
        
        $ctx->stepDetail('ai_analysis', sprintf(
            '%d anomalies, %d causal chains, %d root causes',
            count($anomalies),
            count($chains),
            count($root_causes)
        ));
        
        $ctx->completeStep('ai_analysis');
    }
}
```

### Report Integration

```
Report Structure:

[Rule-Based Findings]
└─ Issues matching predefined rules
   (CRITICAL, HIGH, WARNING)

[AI-Driven Analysis] ← NEW
├─ Anomalies Detected
│  └─ Error rate spike at 14:05 (Z-score: 3.2)
├─ Causal Chains Identified
│  └─ Thermal spike → Power sag → I/O errors
├─ Root Causes
│  └─ Thermal management (confidence: 0.87)
└─ Recommendations
   └─ Add cooling capacity

[Combined Findings]
└─ All issues merged with confidence levels
```

---

## Phase 4: Learning & Improvement (ongoing)

### Feedback Loop

```
User Reviews AI Finding
    ↓
[Correct?] 
├─ YES → Store as success case
│        Train on this example
│        Improve similar detections
│
├─ WRONG → Debug why analysis failed
│          Adjust causality weights
│          Improve feature extraction
│
└─ UNSURE → Ask user to investigate
            Store as uncertainty case
            Improve confidence thresholds
```

### Continuous Improvement

```
Metrics to Track:
  ├─ Accuracy per finding type
  ├─ False positive rate
  ├─ False negative rate (missed issues)
  ├─ Confidence score calibration
  └─ User satisfaction

Monthly Review:
  ├─ Analyze feedback
  ├─ Identify weak areas
  ├─ Retrain models
  └─ Update thresholds
```

---

## Architecture Diagram

```
┌──────────────────────────────────────────────────────────┐
│                    DEBUG BUNDLE                           │
└──────────────────────────────────────────────────────────┘
                            ↓
                    ┌──────────────┐
                    │  ParseStep   │
                    └──────────────┘
                            ↓
        ┌───────────────────┴───────────────────┐
        ↓                                       ↓
   ┌─────────────┐                    ┌──────────────────┐
   │EvaluateStep │ (Rules)            │AI AnalysisStep   │ ← NEW
   │             │                    │                  │
   │- Rule match │                    │- Anomaly detect  │
   │- Findings   │                    │- Correlation     │
   └─────────────┘                    │- RCA             │
        ↓                             │- Explanation     │
        │                             │- Confidence      │
        │                             └──────────────────┘
        │                                      ↓
        └──────────────┬───────────────────────┘
                       ↓
              ┌────────────────┐
              │CorrelateStep   │
              │                │
              │- Merge findings│
              │- Link related  │
              └────────────────┘
                       ↓
              ┌────────────────┐
              │NarrateStep     │
              │                │
              │- Add AI context│
              │- Explain chains│
              └────────────────┘
                       ↓
              ┌────────────────┐
              │RenderStep      │
              │                │
              │- Create report │
              │- Show both     │
              └────────────────┘
                       ↓
           ┌──────────────────────┐
           │   Final Report       │
           │                      │
           │ Rule: RAID degraded  │
           │ AI: Caused by power  │
           │ Confidence: 82%      │
           └──────────────────────┘
```

---

## Resource Requirements

### Development

```
Phase 1: 2-3 engineers, 2-4 weeks
Phase 2: 2-3 engineers, 2-4 weeks
Phase 3: 1-2 engineers, 1-2 weeks
Phase 4: 1 engineer, ongoing

Total: ~3-4 months full-time for MVP
```

### Runtime

```
Per bundle analysis:
  ├─ Memory: 100-500MB (logs + analysis)
  ├─ CPU: 2-4 cores during analysis
  ├─ Time: 2-5 minutes per bundle
  └─ Storage: 1-10MB for results
```

---

## Success Criteria

```
Phase 1 Complete When:
  ✓ Detect statistical anomalies >90% accuracy
  ✓ Can identify 5+ anomaly types
  ✓ Process 100K events in <2 minutes

Phase 2 Complete When:
  ✓ Build causal chains correctly 80%+ of time
  ✓ Identify root causes with 70%+ accuracy
  ✓ Generate coherent explanations

Phase 3 Complete When:
  ✓ Full integration with existing pipeline
  ✓ AI findings appear in reports
  ✓ No performance regression

Phase 4 Complete When:
  ✓ User feedback collection working
  ✓ Accuracy improves over time
  ✓ False positive rate <10%
```

---

## Decision Points

### Q1: Show AI findings always or only high confidence?

**Option A**: Always show (0.0+ confidence)
- Pros: Users see all analysis, can investigate
- Cons: Overwhelming, confusing

**Option B**: Show high confidence only (0.75+)
- Pros: Only accurate findings shown
- Cons: Miss some real issues

**Recommendation**: Show all, but label by confidence tier

---

### Q2: Should AI recommend actions or just identify issues?

**Option A**: AI identifies only (rules make recommendations)
- Pros: Simpler, safer
- Cons: Less actionable

**Option B**: AI generates recommendations
- Pros: Complete RCA including fixes
- Cons: More risk if wrong

**Recommendation**: AI generates, but label as "experimental"

---

### Q3: How much human validation before deployment?

**Option A**: Full expert review (100% of findings)
- Pros: High confidence in accuracy
- Cons: Very slow to deploy

**Option B**: Spot checks (10% of findings)
- Pros: Faster to deploy
- Cons: Risk of undetected errors

**Recommendation**: Spot checks + feedback loop

---

## Rollout Plan

```
Week 1-2: Phase 1 development
Week 3: Phase 1 testing with real bundles
Week 4-5: Phase 1 refinement based on feedback

Week 6-7: Phase 2 development
Week 8: Phase 2 testing
Week 9: Phase 2 refinement

Week 10: Phase 3 integration
Week 11: End-to-end testing
Week 12: Beta release to select customers

Week 13+: Phase 4 continuous improvement
```

---

## Risk Mitigation

| Risk | Mitigation |
|------|-----------|
| AI finds false positives | High confidence threshold, expert validation |
| Analysis too slow | Optimize parsing, cache results, run async |
| Missing context | Quality scoring, warn about missing data |
| Users confused | Clear labeling, separate section, education |
| Model overfits | Diverse training data, feedback correction |

---

## Summary

This framework provides a path to add intelligent root cause analysis while maintaining the speed and certainty of the rule-based system. Start with anomaly detection, add correlation analysis, then integrate fully into the pipeline.

