<?php

declare(strict_types=1);

namespace App\Parsers;

class NetworkParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $network = ['interfaces' => [], 'gateways' => []];
        
        // 1. Interfaces from ifconfig.result
        $ifFile = $extractedPath . '/dsm/result/ifconfig.result';
        if (file_exists($ifFile)) {
            $content = file_get_contents($ifFile);
            $sections = preg_split('/\n\n/', $content);
            foreach ($sections as $section) {
                if (preg_match('/^([a-z0-9]+):\s+flags=(\d+).*\n\s+inet\s+([0-9.]+)\s+netmask\s+([0-9.]+)/', $section, $matches)) {
                    $network['interfaces'][$matches[1]] = [
                        'ip' => $matches[3],
                        'netmask' => $matches[4],
                    ];
                }
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
}
