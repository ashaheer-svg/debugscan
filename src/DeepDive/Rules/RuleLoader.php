<?php

declare(strict_types=1);

namespace App\DeepDive\Rules;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * RuleLoader: YAML rule file parser and validator
 *
 * PURPOSE:
 * Parses individual YAML rule files into immutable Rule DTOs. Performs three
 * validation passes: file existence, YAML syntax, required field presence.
 * Enables rule authoring to be forgiving (missing optional fields) while strict
 * about core forensic metadata (severity, actionability, signature type).
 *
 * RULE FILE FORMAT (YAML):
 *   id: storage.raid_degraded              # Required: unique identifier
 *   version: 1                             # Required: rule version (integer)
 *   title: "RAID Array Degraded"           # Required: human-readable name
 *   severity: high                         # Required: info|warn|high|critical
 *   actionability: upgrade_recommended     # Required: user_fixable|upgrade|vendor|informational
 *   category: storage                      # Required: classification (storage, network, memory, security)
 *   description: "Long explanation..."     # Optional: detailed issue description
 *   remediation: "Fix by resyncing..."     # Optional: user-facing instructions
 *   tags: [raid, md, degraded]             # Optional: keyword tags for filtering
 *   signature:                             # Required: matcher-specific detection config
 *     type: regex                          # Required: regex|sqlite|aggregate|absence
 *     source: messages                     # Source log file or database
 *     pattern: 'raid.*degraded'            # Type-specific configuration (varies by matcher)
 *     min_hits: 1
 *   entities:                              # Optional: extraction templates for Correlator
 *     disk: "$match.disk"                  # {key: "$match.field"} format
 *
 * VALIDATION TIERS:
 * Tier 1 - File existence: File must be readable, not empty, valid YAML
 * Tier 2 - Required fields: All 7 required keys must be present
 * Tier 3 - Type validation: Signature must be array with 'type' key (Rule::assertValid)
 * Tier 4 - Semantic validation: Rule::assertValid() checks enum values (severity, etc.)
 *
 * ERROR HANDLING:
 * Throws RuntimeException on any validation failure with detailed message including
 * file path and field name. This prevents invalid rules from entering production.
 * YAML parse errors wrapped with context (file path, line number from Symfony).
 *
 * USAGE:
 * Instantiated fresh in RuleCatalogue::loadDirectory(). Foreach rule YAML file,
 * call loadFile($path) to get a validated Rule object. Multiple loaders can exist
 * (stateless) or single loader can parse multiple files (thread-safe).
 *
 * @package App\DeepDive\Rules
 */
final class RuleLoader
{
    public function loadFile(string $path): Rule
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException("Rule file not readable: {$path}");
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new \RuntimeException("YAML parse error in {$path}: " . $e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new \RuntimeException("Rule file {$path} does not contain a YAML mapping");
        }

        $required = ['id', 'version', 'title', 'severity', 'actionability', 'category', 'signature'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \RuntimeException("Rule file {$path} missing required key '{$key}'");
            }
        }

        $sig = $data['signature'];
        if (!is_array($sig) || empty($sig['type'])) {
            throw new \RuntimeException("Rule file {$path}: signature must be a mapping with a 'type' key");
        }

        $rule = new Rule(
            id:            (string)$data['id'],
            version:       (int)$data['version'],
            title:         (string)$data['title'],
            severity:      (string)$data['severity'],
            actionability: (string)$data['actionability'],
            category:      (string)$data['category'],
            signature:     $sig,
            entities:      is_array($data['entities'] ?? null) ? $data['entities'] : [],
            description:   isset($data['description']) ? (string)$data['description'] : null,
            remediation:   isset($data['remediation']) ? (string)$data['remediation'] : null,
            tags:          is_array($data['tags'] ?? null) ? array_values(array_map('strval', $data['tags'])) : [],
        );
        $rule->assertValid();
        return $rule;
    }
}
