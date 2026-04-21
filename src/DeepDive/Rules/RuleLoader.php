<?php

declare(strict_types=1);

namespace App\DeepDive\Rules;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Parses one YAML rule file into a Rule DTO. Tolerant of shallow authoring
 * mistakes (missing optional fields), strict about the required ones.
 *
 * File format:
 *   id: raid.kicked_disk
 *   version: 1
 *   title: "RAID array kicked a disk"
 *   severity: high                # info|warn|high|critical
 *   actionability: upgrade_recommended
 *   category: storage
 *   description: "..."            # optional
 *   remediation: "..."            # optional
 *   tags: [raid, md]              # optional
 *   signature:
 *     type: regex                 # regex|sqlite|aggregate|absence
 *     source: messages
 *     pattern: 'md/raid:md\d+ Disk failure on (?P<disk>\S+)'
 *     min_hits: 1
 *   entities:
 *     disk: "$match.disk"
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
