<?php

declare(strict_types=1);

namespace App\Parsers;

class NetworkParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $network = ['interfaces' => [], 'gateways' => []];

        // 1. Interfaces from ifconfig.result (Hardwarev2.md Section 2.5 & 3.5)
        $ifFile = $extractedPath . '/dsm/result/ifconfig.result';
        if (file_exists($ifFile)) {
            $content = file_get_contents($ifFile);
            $sections = preg_split('/\n\n/', $content);
            foreach ($sections as $section) {
                $network['interfaces'] += $this->parseIfconfigSection($section);
            }
        }

        // 2. Gateway from route.result
        $routeFile = $extractedPath . '/dsm/result/route.result';
        if (file_exists($routeFile)) {
            $content = file_get_contents($routeFile);
            if (preg_match('/^default\s+via\s+([0-9.]+)\s+dev\s+([a-z0-9]+)/m', $content, $matches)) {
                $network['gateways'][] = [
                    'gateway' => $matches[1],
                    'interface' => $matches[2],
                ];
            }
        }

        return $network;
    }

    /**
     * Parse a single ifconfig interface block
     * Supports both DSM 6.x (inet addr:) and DSM 7.x (inet) formats
     *
     * FIXED: Previously only supported DSM 7.x format (inet X.X.X.X netmask)
     * Now handles both formats for backwards compatibility
     */
    private function parseIfconfigSection(string $section): array
    {
        $interfaces = [];

        // Extract interface name (first line)
        if (!preg_match('/^([a-z0-9]+):/', $section, $nameMatch)) {
            return $interfaces;
        }
        $ifname = $nameMatch[1];

        $ip = null;
        $netmask = null;

        // Try DSM 7.x format first: inet X.X.X.X netmask Y.Y.Y.Y
        if (preg_match('/inet\s+([0-9.]+)\s+netmask\s+([0-9.]+)/', $section, $matches)) {
            $ip = $matches[1];
            $netmask = $matches[2];
        }
        // Fallback to DSM 6.x format: inet addr:X.X.X.X ... Mask:Y.Y.Y.Y
        elseif (preg_match('/inet addr:([0-9.]+).*Mask:([0-9.]+)/', $section, $matches)) {
            $ip = $matches[1];
            $netmask = $matches[2];
        }

        if ($ip && $netmask) {
            $interfaces[$ifname] = [
                'ip' => $ip,
                'netmask' => $netmask,
            ];
        }

        return $interfaces;
    }
}
