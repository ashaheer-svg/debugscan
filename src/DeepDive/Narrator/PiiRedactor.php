<?php

declare(strict_types=1);

namespace App\DeepDive\Narrator;

/**
 * PiiRedactor: Sensitive data masking for LLM processing
 *
 * PURPOSE:
 * Scrubs obvious PII from evidence excerpts before they go to an external
 * LLM. We replace consistently: the same IP gets the same placeholder, so
 * the model can still reason about "the same host appearing in two logs"
 * without seeing the address.
 *
 * This is defence-in-depth, not a substitute for user consent. Tenants
 * have already opted into DeepDive (feature flag), and DSM debug bundles
 * never contain user data in log paths — but hostnames, LAN IPs, and
 * drive serials are still sensitive enough to mask.
 *
 * Patterns intentionally biased toward precision over recall: it's better
 * to leak "192.168.x.x" than to replace every "1.2.3.4" substring
 * unintentionally in something like a firmware version.
 */
final class PiiRedactor
{
    /** Map from original token → placeholder, so each run is internally consistent. */
    private array $map = [];

    /** @var array<string,int> counters for placeholder generation */
    private array $counters = ['IP' => 0, 'MAC' => 0, 'HOST' => 0, 'SERIAL' => 0, 'EMAIL' => 0];

    public function redact(string $text): string
    {
        // Order matters: MAC before IPv6 (which can look MAC-like), email before any user-matching.
        $text = $this->sub($text, '/\b[0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5}\b/', 'MAC');
        $text = $this->sub($text, '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', 'EMAIL');
        $text = $this->sub($text, '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', 'IP');

        // Synology drive serials: letter-heavy 6-12 chars. We only scrub when the
        // context word suggests a serial to avoid eating firmware version strings.
        $text = preg_replace_callback(
            '/\b(serial(?:\s*no\.?|\s*number)?|wwn)\s*[:=]\s*([A-Z0-9-]{6,20})\b/i',
            fn(array $m): string => $m[1] . ':' . $this->allocate('SERIAL', $m[2]),
            $text
        ) ?? $text;

        // Hostnames of the form word.word.word (not file paths, not URLs). Conservative:
        // only replace when the text clearly reads "host=<foo>" or syslog-style second field.
        $text = preg_replace_callback(
            '/\b(host(?:name)?)\s*[:=]\s*([A-Za-z0-9][A-Za-z0-9.-]{2,60})\b/',
            fn(array $m): string => $m[1] . ':' . $this->allocate('HOST', $m[2]),
            $text
        ) ?? $text;

        return $text;
    }

    /** Redact every string value in an associative array recursively. */
    public function redactArray(array $in): array
    {
        $out = [];
        foreach ($in as $k => $v) {
            if (is_string($v))       $out[$k] = $this->redact($v);
            elseif (is_array($v))    $out[$k] = $this->redactArray($v);
            else                      $out[$k] = $v;
        }
        return $out;
    }

    /** Map of original → placeholder for auditability ("did we leak anything?"). */
    public function mapping(): array { return $this->map; }

    private function sub(string $text, string $regex, string $kind): string
    {
        return preg_replace_callback(
            $regex,
            fn(array $m): string => $this->allocate($kind, $m[0]),
            $text
        ) ?? $text;
    }

    private function allocate(string $kind, string $original): string
    {
        if (!isset($this->map[$original])) {
            $this->counters[$kind] = ($this->counters[$kind] ?? 0) + 1;
            $this->map[$original] = '[' . $kind . '_' . $this->counters[$kind] . ']';
        }
        return $this->map[$original];
    }
}
