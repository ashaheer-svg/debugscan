<?php

declare(strict_types=1);

namespace App\Parsers;

/**
 * VersionParser: Extract DSM version information
 *
 * PURPOSE:
 * Reads VERSION files to get DSM major version, product version, and build number.
 * Sets context for downstream parsers (HardwareParser depends on majorversion for file path selection).
 * First parser executed (dependency for others).
 *
 * Output: {major, product, build, build_date}
 */
class VersionParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $versionFiles = [
            $extractedPath . '/dsm/etc/VERSION',
            $extractedPath . '/dsm/etc.defaults/VERSION'
        ];

        foreach ($versionFiles as $file) {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                $lines = explode("\n", $content);
                $versionData = [];
                foreach ($lines as $line) {
                    if (str_contains($line, '=')) {
                        list($key, $value) = explode('=', $line, 2);
                        $versionData[trim($key)] = trim($value, '" ');
                    }
                }

                $context['majorversion'] = (int)($versionData['majorversion'] ?? 0);
                $context['productversion'] = $versionData['productversion'] ?? '';
                $context['buildnumber'] = $versionData['buildnumber'] ?? '';

                return [
                    'data' => [
                        'major' => $context['majorversion'],
                        'product' => $context['productversion'],
                        'build' => $context['buildnumber'],
                        'build_date' => $versionData['builddate'] ?? ''
                    ],
                    'citations' => [
                        [
                            'file' => str_replace($extractedPath . '/', '', $file),
                            'lines' => '1-' . count($lines),
                            'timestamp' => date('Y-m-d H:i:s', filemtime($file))
                        ]
                    ]
                ];
            }
        }

        return ['data' => [], 'citations' => []];
    }
}
