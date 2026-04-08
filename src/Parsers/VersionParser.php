<?php

declare(strict_types=1);

namespace App\Parsers;

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
                    'major' => $context['majorversion'],
                    'product' => $context['productversion'],
                    'build' => $context['buildnumber'],
                    'build_date' => $versionData['builddate'] ?? ''
                ];
            }
        }

        return [];
    }
}
