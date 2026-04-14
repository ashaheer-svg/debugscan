<?php

declare(strict_types=1);

namespace App\Parsers;

class LogParser implements ParserInterface
{
    private array $keywords = ['error', 'fail', 'critical', 'panic', 'warn', 'degraded', 'unhealthy'];

    public function parse(string $extractedPath, array &$context): array
    {
        $maxEvents = $context['_config']['logs']['max_rows'] ?? 100;
        $logs = ['critical_events' => []];
        $logFiles = [
            $extractedPath . '/dsm/var/log/messages',
            $extractedPath . '/dsm/var/log/dmesg',
            $extractedPath . '/dsm/var/log/synolog/synosys.log',
        ];

        foreach ($logFiles as $file) {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                $lines = explode("\n", $content);
                // Get the last 1000 lines and filter for keywords
                foreach (array_slice($lines, -1000) as $line) {
                    $lowerLine = strtolower($line);
                    foreach ($this->keywords as $keyword) {
                        if (str_contains($lowerLine, $keyword)) {
                            $logs['critical_events'][] = [
                                'source'  => basename($file),
                                'content' => trim($line),
                            ];
                            break;
                        }
                    }
                }
            }
        }

        // Apply admin-configured limit
        if (count($logs['critical_events']) > $maxEvents) {
             $logs['critical_events'] = array_slice($logs['critical_events'], -$maxEvents);
        }

        return $logs;
    }
}
