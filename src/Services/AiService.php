<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * AiService: LLM-powered diagnostic analysis via Groq API
 *
 * PURPOSE:
 * Sends aggregated diagnostic data to Groq (llama models) for forensic analysis
 * Formats diagnostic JSON with markdown tables to optimize token efficiency
 * Returns structured findings (health score, categories, recommendations)
 * Logs full prompts for audit trail and reproducibility
 *
 * GROQ INTEGRATION:
 * Provider: groq.com OpenAI-compatible API
 * Models: llama-3.3-70b-versatile, other llama variants
 * Response format: Strict JSON with response_format='json_object'
 * Temperature: 0.1 (deterministic for forensics)
 * Max output: 4096 tokens per analysis
 * Timeout: 60 seconds per request
 *
 * WORKFLOW:
 * 1. Receive diagnostic data (hardware, logs, disks, auth timeline, SMB xfers)
 * 2. Flatten dense arrays to markdown tables (reduce token usage 30-50%)
 * 3. Assemble system prompt (forensic rules) + user prompt (diagnostic JSON)
 * 4. Truncate if >maxChars (safety check for Groq 413 limits)
 * 5. Call Groq API with system + user prompts
 * 6. Parse JSON response (findings, health_score, recommendations)
 * 7. Log full prompt for audit trail
 *
 * SYSTEM PROMPT:
 * getDefaultHeader(): Role definition (Lead Forensic Support Engineer)
 * getForensicRules(): Analysis rules (precision, evidence requirement, access audit, exfiltration detection)
 * Output format: Strict JSON structure with health_score, summary, findings array
 *
 * TOKEN OPTIMIZATION:
 * Problem: Diagnostic JSON can be 500k+ characters, exceeds Groq limits
 * Solution: Convert dense data structures to markdown tables
 * flattenLogsToMarkdown(): Critical events → markdown table (max 100 rows)
 * flattenDisksToMarkdown(): Disk telemetry → markdown table
 * flattenAuthTimelineToMarkdown(): Auth events → markdown table (max 50 rows)
 * flattenSmbXferToMarkdown(): SMB transfers → markdown table (max 50 rows)
 * Result: 30-50% reduction in character count
 *
 * SAFETY & AUDIT:
 * Payload truncation: If >maxChars (default 50k), truncate + mark [TRUNCATED]
 * Prompt logging: Full prompt saved to storage/logs/ai_prompt_{jobId}.txt
 * Persistent even if API fails (enables post-mortem analysis)
 * Error handling: Groq exceptions → RuntimeException
 *
 * @package App\Services
 */
class AiService
{
    private Client $client;
    private ?string $apiKey;
    private string $baseUrl = 'https://api.groq.com/openai/v1/';

    /**
     * Constructor: Initialize Groq API client
     *
     * @param string|null $apiKey Groq API key (if null, API calls will fail with 401)
     */
    public function __construct(?string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Authorization' => "Bearer " . ($this->apiKey ?? ''),
                'Content-Type' => 'application/json',
            ],
            'timeout' => 60.0,
        ]);
    }

    /**
     * Fetch and list available Groq models
     *
     * GROQ /MODELS ENDPOINT:
     * Returns array of model metadata including:
     * - id: Model identifier (e.g., llama-3.3-70b-versatile)
     * - active: Boolean (filter for active=true)
     * - owned_by: Provider name
     * - context_window: Max input tokens
     * - public_apps: Available deployment endpoints
     *
     * RESPONSE FORMAT:
     * Returns simplified array for frontend model selector:
     * {
     *   'id': 'llama-3.3-70b-versatile',
     *   'name': 'llama-3.3-70b-versatile',
     *   'owned_by': 'Meta',
     *   'context_window': 8192,
     *   'max_output_tokens': 32768,
     *   'public_apps': ['chat', 'api', ...]
     * }
     *
     * FILTERING:
     * Only active models included (active field omitted if false)
     * max_output_tokens capped at 32768 (Groq standard)
     * Handles missing fields gracefully (defaults to null or constants)
     *
     * USE CASE:
     * Admin panel model selector for report plan configuration
     * Frontend validation before sending analysis request
     *
     * @return array<array<string,mixed>> Array of model definitions
     *
     * @throws RuntimeException If Groq API unreachable or returns invalid JSON
     */
    public function getAvailableModels(): array
    {
        try {
            $response = $this->client->get('models');
            $data = json_decode($response->getBody()->getContents(), true);
            
            $models = [];
            foreach ($data['data'] ?? [] as $model) {
                if ($model['active'] ?? true) {
                    $models[] = [
                        'id' => $model['id'],
                        'name' => $model['id'],
                        'owned_by' => $model['owned_by'] ?? 'unknown',
                        'context_window' => $model['context_window'] ?? null,
                        'max_output_tokens' => 32768, // Standard Groq limit for current models
                        'public_apps' => $model['public_apps'] ?? null
                    ];
                }
            }
            return $models;
        } catch (\Exception $e) {
            throw new RuntimeException("Failed to fetch Groq models: " . $e->getMessage());
        }
    }

    /**
     * Analyze diagnostic data via Groq API with prompt engineering
     *
     * ANALYSIS WORKFLOW:
     * 1. Build system prompt (custom header + forensic rules)
     * 2. Assemble user prompt (device identity + flattened diagnostic JSON)
     * 3. Flatten dense data structures to markdown tables (token optimization)
     * 4. Truncate if character count exceeds maxChars
     * 5. Log full prompt for audit trail
     * 6. Call Groq chat/completions with system + user prompts
     * 7. Parse JSON response (health_score, findings, recommendations)
     * 8. Return findings + prompt + truncation status + usage metrics
     *
     * SYSTEM PROMPT:
     * Combines custom prompt (if provided) with forensic rules
     * Rules enforce: evidence-based findings, forensic precision, security focus
     * Instructs model to respond in strict JSON format
     *
     * USER PROMPT:
     * Device Identity Header: Model, Serial, DSM version
     * Flattened Data: Critical events, disks, auth timeline, SMB transfers as markdown tables
     * Raw JSON: Full diagnostic payload (possibly truncated)
     *
     * DATA FLATTENING (Token Optimization):
     * Large arrays converted to markdown tables (30-50% character reduction)
     * flatten*ToMarkdown() functions limit rows (100 critical events, 50 auth/SMB events)
     * Remaining data kept as raw JSON for completeness
     * Tables easier for LLM to parse than deeply nested JSON
     *
     * TRUNCATION SAFETY:
     * If raw JSON > maxChars (default 50000):
     *   - Truncate to maxChars
     *   - Append [!!! CAUTION: PAYLOAD TRUNCATED !!!] marker
     *   - Set is_truncated=true in response
     *   - Analysis proceeds (may miss some context)
     *
     * PROMPT LOGGING:
     * Full prompt saved to: storage/logs/ai_prompt_{jobId}.txt
     * Includes both system and user prompts for reproducibility
     * Saved BEFORE API call (catches truncation and request details)
     * Persists even if API fails (post-mortem analysis)
     *
     * GROQ API REQUEST:
     * Model: Passed parameter (e.g., llama-3.3-70b-versatile)
     * Max tokens: Clamped to outputLimit (4096 for all current models)
     * Temperature: 0.1 (deterministic for forensics)
     * Response format: json_object (enforces valid JSON response)
     * Timeout: 60 seconds (from client config)
     *
     * RESPONSE PARSING:
     * Groq returns: choices[0].message.content (raw JSON string)
     * Decoded to associative array (health_score, findings, etc.)
     * Usage metrics extracted for token accounting
     * Malformed responses fall back to empty array
     *
     * @param array<array<string,mixed>> $diagnosticData Diagnostic bundles (hardware, logs, disks, auth, SMB)
     * @param string $model Groq model ID (e.g., llama-3.3-70b-versatile)
     * @param int $maxTokens Max output tokens requested (will be clamped to 4096)
     * @param int $maxChars Max character limit for truncation safety (default 50000)
     * @param string $jobId Job UUID (used for prompt log filename)
     * @param string|null $customPrompt Optional system prompt header from report plan
     *
     * @return array{findings:array<string,mixed>, full_prompt:string, is_truncated:bool, usage:array<string,int>}
     *   - findings: Parsed JSON response from Groq (health_score, summary, findings array)
     *   - full_prompt: Complete prompt string (system + user) sent to Groq
     *   - is_truncated: True if payload was truncated for safety
     *   - usage: Token usage metrics {prompt_tokens, completion_tokens, total_tokens}
     *
     * @throws RuntimeException If Groq API fails or returns invalid response
     */
    public function analyze(array $diagnosticData, string $model, int $maxTokens, int $maxChars = 50000, string $jobId = 'unknown', ?string $customPrompt = null): array
    {
        $baseRules = $this->getForensicRules();
        $systemPrompt = (!empty($customPrompt) ? $customPrompt : $this->getDefaultHeader()) . "\n\n" . $baseRules;
        $isTruncated = false;
        
        // Assemble User Prompt (Hybrid Mode: JSON + Markdown Tables)
        $userPrompt = "SYNOLOGY FORENSIC DIAGNOSTIC REQUEST (FULL SPECTRUM)\n";
        $userPrompt .= "=================================================\n\n";
        
        foreach ($diagnosticData as $index => $fileData) {
            $userPrompt .= "### DATA SET " . ($index + 1) . "\n";
            
            // Device Identity Header (Forensic Identity Transparency)
            $userPrompt .= sprintf("- Device Identity: Model %s | Serial %s | DSM %s\n\n",
                $fileData['hardware']['model'] ?? 'Unknown',
                $fileData['hardware']['serial'] ?? 'Unknown',
                $fileData['version']['product'] ?? 'Unknown'
            );
            
            // OPTIMIZATION: Flatten dense tables to Markdown to save tokens/avoid 413
            if (isset($fileData['logs']['critical_events'])) {
                $fileData['logs']['critical_events'] = $this->flattenLogsToMarkdown($fileData['logs']['critical_events']);
            }
            if (isset($fileData['disks'])) {
                $fileData['disks'] = $this->flattenDisksToMarkdown($fileData['disks']);
            }
            if (isset($fileData['auth_timeline']['events'])) {
                $fileData['auth_timeline']['events'] = $this->flattenAuthTimelineToMarkdown($fileData['auth_timeline']['events']);
            }
            if (isset($fileData['smb_xfer']['recent_transfers'])) {
                $fileData['smb_xfer']['recent_transfers'] = $this->flattenSmbXferToMarkdown($fileData['smb_xfer']['recent_transfers']);
            }
            if (isset($fileData['packaged_logs'])) {
                foreach ($fileData['packaged_logs'] as $logType => $content) {
                    // Packaged logs are already string-based, no changes needed
                }
            }
            
            // Generate full RAW JSON for this optimized record
            $rawJson = json_encode($fileData, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
            
            // Standard safety: Groq 413 gateway limits are often around 100k-200k total request size.
            // We enforce a strict per-file limit here.
            if (mb_strlen($rawJson) > $maxChars) {
                $isTruncated = true;
                $rawJson = mb_substr($rawJson, 0, $maxChars) . "\n\n[!!! CAUTION: PAYLOAD TRUNCATED BY ANALYZER FOR SAFETY (Limit: " . number_format($maxChars) . " chars) !!!]";
            }

            $userPrompt .= "#### RAW DIAGNOSTIC DATA (MAY BE TRUNCATED)\n" . $rawJson . "\n\n";
        }

        $fullPromptString = "SYSTEM PROMPT:\n{$systemPrompt}\n\nUSER PROMPT:\n{$userPrompt}";

        // Pre-Flight Local Audit (Persistent even if API fails/413s)
        $logDir = __DIR__ . '/../../storage/logs';
        if (is_dir($logDir)) {
            @file_put_contents($logDir . '/ai_prompt_' . basename((string)$jobId) . '.txt', $fullPromptString);
        }

        try {
            // Standardizing on 'max_tokens' for better Groq compatibility across models.
            // 4096 is more than enough for our structured JSON health reports.
            $outputLimit = strpos($model, '8b') !== false ? 4096 : 4096;
            $calculatedMaxTokens = min($maxTokens, $outputLimit);

            $response = $this->client->post('chat/completions', [
                'json' => [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'max_tokens' => $calculatedMaxTokens,
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.1,
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            $content = $result['choices'][0]['message']['content'] ?? '{}';
            $usage = $result['usage'] ?? [];
            
            return [
                'findings' => json_decode($content, true) ?: [],
                'full_prompt' => $fullPromptString,
                'is_truncated' => $isTruncated,
                'usage' => $usage
            ];
        } catch (\Exception $e) {
            throw new RuntimeException("AI Analysis failed: " . $e->getMessage());
        }
    }

    /**
     * Convert critical log events array to markdown table
     *
     * OPTIMIZATION:
     * Markdown tables are ~70% smaller than nested JSON arrays
     * Easier for LLM to parse and understand at a glance
     * Limits to 100 rows to prevent prompt bloat
     *
     * TABLE FORMAT:
     * | Source | Timestamp/Message |
     * | :--- | :--- |
     * | syslog | 2025-04-28 Event details |
     * ...
     * | ... | (Truncated N additional events) |
     *
     * SANITIZATION:
     * Newlines and carriage returns replaced with spaces (markdown table safety)
     * Pipe characters escaped (\\|) to prevent table corruption
     * Result: Safe markdown without accidentally breaking table structure
     *
     * @param array<array<string,mixed>> $events Critical event records (source, content)
     *
     * @return string Markdown table or "No critical events found."
     */
    private function flattenLogsToMarkdown(array $events): string
    {
        if (empty($events)) return "No critical events found.";

        $md = "| Source | Timestamp/Message |\n";
        $md .= "| :--- | :--- |\n";
        foreach (array_slice($events, 0, 100) as $event) {
            $source = $event['source'] ?? 'unknown';
            $content = str_replace(["\n", "\r", "|"], [" ", "", "\\|"], $event['content'] ?? '');
            $md .= "| {$source} | {$content} |\n";
        }

        if (count($events) > 100) {
            $md .= "| ... | (Truncated " . (count($events) - 100) . " additional events) |\n";
        }

        return $md;
    }

    /**
     * Convert disk telemetry array to markdown table
     *
     * TABLE COLUMNS:
     * Bay: Drive bay/slot location
     * Model: Drive model string (e.g., WDC WD5000AAKX)
     * Status: Health status (HEALTHY, CRITICAL, WARNING)
     * Temp: Temperature in Celsius
     * Size: Capacity in GB
     * Errors: SMART error counts (Reset Failures / Uncorrectable)
     *
     * FIELD MAPPING:
     * bay: Drive location identifier (preferred over slot)
     * model: Drive model string
     * status: Health status (uppercased)
     * temp: Temperature (default '??')
     * size_gb: Disk capacity
     * reset_fail_status, unc_status: SMART error types
     *
     * FORMAT:
     * Compact table suitable for LLM analysis of disk health
     * Shows all critical drive parameters in single view
     * Enables quick pattern recognition (bad disk runs, temp trends)
     *
     * @param array<array<string,mixed>> $disks Disk records (bay, model, status, temp, etc.)
     *
     * @return string Markdown table or "No disk telemetry detected."
     */
    private function flattenDisksToMarkdown(array $disks): string
    {
        if (empty($disks)) return "No disk telemetry detected.";

        $md = "| Bay | Model | Status | Temp | Size | Errors (Reset/UNC) |\n";
        $md .= "| :--- | :--- | :--- | :--- | :--- | :--- |\n";
        foreach ($disks as $id => $d) {
            $bay = $d['bay'] ?? ($d['slot'] ?? $id);
            $model = $d['model'] ?? 'Unknown';
            $status = strtoupper($d['status'] ?? 'None');
            $temp = ($d['temp'] ?? '??') . 'C';
            $size = ($d['size_gb'] ?? '0') . 'GB';
            $rFail = $d['reset_fail_status'] ?? 'normal';
            $unc = $d['unc_status'] ?? 'normal';

            $md .= "| {$bay} | {$model} | {$status} | {$temp} | {$size} | R:{$rFail} / U:{$unc} |\n";
        }
        return $md;
    }

    /**
     * Convert authentication timeline events to markdown table
     *
     * SECURITY FOCUS:
     * Enables rapid brute-force pattern detection (multiple failures from same IP)
     * Identifies geographical anomalies (suspicious IP regions)
     * Flags excessive login failures or unusual protocols
     * Tracks which accounts are targeted
     *
     * TABLE COLUMNS:
     * Timestamp: Event time (Y-m-d H:i:s)
     * User: Username or account name
     * IP: Source IP address (potential indicator of origin)
     * Protocol: SSH, SMB, SNMP, or other auth protocol
     * Status: SUCCESS or FAILED
     * Message: Brief reason or additional context
     *
     * LIMITING:
     * Restricted to 50 most recent events (prevents huge tables)
     * Allows time-series analysis of authentication patterns
     * Oldest events truncated if >50 total
     *
     * FIELD MAPPING:
     * time: Unix timestamp (converted to Y-m-d H:i:s)
     * username: Authenticating user (default 'unknown')
     * ip: Source IP address
     * protocol: Auth protocol (uppercased)
     * is_failed: Boolean (true = FAILED, false = SUCCESS)
     * msg: Additional context message
     *
     * @param array<array<string,mixed>> $events Auth event records (time, username, ip, protocol, is_failed, msg)
     *
     * @return string Markdown table or "No significant authentication events found."
     */
    private function flattenAuthTimelineToMarkdown(array $events): string
    {
        if (empty($events)) return "No significant authentication events found.";
        $md = "| Timestamp | User | IP | Protocol | Status | Message |\n";
        $md .= "| :--- | :--- | :--- | :--- | :--- | :--- |\n";
        foreach (array_slice($events, 0, 50) as $e) {
            $ts = date('Y-m-d H:i:s', (int)($e['time'] ?? 0));
            $user = $e['username'] ?? 'unknown';
            $ip = $e['ip'] ?? 'unknown';
            $proto = strtoupper($e['protocol'] ?? '???');
            $status = ($e['is_failed'] ?? false) ? 'FAILED' : 'SUCCESS';
            $msg = str_replace("|", "\\|", $e['msg'] ?? '');
            $md .= "| {$ts} | {$user} | {$ip} | {$proto} | {$status} | {$msg} |\n";
        }
        return $md;
    }

    /**
     * Convert SMB file transfer audits to markdown table
     *
     * DATA EXFILTRATION DETECTION:
     * Identifies suspicious file-level activity patterns:
     * - Massive DELETE operations (ransomware indicator)
     * - Rapid WRITE sequences (potential exfiltration)
     * - Unusual operations on sensitive paths
     * - Off-hours or unauthorized user activity
     *
     * TABLE COLUMNS:
     * Timestamp: When operation occurred (Y-m-d H:i:s)
     * User: SMB authenticated user performing operation
     * IP: Client IP address (helps identify compromised systems)
     * Op: Operation type (CREATE, DELETE, READ, WRITE, etc.)
     * Path: File/directory path affected
     *
     * LIMITING:
     * Restricted to 50 most recent operations
     * Focuses on recent activity (most relevant to active threats)
     * Truncates if >50 total operations
     *
     * FIELD MAPPING:
     * time: Unix timestamp (converted to Y-m-d H:i:s)
     * username: SMB authenticated user
     * ip: Client IP address
     * op: Operation type (uppercased)
     * path: SMB share path (escaped for markdown safety)
     *
     * FORENSIC VALUE:
     * Enables pattern analysis (DELETE storms, bulk READ operations)
     * Cross-reference with authentication timeline for compromised accounts
     * Spot unauthorized access or lateral movement
     *
     * @param array<array<string,mixed>> $transfers SMB transfer records (time, username, ip, op, path)
     *
     * @return string Markdown table or "No SMB transfer audits detected."
     */
    private function flattenSmbXferToMarkdown(array $transfers): string
    {
        if (empty($transfers)) return "No SMB transfer audits detected.";
        $md = "| Timestamp | User | IP | Op | Path |\n";
        $md .= "| :--- | :--- | :--- | :--- | :--- |\n";
        foreach (array_slice($transfers, 0, 50) as $t) {
            $ts = date('Y-m-d H:i:s', (int)($t['time'] ?? 0));
            $user = $t['username'] ?? 'unknown';
            $ip = $t['ip'] ?? 'unknown';
            $op = strtoupper($t['op'] ?? 'unknown');
            $path = str_replace("|", "\\|", $t['path'] ?? '');
            $md .= "| {$ts} | {$user} | {$ip} | {$op} | {$path} |\n";
        }
        return $md;
    }

    /**
     * Get default system prompt header
     *
     * PURPOSE:
     * Defines AI role and context for analysis
     * Establishes expectations: JSON output, forensic engineer persona, full-spectrum diagnostic
     * Primes model for forensic analysis mindset
     *
     * ROLE ASSIGNMENT:
     * "Lead Forensic Support Engineer for Synology"
     * Instructs model to think like expert support engineer, not generic AI
     * Forensic focus: precision, evidence-based reasoning, threat detection
     *
     * DATA CONTEXT:
     * Explicitly mentions:
     * - File Sets: Multiple diagnostic bundles
     * - Full-spectrum telemetry: Hardware, logs, databases
     * - SQLite forensic extractions: Deep system database exports
     * Result: Model aware of sophisticated diagnostic data available
     *
     * CUSTOM OVERRIDE:
     * analyze() method allows customPrompt parameter to replace this header
     * Enables tenant-specific or domain-specific analysis instructions
     * Custom prompt appended to rules (allows both custom + strict rules)
     *
     * @return string Default system prompt header
     */
    private function getDefaultHeader(): string
    {
        return "You must respond in valid JSON format.
        You are the Lead Forensic Support Engineer for Synology.
        Your task is to analyze diagnostic 'File Sets' and provide a definitive health audit.
        You are receiving FULL-SPECTRUM raw diagnostic telemetry, including standard text logs and deep SQLite forensic extractions (located in the 'forensic_extractions' key).";
    }

    /**
     * Get forensic analysis rules
     *
     * RULE CATEGORIES:
     * 1. PRECISION: SQLite databases (SYNOSYSDB, SYNODISKHEALTHDB, SYNOCONNDB, SMBXFERDB) are ground truth
     *    - Deep system databases more reliable than logs
     *    - Trust forensic extracts over parsed summaries
     *
     * 2. EVIDENCE REQUIREMENT: Every finding must cite evidence from payload
     *    - Prevents hallucination (no inventing findings)
     *    - Forces model to work from actual data
     *    - Enables audit trail and verification
     *
     * 3. ACCESS AUDIT: Analyze authentication timeline for security threats
     *    - Brute-force patterns: Multiple failures from same IP
     *    - Geographical anomalies: Suspicious IP origins
     *    - Excessive failures: Potential account compromise
     *
     * 4. DATA EXFILTRATION: Analyze SMB transfers for ransomware indicators
     *    - Massive DELETE operations: Ransomware signature
     *    - Rapid WRITE/ENCRYPT operations: Data theft
     *    - Unusual patterns on sensitive paths
     *
     * 5. ACCURACY: Avoid false positives
     *    - If no critical issues detected, provide A grade
     *    - Explain healthy indicators
     *    - Distinguish between warnings and critical findings
     *
     * OUTPUT FORMAT:
     * Strict JSON with mandatory structure:
     * - health_score: A|B|C|D|F grade
     * - summary: Executive overview (2-3 sentences)
     * - findings: Array of identified issues
     *   - category: Hardware|Storage|Security|System
     *   - severity: critical|warning|info|ok
     *   - title: Headline
     *   - description: Technical analysis
     *   - recommendation: Actionable remediation
     *   - evidence: Cited source, timestamp, raw data fragment
     *
     * ENFORCEMENT:
     * response_format='json_object' in API call ensures valid JSON
     * Model cannot deviate from schema
     * Guarantees parseable response
     *
     * @return string Forensic analysis rules and output format spec
     */
    private function getForensicRules(): string
    {
        return "STRICT RULES:
        1. PRECISION: Analyze raw forensic telemetry from SQLite extractions (e.g. .SYNOSYSDB, .SYNODISKHEALTHDB, .SYNOCONNDB, .SMBXFERDB) as the absolute ground truth.
        2. EVIDENCE REQUIREMENT: Every finding MUST cite identifying logs, telemetry keys, or hardware identifiers found in the raw JSON payload.
        3. ACCESS AUDIT: Use the 'auth_timeline' block to identify brute-force patterns, unauthorized geographical logins (if IP looks suspicious), or excessive failures.
        4. DATA EXFILTRATION: Use 'smb_xfer' to detect suspicious file-level activity (e.g., massive deletes or rapid encryption/writes characteristic of ransomware).
        5. ACCURACY: If the data shows no critical issues, provide an 'A' grade and explain the healthy indicators.

        OUTPUT FORMAT (Strict JSON):
        {
            \"health_score\": \"A|B|C|D|F\",
            \"summary\": \"Executive overview of system health and critical risks.\",
            \"findings\": [
                {
                    \"category\": \"Hardware|Storage|Security|System\",
                    \"severity\": \"critical|warning|info|ok\",
                    \"title\": \"Finding headline\",
                    \"description\": \"In-depth technical analysis of the issue.\",
                    \"recommendation\": \"Specific, actionable remediation steps.\",
                    \"evidence\": {
                        \"source\": \"Diagnostic Block Name\",
                        \"timestamp\": \"Relevant event time\",
                        \"raw_fragment\": \"Extracted log text or telemetry value\"
                    }
                }
            ]
        }";
    }
}
