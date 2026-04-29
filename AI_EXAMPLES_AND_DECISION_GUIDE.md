# AI Root Cause Analysis - Real-World Examples & Decision Guide

---

## When to Use Each Approach

### Rule-Based Analysis (Use When)
```
✓ Issue matches known pattern
✓ Clear failure signature
✓ High confidence needed
✓ Action must be taken immediately

Example: RAID degraded + disk error → Replace disk (HIGH confidence)
```

### AI Analysis (Use When)
```
✓ Unknown or unusual pattern
✓ Multiple contributing factors
✓ Subtle relationships
✓ Exploratory investigation needed

Example: Intermittent I/O timeouts without obvious cause → Investigate root cause
```

---

## Real-World Example: The JJeth Case

### What Rules Detect

```yaml
Finding 1:
  Rule: hardware.redundant_psu_failure
  Severity: CRITICAL
  Details: "Redundant PSU model missing 1 PSU - running on single PSU 
            with zero fault tolerance"
  
Finding 2:
  Rule: hardware.psu_not_detected
  Severity: CRITICAL
  Details: "PSU not detected by system"
```

### What AI Would Add

```
AI Finding 1: Cascading Power Failure
  
  Detected Anomalies:
    • PSU #1 stop/recovery cycles (5 occurrences)
    • Voltage sag events preceding shutdowns
    • Improper shutdown events (6 occurrences)
  
  Causal Chain:
    PSU #1 fails → Voltage drops → BMC detects failure
                → PSU recovers → Cycle repeats
  
  Root Cause: PSU #1 intermittent power delivery
    Confidence: 0.91 (HIGH)
    
  Evidence:
    • Stop events at: 12:26:08, 12:27:49, 13:16:46, 13:19:54
    • Recovery within 3 minutes each time
    • Pattern shows increasing frequency
    
  Recommendation:
    Replace PSU #1 immediately (failure accelerating)
    Likelihood of complete failure within days
```

---

## Example 1: Silent Disk Degradation

### Scenario
```
System appears healthy, but filesystem corruption detected during backup
No obvious errors in logs or SMART data
```

### Rule-Based Analysis
```
Finding: SMART data shows increased bad sectors
Severity: WARNING
Action: Monitor disk, plan replacement
```

### AI Analysis
```
Analysis: Disk Aging Chain

  Anomalies Detected:
    • Bad sector count increasing 2-3 per day (linear trend)
    • Read latency increasing 5-10% weekly
    • I/O timeout errors appear after 20:00 daily (thermal correlation)
    
  Causal Chain Identified:
    Bad sectors accumulate → Seek time increases
                          → Under heavy I/O, latency spikes
                          → Timeouts during peak load
                          → Data consistency issues
    
  Timeline:
    Week 1: 5 bad sectors → No impact
    Week 2: 15 bad sectors → Occasional latency
    Week 3: 35 bad sectors → Daily timeouts appear
    Week 4: 60 bad sectors → Data corruption detected
    
  Root Cause: Early disk failure (magnetic degradation)
    Confidence: 0.87
    
  Critical Insight: Linear degradation suggests wear, not damage
    Prognosis: Failure likely within 2-4 weeks
    
  Actionable Recommendation:
    1. IMMEDIATE: Backup all data to external storage
    2. SHORT-TERM: Replace disk before week 4
    3. PREVENT: Reduce peak load 20:00-22:00 window
    4. MONITOR: Track bad sector growth rate
```

---

## Example 2: Performance Degradation Mystery

### Scenario
```
Users report slowness, application timeouts occur randomly
No RAID problems, disk space adequate, CPU not maxed
No obvious errors in logs
```

### Rule-Based Analysis
```
Finding: None (no rules match)
Result: "No issues detected"
Reality: Something is wrong, but invisible to rules
```

### AI Analysis
```
Analysis: I/O Performance Degradation

  Anomalies Detected:
    • I/O wait time increasing 50% over 3 days (trend)
    • Seek latency 2x normal for large files
    • Memory page faults correlating with high I/O
    
  Event Correlation:
    Heavy write load (16:00-17:00 daily)
      → RAM usage peaks
      → Page faults increase
      → Disk seeks increase
      → I/O latency spikes
      → Application timeouts
    
  Causal Chain:
    Peak Usage Load
        ↓
    Memory pressure (80%+ utilization)
        ↓
    Active page swapping to disk
        ↓
    Additional disk I/O on top of normal load
        ↓
    Disk queue depth increases
        ↓
    All I/O slows down (not just swap)
        ↓
    Application timeouts from slow reads
    
  Root Cause: RAM insufficient for workload
    Confidence: 0.84
    
  Deep Analysis:
    • Working set size: 6GB (normal)
    • Available RAM: 4GB (insufficient)
    • Without swap: 2GB shortage
    • With swap: Disk thrashing amplifies problem
    
  Recommendation:
    IMMEDIATE: Reduce concurrent workloads during 16:00-17:00
    SHORT-TERM: Upgrade RAM to 8GB (minimum)
    LONG-TERM: Optimize application (reduces working set)
    
  Note: High confidence despite no alerts because correlation 
        across multiple data sources is strong.
```

---

## Example 3: Thermal-Related Cascade

### Scenario
```
System shut down unexpectedly
No clear error in logs
System booted successfully afterward
```

### Rule-Based Analysis
```
Finding: None (no rules match unexpected shutdown)
Result: "Unknown cause"
```

### AI Analysis
```
Analysis: Thermal-Driven Power Delivery Cascade

  Timeline (5 minutes before shutdown):
    
    14:50:00 | CPU temp: 75°C (normal)
             |
    14:51:00 | Heavy I/O workload starts
             | (backup job, high concurrency)
             |
    14:52:00 | CPU temp: 85°C (elevated)
             | System starts thermal throttling
             |
    14:53:00 | CPU temp: 92°C (critical)
             | Throttling at 60% (reduces cooling via load)
             | Power draw spikes (inefficient at low clocks)
             |
    14:54:00 | PSU voltage sag detected
             | (too much current despite thermal throttling)
             |
    14:54:30 | BMC detects voltage below threshold
             | Triggers emergency shutdown to protect hardware
             |
    14:54:45 | System powers off (graceful by BMC)
             | Data consistent (controlled shutdown)
             |
    14:55:00 | System reboots on its own
             |
    Anomalies Detected:
      • Thermal spike not from CPU alone
      • Voltage sag correlated with thermal peak
      • Shutdown triggered by BMC protective logic
    
    Causal Chain:
    
      High I/O Workload
        ↓ (causes CPU load)
      High CPU Activity
        ↓ (generates heat)
      Elevated Temperatures
        ↓ (triggers throttling)
      Inefficient Processing
        ↓ (actually increases power draw)
      High Current Draw
        ↓ (through PSU)
      Voltage Regulation Issue
        ↓ (PSU aging, voltage sag)
      BMC Protective Shutdown
        ↓
      System Off
    
    Root Cause: Combination of thermal + power margin
      Primary: Thermal management marginal
      Secondary: PSU aging (voltage unstable under load)
      Trigger: Heavy I/O during high ambient temperature
      
      Confidence: 0.89
    
    Key Insight: Neither thermal nor power alone would cause shutdown.
                 Only the combination of both marginal systems triggers.
    
    Prognosis:
      • Will happen again if heavy I/O during warm time
      • Frequency may increase as PSU ages
      • Risk: Next time may not shut down cleanly
      
    Recommendation:
      1. IMMEDIATE: Avoid heavy I/O during 14:00-17:00 (warmest hours)
      2. SHORT-TERM: Improve cooling (add fans, better airflow)
      3. MEDIUM: Monitor PSU voltage under load
      4. LONG-TERM: Replace aging PSU (0-1 year old vs 5+ year)
      
    Preventive Actions:
      • Add thermal monitoring alert at 85°C
      • Add voltage sag alert at <11.8V on 12V rail
      • Schedule heavy workloads for cooler hours
      • Reduce concurrent I/O threads
      • Test system under peak load + elevated ambient
```

---

## Report Output Example

```html
<section class="ai-analysis">
  <h2>AI-Driven Root Cause Analysis</h2>
  
  <p class="intro">
    The following issues were detected through machine learning analysis 
    of patterns not covered by predefined rules. Confidence levels indicate 
    certainty of the analysis.
  </p>
  
  <div class="ai-finding" data-confidence="0.91">
    <div class="finding-header critical">
      ⚠️ Cascading Power Failure Chain
      <span class="confidence-badge">91% Confidence</span>
    </div>
    
    <div class="finding-body">
      <h4>Detected Pattern</h4>
      <p>
        Power Supply #1 is experiencing intermittent power delivery failures 
        that trigger a cascade of system-level events.
      </p>
      
      <h4>Evidence</h4>
      <table class="evidence-table">
        <tr>
          <th>Timestamp</th>
          <th>Event Type</th>
          <th>Source</th>
          <th>Impact</th>
        </tr>
        <tr>
          <td>2026-04-29 12:26:08</td>
          <td>Power Supply 1 Stop</td>
          <td>SYNOSYSDB</td>
          <td>Power delivery lost</td>
        </tr>
        <tr>
          <td>2026-04-29 12:27:49</td>
          <td>Power Supply 1 Recovery</td>
          <td>SYNOSYSDB</td>
          <td>Power restored (3 min gap)</td>
        </tr>
        <tr>
          <td>2026-04-29 12:28:00</td>
          <td>System Improper Shutdown</td>
          <td>SYNOSYSDB</td>
          <td>Unclean shutdown due to power loss</td>
        </tr>
      </table>
      
      <h4>Causal Analysis</h4>
      <div class="causal-chain">
        <div class="chain-step">
          <div class="step-label">1. PSU Stop Event</div>
          <div class="step-detail">Power Supply #1 stops delivering power</div>
        </div>
        <div class="arrow">→</div>
        <div class="chain-step">
          <div class="step-label">2. Voltage Collapse</div>
          <div class="step-detail">System voltage drops below threshold</div>
        </div>
        <div class="arrow">→</div>
        <div class="chain-step">
          <div class="step-label">3. System Shutdown</div>
          <div class="step-detail">System loses power and shuts down</div>
        </div>
        <div class="arrow">→</div>
        <div class="chain-step">
          <div class="step-label">4. PSU Recovery</div>
          <div class="step-detail">PSU automatically restarts after cooldown</div>
        </div>
        <div class="arrow">→</div>
        <div class="chain-step">
          <div class="step-label">5. System Reboot</div>
          <div class="step-detail">System boots from powered state</div>
        </div>
      </div>
      
      <h4>Root Cause</h4>
      <p>
        <strong>Primary:</strong> PSU #1 intermittent power delivery 
        (likely capacitor degradation or contact issues)
      </p>
      <p>
        <strong>Secondary:</strong> Lack of redundancy during failure 
        (second PSU not actively compensating)
      </p>
      
      <h4>Timeline Pattern</h4>
      <p>
        Analysis of all recorded incidents shows increasing frequency:
      </p>
      <ul>
        <li>Incident 1-2: ~2 months apart (Oct 2025 - Dec 2025)</li>
        <li>Incident 3-4: ~1 month apart (Dec 2025 - Jan 2026)</li>
        <li>Incident 5-6: ~2 weeks apart (Jan-Feb 2026)</li>
        <li>Incident 7-8: ~3 days apart (Apr 2026)</li>
      </ul>
      <p class="trend-alert">
        ⚠️ Acceleration pattern suggests rapidly degrading component
      </p>
      
      <h4>Recommendation</h4>
      <div class="recommendation critical">
        <strong>URGENT - Replace PSU #1 within 24 hours</strong>
        <p>
          System has zero fault tolerance. Another failure will cause 
          immediate shutdown and potential data loss. The acceleration 
          pattern suggests complete failure imminent.
        </p>
        <ol>
          <li>Order replacement PSU immediately</li>
          <li>Schedule replacement during low-traffic window</li>
          <li>Back up critical data to external location</li>
          <li>Be prepared for emergency replacement if failures accelerate</li>
        </ol>
      </div>
    </div>
  </div>
  
  <div class="summary-stats">
    <h3>Analysis Summary</h3>
    <div class="stat-row">
      <span class="stat-label">Anomalies Detected:</span>
      <span class="stat-value">3</span>
    </div>
    <div class="stat-row">
      <span class="stat-label">Causal Chains Identified:</span>
      <span class="stat-value">1</span>
    </div>
    <div class="stat-row">
      <span class="stat-label">Root Causes Found:</span>
      <span class="stat-value">1 Primary + 1 Secondary</span>
    </div>
    <div class="stat-row">
      <span class="stat-label">Issues Not Covered by Rules:</span>
      <span class="stat-value">1 (New insight: acceleration pattern)</span>
    </div>
  </div>
</section>
```

---

## Decision Guide: Rule vs. AI

```
Question 1: Is there a known rule for this issue?
  YES → Use rule-based, trust result
  NO → Go to Q2

Question 2: Is there an obvious error signature?
  YES → Create rule, use going forward
  NO → Use AI analysis

Question 3: Is confidence important?
  CRITICAL → Require high (0.8+), investigate AI findings
  MODERATE → Show AI (0.6+), label confidence
  EXPLORATORY → Show all AI (0.4+), for investigation

Question 4: Should the user take action immediately?
  YES → Use high-confidence findings only (rules + top AI)
  NO → Show all findings, let user investigate

Question 5: Is this a known-unknown or unknown-unknown?
  Known-Unknown (know issue type, not cause) → Rules
  Unknown-Unknown (don't know issue type) → AI
```

---

## Confidence Threshold Recommendations

```
For IT Operations (High Confidence Required):
  Display only: ≥0.75 confidence
  Action recommended based on: ≥0.80
  
For Support Engineers (Medium Confidence):
  Display: ≥0.60 confidence
  Investigate further: 0.60-0.75
  Recommend action: ≥0.75
  
For Developers (Exploratory):
  Display all: ≥0.40 confidence
  Use for learning
  Help improve detection
```

---

## Expected Impact

```
Baseline (Rules Only):
  • Detects 95% of known issues
  • Misses 100% of unknown issues
  • False positive rate: <5%

With AI Enhancement:
  • Detects 95% of known issues (rules)
  • Detects 40-50% of unknown issues (AI)
  • Provides root cause for 60-70% of findings
  • False positive rate: 10-15% (mitigated by confidence)
  
Overall Improvement:
  • Coverage increase: Known + unknown detection
  • Actionability: Includes root cause analysis
  • Insight: Causal relationships, not just symptoms
```

---

## When NOT to Use AI Analysis

```
❌ Critical system where certainty essential
   → Stick with rules only
   
❌ Limited resources for validation
   → AI needs human oversight
   
❌ Users expect only definitive answers
   → AI confidence scores may confuse
   
❌ Bundle data incomplete or corrupted
   → AI accuracy suffers without data
```

---

## Next Steps

1. **Decide**: Rule-based only, or add AI layer?
2. **Design**: Use framework provided (4-phase approach)
3. **Prototype**: Phase 1 - anomaly detection first
4. **Validate**: Test with real bundles (JJeth, others)
5. **Integrate**: Add to pipeline gradually
6. **Learn**: Collect user feedback, improve models
7. **Deploy**: Production release with ongoing monitoring

