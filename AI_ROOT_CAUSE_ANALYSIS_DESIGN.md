# AI-Driven Root Cause Analysis System Design

**Date**: April 29, 2026
**Status**: Design Proposal
**Scope**: Unknown Issue Detection & RCA

---

## Executive Summary

The current DeepDive system uses rule-based detection for **known** issues. This proposal adds an **AI-driven analysis layer** that:

1. **Detects anomalies** not covered by existing rules
2. **Analyzes relationships** between system events
3. **Identifies root causes** through pattern recognition
4. **Generates explanations** with reasoning
5. **Learns from cases** to improve future analysis

---

## Current Architecture (Rule-Based)

```
Debug Bundle
    ↓
[ParseStep] → Extract facts
    ↓
[EvaluateStep] → Match against rules
    ↓
[CorrelateStep] → Link related findings
    ↓
[NarrateStep] → Generate text
    ↓
[RenderStep] → Create report
```

**Limitation**: Only detects issues matching predefined rules. Unknown patterns slip through.

---

## Proposed Enhancement: AI Layer

```
Debug Bundle
    ↓
[ParseStep] → Extract facts
    ↓
┌─────────────────────────┐
│ [EvaluateStep]          │ (Rule-based, known issues)
│   ↓                     │
│ [RuleMatches]           │ CRITICAL, HIGH, WARNING
└─────────────────────────┘
    ↓
┌─────────────────────────────────────────────────┐
│ [AI Analysis Step] ← NEW                        │
│                                                 │
│ 1. Anomaly Detection                            │
│    └─ Find unusual patterns not in rules        │
│                                                 │
│ 2. Event Correlation                           │
│    └─ Link related events across logs          │
│                                                 │
│ 3. Root Cause Analysis                         │
│    └─ Identify triggering event chains         │
│                                                 │
│ 4. Explanation Generation                      │
│    └─ Create human-readable analysis           │
│                                                 │
│ 5. Confidence Scoring                          │
│    └─ Quantify certainty level                 │
│                                                 │
│ 6. Recommendations                             │
│    └─ Suggest remediation actions              │
└─────────────────────────────────────────────────┘
    ↓
[CorrelateStep] → Merge rule + AI findings
    ↓
[NarrateStep] → Enhance with AI insights
    ↓
[RenderStep] → Create unified report
```

---

## AI Capabilities Framework

### 1. Anomaly Detection Module

**Purpose**: Identify unusual patterns in logs/metrics not covered by rules

**Approach**:
```
Statistical Baseline:
  ├─ Normal event frequency distribution
  ├─ Typical error rates
  ├─ Expected resource usage patterns
  └─ Baseline timestamps/sequences

Compare to Current Data:
  ├─ Flag deviations (>2σ)
  ├─ Identify clustering
  ├─ Detect temporal anomalies
  └─ Recognize sequence breaks
```

**Example**:
```
Normal: Disk errors: 1-2 per day, random timestamps
Detected: Disk errors: 50+ per hour, clustered at 2:15 AM daily
→ AI flags as anomalous (not covered by rules)
→ Suggests: Scheduled process causing errors
```

**Implementation**: 
- Time-series analysis (STL decomposition)
- Isolation forests (outlier detection)
- Z-score/IQR analysis
- Sequence pattern mining

---

### 2. Event Correlation Engine

**Purpose**: Connect seemingly unrelated events into causal chains

**Approach**:
```
Multi-Source Timeline:
  ├─ Kernel logs (dmesg)
  ├─ Application logs (syslog)
  ├─ RAID status changes
  ├─ IPMI events
  ├─ Power events
  ├─ Thermal events
  └─ Network events (if available)

Correlation Analysis:
  ├─ Temporal proximity (events within N seconds?)
  ├─ Event types (logical relationship?)
  ├─ State transitions (does B follow A logically?)
  ├─ Causal markers (keywords: "because", "caused by", etc.)
  └─ Statistical association (probability A→B?)
```

**Example Chain Detection**:
```
Timeline:
  14:05:00 | Thermal: SYS temp = 85°C (high)
  14:05:15 | Thermal: SYS temp = 90°C (critical)
  14:05:30 | Power: PSU voltage sag detected
  14:05:45 | System: Throttling CPU to 50%
  14:06:00 | Syslog: I/O timeout on device /dev/sda

AI Analysis:
  High temp → Cooling inadequate
  → Inadequate cooling → Power delivery strain
  → Power strain → Voltage sag
  → Voltage sag → I/O errors
  
Root Cause: Thermal → Power delivery cascade failure
Confidence: 0.92 (high)
Recommendation: Check cooling system, PSU capacity
```

---

### 3. Root Cause Analysis Engine

**Purpose**: Identify the triggering event that started the cascade

**Approach**:
```
Dependency Graph Construction:
  
  Event A (earliest) → triggers → Event B → triggers → Event C → ...
  
  System state before A: Normal
  System state after sequence: Failed
  
  Find: What changed between A and B?
```

**Causal Inference Methods**:
```
1. Temporal Causality
   - A precedes B? (necessary but not sufficient)
   - Time gap reasonable? (seconds, not hours?)
   
2. Domain Knowledge
   - Are A→B linked in system behavior?
   - Example: High temp → Power issues
   
3. Counter-factual Analysis
   - What if A hadn't occurred?
   - Would B still happen?
   
4. Statistical Association
   - Do A and B correlate across bundles?
   - Is correlation >0.7?
```

**Example: Unknown I/O Timeout**

```
Symptoms: Intermittent I/O timeout errors on /dev/sda

Rule-Based System: No matching rules → Not detected

AI Analysis:
  1. Extract all /dev/sda related events
  2. Find patterns:
     - I/O timeout always preceded by power event
     - Power event always preceded by thermal spike
     - Thermal spike always preceded by heavy I/O load
     
  3. Build chain:
     Heavy Load → Thermal Spike → Power Sag → I/O Timeout
     
  4. Confidence scoring:
     - Temporal proximity: 0.95
     - Domain causality: 0.88
     - Statistical correlation: 0.82
     - Overall confidence: 0.88
     
  5. Root cause identified:
     PRIMARY: Thermal management (cooling capacity)
     SECONDARY: Power delivery stability
     TERTIARY: Workload scheduling
     
  6. Recommendation:
     "System thermal management is marginal. Heavy I/O 
      workloads trigger temperature spikes beyond cooling 
      capacity, causing power delivery issues. Recommend: 
      (1) Add case cooling, (2) Reduce peak load, (3) 
      Schedule heavy workloads during cooler hours."
```

---

### 4. Explanation Generation

**Purpose**: Create human-readable analysis for unknown issues

**Approach**:
```
Template-Based Generation:

Issue: {detected_anomaly}
Severity: {confidence_score}

Evidence:
  - {event_1} (timestamp, source)
  - {event_2} (timestamp, source)
  - {event_3} (timestamp, source)

Analysis:
  {narrative explanation of causal chain}

Root Cause:
  {primary cause} (confidence: {score})
  
Contributing Factors:
  - {factor_1} (impact: {level})
  - {factor_2} (impact: {level})

Timeline:
  {T0}: {initial_event}
      → {resulting_state}
  {T1}: {cascade_event}
      → {worsening_state}
  {T2}: {failure_event}
      → {final_state}

Impact:
  - System components affected: {list}
  - Data at risk: {yes/no}
  - Recovery time: {estimate}

Recommended Actions:
  1. {immediate_action} (urgency: HIGH)
  2. {short_term_action} (urgency: MEDIUM)
  3. {long_term_action} (urgency: LOW)

Confidence Levels:
  Root Cause Analysis: {score}%
  Recommended Actions: {score}%
  
Limitations:
  - Analysis based on available data
  - Missing {data_source} may affect accuracy
  - Requires {action} to validate
```

---

### 5. Confidence Scoring

**Purpose**: Quantify certainty of AI analysis

**Scoring Factors**:
```
Data Quality (0-1.0):
  ├─ Bundle completeness (0.0-1.0)
  │  └─ Missing logs = lower confidence
  ├─ Time alignment (0.0-1.0)
  │  └─ Skewed timestamps = uncertainty
  └─ Event density (0.0-1.0)
     └─ Too few events = low confidence

Causality Strength (0-1.0):
  ├─ Temporal proximity (0.0-1.0)
  │  └─ Events seconds apart? High confidence
  ├─ Domain plausibility (0.0-1.0)
  │  └─ Known system relationship? High confidence
  ├─ Statistical correlation (0.0-1.0)
  │  └─ Correlation coefficient
  └─ Repeatability (0.0-1.0)
     └─ Happens consistently? High confidence

Explainability (0.0-1.0):
  ├─ Number of intermediate steps
  │  └─ Too many steps = harder to explain
  ├─ Known mechanisms
  │  └─ Unknown mechanisms = lower confidence
  └─ Expert agreement
     └─ Domain expert validation

Final Score = (Data Quality × Causality × Explainability) ^ 0.33
```

**Confidence Categories**:
```
0.90+: Very High (actionable, use immediately)
0.75-0.89: High (use with validation)
0.60-0.74: Medium (needs investigation)
0.40-0.59: Low (exploratory only)
<0.40: Very Low (ignore, wait for more data)
```

---

## System Architecture

### AI Analysis Pipeline

```python
class AIRootCauseAnalyzer:
    """
    Perform AI-driven root cause analysis on unknown issues
    """
    
    def __init__(self):
        self.anomaly_detector = AnomalyDetector()
        self.correlator = EventCorrelator()
        self.rca_engine = RCAnalysisEngine()
        self.explainer = ExplanationGenerator()
    
    def analyze(self, bundle_data: BundleData) -> AIAnalysis:
        """
        Main entry point: analyze bundle for unknown issues
        """
        
        # Step 1: Detect anomalies
        anomalies = self.anomaly_detector.find_anomalies(
            bundle_data.logs,
            bundle_data.metrics
        )
        
        # Step 2: Build event timeline
        timeline = self._build_timeline(bundle_data)
        
        # Step 3: Find event correlations
        correlations = self.correlator.find_correlations(timeline)
        
        # Step 4: Perform RCA
        root_causes = self.rca_engine.identify_causes(
            anomalies,
            correlations,
            timeline
        )
        
        # Step 5: Generate explanations
        analysis = self.explainer.generate_analysis(
            root_causes,
            timeline,
            bundle_data
        )
        
        # Step 6: Score confidence
        analysis.confidence = self._score_confidence(analysis)
        
        return analysis
```

### Data Preparation

```
Input: Raw debug bundle
  ├─ Logs (syslog, dmesg, application logs)
  ├─ Metrics (SMART data, IPMI readings)
  ├─ Structured data (RAID status, network config)
  └─ Events (timestamps, error codes)

Processing:
  ├─ Normalize timestamps (UTC)
  ├─ Parse log entries (regex extraction)
  ├─ Build event objects (time, type, source, details)
  ├─ Aggregate by component (disk, power, thermal, etc.)
  ├─ Identify sequences
  └─ Filter noise (benign log noise)

Output: Structured event timeline
  └─ [Event, Event, Event, ...]
     Each with: timestamp, source, type, severity, data
```

---

## Integration Points

### 1. With ParseStep

```python
# In ParseStep.run():

# Existing rule-based analysis
rule_findings = evaluate_rules(registry, catalogue)

# NEW: AI analysis for unknown issues
ai_analyzer = AIRootCauseAnalyzer()
ai_findings = ai_analyzer.analyze(bundle_data)

# Store both
ctx.bag['rule_findings'] = rule_findings
ctx.bag['ai_findings'] = ai_findings
```

### 2. With CorrelateStep

```python
# Merge findings
merged = {
    'rule_based': rule_findings,
    'ai_driven': ai_findings,
    'correlated': correlate_both(rule_findings, ai_findings)
}

# If AI found something rules missed:
if ai_findings and not overlapping_rule_findings:
    merged['new_issues_identified'] = ai_findings
```

### 3. With ReportRenderer

```
Report Sections:

[Header]
[Hardware]
[Summary - Rules Based]
    ├─ Critical Issues (from rules)
    └─ High Priority (from rules)

[AI Analysis Results] ← NEW SECTION
    ├─ Anomalies Detected
    │  └─ Issue A (confidence: 87%)
    │  └─ Issue B (confidence: 62%)
    ├─ Root Cause Chains
    │  └─ Root cause A → cascade → failure
    └─ Recommendations
       ├─ Immediate actions
       └─ Long-term improvements

[Incidents - Combined]
    ├─ From rules
    └─ From AI analysis

[Appendix]
    ├─ Evidence trails
    ├─ Timeline
    └─ Confidence metrics
```

---

## Use Cases

### Case 1: Intermittent I/O Errors

**Scenario**: Customer reports random I/O timeout errors, but no error patterns in logs

**Rule System**: No matching rules → No findings

**AI System**:
```
Detects:
  - I/O timeout spike at irregular intervals
  - Thermal warning events preceding errors
  - Power event preceding thermal spikes
  
Analysis:
  Causal chain: Heavy Load → Thermal Spike → Power Sag → I/O Timeout
  
Confidence: 0.88
  
Recommendation:
  Root cause is thermal, not I/O subsystem. Recommend: upgrade cooling.
```

---

### Case 2: Silent Data Corruption

**Scenario**: Filesystem corruption detected, but no obvious failure in logs

**Rule System**: RAID degradation detected, but not root cause

**AI System**:
```
Detects:
  - Unusual memory error pattern (before corruption)
  - Voltage instability (before memory errors)
  - Correlation with heavy write workload
  
Analysis:
  Causal chain: Heavy Writes → Voltage Sag → Memory Errors → Data Corruption
  
Confidence: 0.81
  
Recommendation:
  Root cause is power delivery under load. Recommend: PSU replacement, 
  load balancing, or reduced write concurrency.
```

---

### Case 3: Performance Degradation

**Scenario**: System running slowly, user reports application timeouts

**Rule System**: No specific rule for "slowness"

**AI System**:
```
Detects:
  - I/O latency trend (increasing over time)
  - Increasing bad sector count
  - Correlation with system load
  
Analysis:
  Disk health degrading. As bad sectors increase, seek time increases,
  causing I/O latency. Under heavy load, system can't keep up.
  
Confidence: 0.79
  
Recommendation:
  Early disk failure. Recommend: immediate backup, replace disk, 
  monitor for acceleration of failure.
```

---

## Implementation Roadmap

### Phase 1: Foundation (2-4 weeks)

**Deliverables**:
- [ ] `AIRootCauseAnalyzer` class
- [ ] `AnomalyDetector` module
- [ ] Basic event correlation
- [ ] Timeline builder

**Capabilities**:
- Detect statistical anomalies
- Build event timelines
- Simple correlation

**Scope**: Prototype with limited capabilities

---

### Phase 2: Intelligence (2-4 weeks)

**Deliverables**:
- [ ] Causal inference engine
- [ ] Confidence scoring
- [ ] Explanation generation
- [ ] Template system

**Capabilities**:
- Identify root causes
- Generate human-readable analysis
- Score confidence levels

**Scope**: Core AI functionality

---

### Phase 3: Integration (1-2 weeks)

**Deliverables**:
- [ ] AI step in pipeline
- [ ] Report rendering
- [ ] Merge with rule findings
- [ ] API design

**Capabilities**:
- Full pipeline integration
- Report generation with AI findings

**Scope**: Production integration

---

### Phase 4: Learning (ongoing)

**Deliverables**:
- [ ] Feedback collection
- [ ] Case database
- [ ] Model improvement
- [ ] Accuracy tracking

**Capabilities**:
- Improve analysis based on feedback
- Learn from real-world cases
- Reduce false positives

**Scope**: Continuous improvement

---

## Safety & Validation

### Confidence Thresholds

```
< 0.40:  Not displayed (too uncertain)
0.40-0.59: Show with "Exploratory Analysis" label
0.60-0.74: Show with "Suggested Investigation" label  
0.75+:    Show with confidence level in report
```

### Validation Requirements

```
Before using AI findings:
  ✓ Verify data completeness (>80% of expected logs)
  ✓ Check timestamp alignment (no massive gaps)
  ✓ Validate anomaly detection (manual spot checks)
  ✓ Get expert review (domain expert approval)
  ✓ Test with known issues (verify against rules)
```

### Human-in-the-Loop

```
AI Analysis
    ↓
[Confidence Check] → Threshold met?
    ↓ YES
[Present to User]
    ├─ With reasoning
    ├─ With confidence score
    ├─ With supporting evidence
    └─ With validation actions
    ↓
[User Feedback]
    ├─ Correct? → Train on success
    ├─ Wrong? → Debug analysis
    └─ Unsure? → Recommend manual check
```

---

## Technical Requirements

### Libraries/Tools

```
Data Analysis:
  ├─ pandas (data manipulation)
  ├─ numpy (numerical analysis)
  ├─ scipy (statistics)
  └─ scikit-learn (ML algorithms)

Anomaly Detection:
  ├─ sklearn.ensemble.IsolationForest
  ├─ statsmodels (time-series)
  └─ Custom algorithms

Causality Analysis:
  ├─ Custom correlation engine
  ├─ Temporal analysis
  └─ Domain knowledge base

NLP/Text Generation:
  ├─ Claude API (for explanations)
  └─ Template system
```

### Data Requirements

```
Per Bundle:
  ├─ System logs (500KB-50MB typical)
  ├─ Kernel logs (100KB-10MB typical)
  ├─ IPMI events (10KB-1MB typical)
  ├─ SMART data (1KB-100KB)
  └─ Timestamps (critical)

Processing:
  ├─ Parse all events
  ├─ Normalize to UTC
  ├─ Sort by timestamp
  └─ Build n-gram sequences
```

---

## Risks & Mitigations

### Risk 1: False Positives

**Problem**: AI finds "root causes" that aren't real

**Mitigation**:
- High confidence thresholds (>0.75 to display)
- Require multiple evidence sources
- Validate against known failure modes
- Get expert review before recommending actions

---

### Risk 2: Missing Context

**Problem**: AI analysis correct given available data, but missing logs make it incomplete

**Mitigation**:
- Quality score based on data completeness
- Lower confidence when data gaps detected
- Warn user about missing sources
- Recommend data collection improvements

---

### Risk 3: Overwhelming Users

**Problem**: Too many AI findings confuse user

**Mitigation**:
- Only show high-confidence findings
- Prioritize by actionability
- Group related findings
- Highlight key root causes

---

### Risk 4: Computational Cost

**Problem**: AI analysis takes too long on large bundles

**Mitigation**:
- Optimize event parsing
- Cache intermediate results
- Run async in background
- Show rule-based results first, AI later

---

## Success Metrics

### Accuracy
```
✓ Find 80%+ of real issues (recall)
✓ 90%+ of findings are accurate (precision)
✓ Confidence score correlates with correctness
✓ Improve over time with feedback
```

### Performance
```
✓ Analyze 100MB bundle in <5 minutes
✓ Parse 100K events in <1 minute
✓ Generate explanation in <30 seconds
✓ Incremental improvement with caching
```

### User Value
```
✓ Find issues rules can't (5-10% of bundles)
✓ Provide explanations users understand
✓ Actionable recommendations (80%+)
✓ Reduce mean-time-to-resolution (MTTR)
```

---

## Comparison: Rules vs. AI

### Rule-Based System

**Strengths**:
- ✅ Deterministic (no surprises)
- ✅ Explainable (clear rule logic)
- ✅ Fast (simple pattern matching)
- ✅ Proven (matches known issues)

**Weaknesses**:
- ❌ Limited to known issues
- ❌ Can't adapt to new patterns
- ❌ Misses subtle correlations
- ❌ Requires expert to write rules

### AI-Driven System

**Strengths**:
- ✅ Finds unknown patterns
- ✅ Learns from data
- ✅ Detects subtle relationships
- ✅ Improves over time

**Weaknesses**:
- ❌ Less certain (confidence scores)
- ❌ Harder to explain
- ❌ Slower analysis
- ❌ Requires validation

### Hybrid Approach (Recommended)

**Best of Both**:
- Use rules for known issues (fast, certain)
- Use AI for unknowns (exploratory)
- Merge findings intelligently
- Validate with human expertise

---

## Conclusion

An AI-driven root cause analysis layer would:

1. **Extend Coverage**: Find issues beyond predefined rules
2. **Provide Insight**: Explain causal chains users care about
3. **Improve Accuracy**: Learn from real-world cases
4. **Enable Proactive Diagnostics**: Detect emerging issues early
5. **Support Decision-Making**: Actionable recommendations

**The combination of rule-based + AI analysis creates a more robust diagnostic system that handles both known and unknown issues.**

---

## Next Steps

1. **Prototype Phase 1** (foundation - anomaly detection)
2. **Validate with real bundles** (test accuracy)
3. **Integrate with pipeline** (merge with rules)
4. **Collect feedback** (improve models)
5. **Deploy to production** (monitor metrics)

---

## Questions for Your Team

1. Should AI findings appear in same report as rules, or separate?
2. What confidence threshold (0.60-0.90) acceptable for your users?
3. Should AI recommend actions, or just identify issues?
4. How much processing time acceptable (seconds to minutes)?
5. Should AI improve with feedback over time?

