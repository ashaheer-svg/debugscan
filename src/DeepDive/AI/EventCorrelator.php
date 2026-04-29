<?php

declare(strict_types=1);

namespace App\DeepDive\AI;

use Psr\Log\LoggerInterface;

/**
 * Event Correlator: Build causal chains from anomalies
 *
 * PURPOSE:
 * Takes anomalies from Phase 1 (LogPreprocessor + TimeSeriesAnalyzer)
 * and correlates them into temporal/causal chains.
 *
 * Detects:
 * - Temporal proximity (events close in time)
 * - Domain causality (power → thermal, disk → performance)
 * - Cascade patterns (event triggers next)
 * - Prerequisite relationships (aged components before failure)
 *
 * OUTPUT:
 * CausalChain objects representing:
 * [Root Event] → [Contributing Factors] → [Cascade] → [Symptoms]
 *
 * EXAMPLES:
 * 1. PSU failure: Aged PSU → voltage instability → CPU throttle → thermal
 * 2. Disk degradation: SMART error → read slowdown → thermal increase
 * 3. Thermal cascade: Fan failure → temp rise → CPU throttle → slowdown
 *
 * @package App\DeepDive\AI
 */
final class EventCorrelator
{
    // Time window for correlation (seconds)
    private const TEMPORAL_WINDOW = 3600;    // 1 hour
    private const IMMEDIATE_WINDOW = 300;    // 5 minutes (strong causality)
    private const GRADUAL_WINDOW = 7200;     // 2 hours (slow cascade)

    private ?LoggerInterface $logger;

    // Domain causality rules
    private const CAUSALITY_RULES = [
        // PSU issues can cause thermal issues
        'PSU_FAILURE'          => ['THERMAL_EXCURSION', 'VOLTAGE_INSTABILITY'],
        'PSU_NOT_DETECTED'     => ['THERMAL_EXCURSION', 'SYSTEM_SLOWDOWN'],
        'VOLTAGE_INSTABILITY'  => ['THERMAL_EXCURSION', 'CPU_THROTTLE'],

        // Disk issues can cascade
        'DISK_ERROR'           => ['DISK_ANOMALY', 'PERFORMANCE_DEGRADATION'],
        'DISK_ANOMALY'         => ['SYSTEM_SLOWDOWN', 'THERMAL_EXCURSION'],

        // Fan failures cause thermal
        'FAN_FAILURE'          => ['THERMAL_EXCURSION', 'SYSTEM_SLOWDOWN'],

        // Thermal issues cause system effects
        'THERMAL_EXCURSION'    => ['CPU_THROTTLE', 'SYSTEM_SLOWDOWN', 'FAN_FAILURE'],

        // RAID issues cause performance
        'RAID_ANOMALY'         => ['PERFORMANCE_DEGRADATION', 'SYSTEM_SLOWDOWN'],
    ];

    /**
     * Initialize correlator
     *
     * @param ?LoggerInterface $logger Optional PSR-3 logger
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Correlate anomalies into causal chains
     *
     * FLOW:
     * 1. Convert anomalies to timeline
     * 2. Identify potential root events (early in chain)
     * 3. For each root, trace forward causality
     * 4. Build chains with contributing factors and cascade
     * 5. Calculate confidence in each chain
     * 6. Merge overlapping chains
     * 7. Return structured CausalChain objects
     *
     * @param array $anomalies Anomaly records from Phase 1
     * @param array $clusters Anomaly clusters (optional, from TimeSeriesAnalyzer)
     *
     * @return array<CausalChain> Array of causal chains
     */
    public function correlateAnomalies(array $anomalies, array $clusters = []): array
    {
        $this->log('info', 'Starting event correlation with ' . count($anomalies) . ' anomalies');

        if (empty($anomalies)) {
            return [];
        }

        // === Step 1: Convert to timeline ===
        $timeline = $this->buildTimeline($anomalies);
        $this->log('debug', 'Built timeline with ' . count($timeline) . ' events');

        // === Step 2: Identify potential roots ===
        $potentialRoots = $this->identifyPotentialRoots($timeline);
        $this->log('debug', 'Identified ' . count($potentialRoots) . ' potential root events');

        // === Step 3: Build chains from roots ===
        $chains = [];
        foreach ($potentialRoots as $rootIdx => $rootEvent) {
            $chain = $this->traceChainFromRoot($timeline, $rootIdx);
            if ($chain !== null && $chain->eventCount() > 1) {
                $chains[] = $chain;
            }
        }

        $this->log('info', 'Built ' . count($chains) . ' initial causal chains');

        // === Step 4: Merge overlapping chains ===
        $chains = $this->mergeOverlappingChains($chains);
        $this->log('info', 'After merge: ' . count($chains) . ' final chains');

        // === Step 5: Use clusters to boost confidence ===
        if (!empty($clusters)) {
            $chains = $this->refineWithClusters($chains, $clusters);
        }

        return $chains;
    }

    /**
     * Build timeline from anomalies
     *
     * Sorts by timestamp, adds index for correlation
     *
     * @param array $anomalies Raw anomalies
     *
     * @return array Sorted timeline with indices
     */
    private function buildTimeline(array $anomalies): array
    {
        // Sort by timestamp
        usort($anomalies, function($a, $b) {
            $timeA = strtotime($a['timestamp'] ?? '0') ?: 0;
            $timeB = strtotime($b['timestamp'] ?? '0') ?: 0;
            return $timeA <=> $timeB;
        });

        // Add timeline index
        foreach ($anomalies as $idx => &$anomaly) {
            $anomaly['_timeline_idx'] = $idx;
        }

        return $anomalies;
    }

    /**
     * Identify events that could be root causes
     *
     * Root event candidates:
     * - Early in timeline (happens first)
     * - High severity or importance
     * - Known triggering anomaly types
     *
     * @param array $timeline Sorted anomalies
     *
     * @return array Root event candidates (timeline index => event)
     */
    private function identifyPotentialRoots(array $timeline): array
    {
        $roots = [];

        // Anomaly types that are strong root indicators
        $rootIndicators = [
            'PSU_NOT_DETECTED'   => 0.98,
            'PSU_FAILURE'        => 0.95,
            'VOLTAGE_INSTABILITY' => 0.90,
            'FAN_FAILURE'        => 0.88,
            'DISK_ERROR'         => 0.85,
            'RAID_ANOMALY'       => 0.80,
        ];

        foreach ($timeline as $idx => $event) {
            $type = $event['type'] ?? '';

            // Check if this is a known root indicator
            if (isset($rootIndicators[$type])) {
                $roots[$idx] = $event;
            }

            // Or if it's the first event of its type
            $isFirstOfType = !isset($roots[0]) ||
                            !array_key_exists('type', $roots[0]) ||
                            $roots[0]['type'] !== $type;

            if ($isFirstOfType && $idx === 0) {
                $roots[$idx] = $event;
            }
        }

        return $roots;
    }

    /**
     * Trace causality chain forward from a root event
     *
     * ALGORITHM:
     * 1. Start with root anomaly
     * 2. Look for causally-related events after root (within time window)
     * 3. Classify events as: contributing factor, intermediate, or symptom
     * 4. Continue tracing forward
     * 5. Calculate confidence based on matches
     *
     * @param array $timeline All anomalies on timeline
     * @param int $rootIdx Index of root event
     *
     * @return ?CausalChain Built chain, or null if insufficient events
     */
    private function traceChainFromRoot(array $timeline, int $rootIdx): ?CausalChain
    {
        $rootEvent = $timeline[$rootIdx];
        $chainId = 'chain_' . $rootIdx . '_' . time();

        // Determine chain type from root event
        $chainType = $this->determineChainType($rootEvent);

        // Create chain
        $chain = new CausalChain($chainId, $chainType, $rootEvent);
        $rootTime = strtotime($rootEvent['timestamp'] ?? '0') ?: 0;

        if ($rootTime === 0) {
            return null;
        }

        $usedIndices = [$rootIdx];
        $lastTime = $rootTime;
        $eventCount = 1;

        // Look for related events after root
        for ($i = $rootIdx + 1; $i < count($timeline); $i++) {
            $event = $timeline[$i];
            $eventTime = strtotime($event['timestamp'] ?? '0') ?: 0;

            if ($eventTime === 0) continue;

            // Check if within time window
            if (($eventTime - $lastTime) > self::TEMPORAL_WINDOW) {
                break; // Too far in future
            }

            // Check if causally related
            $causalMatch = $this->checkCausalRelation($rootEvent['type'] ?? '', $event['type'] ?? '');

            if ($causalMatch['related']) {
                $delay = $eventTime - $rootTime;

                if ($delay <= self::IMMEDIATE_WINDOW) {
                    // Contributing factor or direct effect
                    if ($this->isContributingFactor($rootEvent, $event)) {
                        // Actually happened before root, so skip
                        continue;
                    } else {
                        $chain->addIntermediateEvent($event, count($chain->intermediateEvents()) + 1, $delay);
                    }
                } else {
                    // Longer-term effect
                    $chain->addObservableSymptom($event, $causalMatch['impact'] ?? '');
                }

                $usedIndices[] = $i;
                $lastTime = $eventTime;
                $eventCount++;
            }
        }

        // If chain is too short, return null
        if ($eventCount < 2) {
            return null;
        }

        // Calculate confidence
        $confidence = $this->calculateChainConfidence($chain);
        $chain->setConfidence($confidence);

        return $chain;
    }

    /**
     * Determine chain type from root event
     *
     * @param array $rootEvent Root anomaly
     *
     * @return string Chain type constant
     */
    private function determineChainType(array $rootEvent): string
    {
        $type = $rootEvent['type'] ?? '';

        return match($type) {
            'PSU_NOT_DETECTED', 'PSU_FAILURE', 'REDUNDANT_PSU_FAILURE'
                => CausalChain::TYPE_PSU_FAILURE,

            'THERMAL_EXCURSION', 'FAN_FAILURE'
                => CausalChain::TYPE_THERMAL_CASCADE,

            'DISK_ERROR', 'DISK_ANOMALY'
                => CausalChain::TYPE_DISK_DEGRADATION,

            'VOLTAGE_INSTABILITY'
                => CausalChain::TYPE_POWER_DELIVERY,

            'RAID_ANOMALY'
                => CausalChain::TYPE_HARDWARE_FAILURE,

            default => CausalChain::TYPE_UNKNOWN,
        };
    }

    /**
     * Check if two anomaly types have causal relationship
     *
     * @param string $sourceType Root anomaly type
     * @param string $targetType Following anomaly type
     *
     * @return array{related: bool, impact: string}
     */
    private function checkCausalRelation(string $sourceType, string $targetType): array
    {
        $related = false;
        $impact = '';

        if (isset(self::CAUSALITY_RULES[$sourceType])) {
            if (in_array($targetType, self::CAUSALITY_RULES[$sourceType])) {
                $related = true;
                $impact = "Caused by {$sourceType}";
            }
        }

        return ['related' => $related, 'impact' => $impact];
    }

    /**
     * Determine if event is a contributing factor vs direct effect
     *
     * Contributing factors precede the root cause and enable it.
     * For example: aged PSU component (factor) before voltage drop (root)
     *
     * @param array $rootEvent Root anomaly
     * @param array $event Event to classify
     *
     * @return bool True if contributing factor
     */
    private function isContributingFactor(array $rootEvent, array $event): bool
    {
        $eventTime = strtotime($event['timestamp'] ?? '0') ?: 0;
        $rootTime = strtotime($rootEvent['timestamp'] ?? '0') ?: 0;

        // Contributing factors happen before root
        if ($eventTime >= $rootTime) {
            return false;
        }

        $rootType = $rootEvent['type'] ?? '';
        $eventType = $event['type'] ?? '';

        // Known prerequisite relationships
        $prerequisites = [
            'PSU_FAILURE'  => ['VOLTAGE_INSTABILITY'],
            'THERMAL_EXCURSION' => ['FAN_FAILURE'],
            'DISK_ANOMALY' => ['DISK_ERROR'],
        ];

        return isset($prerequisites[$rootType]) &&
               in_array($eventType, $prerequisites[$rootType]);
    }

    /**
     * Calculate confidence in a causal chain
     *
     * Factors:
     * - Root event confidence (40%)
     * - Number of supporting events (30%)
     * - Temporal coherence (15%)
     * - Causality alignment (15%)
     *
     * @param CausalChain $chain Built chain
     *
     * @return float Confidence 0.0-1.0
     */
    private function calculateChainConfidence(CausalChain $chain): float
    {
        $factors = [];

        // Root confidence
        $rootEvent = $chain->rootEvent();
        $rootConfidence = floatval($rootEvent['confidence'] ?? 0.7);
        $factors['root'] = ['weight' => 0.40, 'score' => $rootConfidence];

        // Supporting events
        $eventCount = count($chain->intermediateEvents()) + count($chain->observableSymptoms());
        $supportScore = min(1.0, $eventCount / 4); // 1.0 at 4+ events
        $factors['support'] = ['weight' => 0.30, 'score' => $supportScore];

        // Temporal coherence (events within reasonable time span)
        $timespan = $chain->timeSpan();
        $temporalScore = 1.0;
        if ($timespan > 3600) $temporalScore = 0.8;  // >1hr = slightly less confident
        if ($timespan > 7200) $temporalScore = 0.6;  // >2hr = less confident
        $factors['temporal'] = ['weight' => 0.15, 'score' => $temporalScore];

        // Causality alignment (events match known patterns)
        $causalityScore = 0.8; // Default good
        if ($chain->type() === CausalChain::TYPE_UNKNOWN) {
            $causalityScore = 0.5; // Unknown patterns less confident
        }
        $factors['causality'] = ['weight' => 0.15, 'score' => $causalityScore];

        // Weighted sum
        $weighted = 0;
        $totalWeight = 0;
        foreach ($factors as $factor) {
            $weighted += $factor['weight'] * $factor['score'];
            $totalWeight += $factor['weight'];
        }

        return $totalWeight > 0 ? $weighted / $totalWeight : 0.5;
    }

    /**
     * Merge chains that share events
     *
     * Prevents duplicate analysis when events are part of multiple chains
     *
     * @param array $chains Built chains
     *
     * @return array Merged chains
     */
    private function mergeOverlappingChains(array $chains): array
    {
        if (count($chains) <= 1) {
            return $chains;
        }

        $merged = [];
        $used = [];

        foreach ($chains as $i => $chain1) {
            if (isset($used[$i])) continue;

            $currentChain = $chain1;

            // Look for overlapping chains
            foreach ($chains as $j => $chain2) {
                if ($i === $j || isset($used[$j])) continue;

                // Check if chains share events
                if ($this->chainsOverlap($chain1, $chain2)) {
                    // Merge: choose the stronger chain or combine them
                    if ($chain2->calculateStrength() > $currentChain->calculateStrength()) {
                        $currentChain = $chain2;
                    }
                    $used[$j] = true;
                }
            }

            $merged[] = $currentChain;
            $used[$i] = true;
        }

        return $merged;
    }

    /**
     * Check if two chains overlap
     *
     * @param CausalChain $chain1 First chain
     * @param CausalChain $chain2 Second chain
     *
     * @return bool True if chains share events
     */
    private function chainsOverlap(CausalChain $chain1, CausalChain $chain2): bool
    {
        $allEventsChain1 = $this->extractEventIds($chain1);
        $allEventsChain2 = $this->extractEventIds($chain2);

        $overlap = array_intersect($allEventsChain1, $allEventsChain2);
        return !empty($overlap);
    }

    /**
     * Extract unique event identifiers from chain
     *
     * @param CausalChain $chain Chain to extract from
     *
     * @return array Event IDs
     */
    private function extractEventIds(CausalChain $chain): array
    {
        $ids = [];

        $root = $chain->rootEvent();
        $ids[] = md5(json_encode($root['timestamp'] . $root['type'] ?? ''));

        foreach ($chain->intermediateEvents() as $event) {
            $ids[] = md5(json_encode($event['anomaly']['timestamp'] . $event['anomaly']['type'] ?? ''));
        }

        foreach ($chain->observableSymptoms() as $event) {
            $ids[] = md5(json_encode($event['anomaly']['timestamp'] . $event['anomaly']['type'] ?? ''));
        }

        return $ids;
    }

    /**
     * Refine confidence using cluster information
     *
     * Clusters indicate sustained/related issues, boosting confidence
     *
     * @param array $chains Causal chains
     * @param array $clusters Anomaly clusters
     *
     * @return array Refined chains
     */
    private function refineWithClusters(array $chains, array $clusters): array
    {
        foreach ($chains as $chain) {
            foreach ($clusters as $cluster) {
                // Check if chain matches cluster
                if ($cluster['anomaly_count'] >= 2 &&
                    strpos(json_encode($cluster['anomalies']), $chain->rootEvent()['type'] ?? '') !== false) {

                    // Boost confidence based on cluster severity
                    $boost = 0.05; // Small boost
                    if ($cluster['severity'] === 'CRITICAL') $boost = 0.15;
                    elseif ($cluster['severity'] === 'HIGH') $boost = 0.10;

                    $newConfidence = min(0.99, $chain->confidence() + $boost);
                    $chain->setConfidence($newConfidence);
                }
            }
        }

        return $chains;
    }

    /**
     * Log a message
     *
     * @param string $level Log level
     * @param string $message Message
     */
    private function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->log($level, '[event-correlator] ' . $message);
        }
    }
}
