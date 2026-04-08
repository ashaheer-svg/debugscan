<?php

declare(strict_types=1);

namespace App\Parsers;

class VolumeParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $volumes = [];
        $file = $extractedPath . '/dsm/run/space/volume_status.cache';

        if (file_exists($file)) {
            $content = file_get_contents($file);
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                if (preg_match('/\[(\/volume\d+)\]/', $line, $matches)) {
                    $volName = $matches[1];
                    $volumes[$volName] = [];
                } elseif (isset($volName) && preg_match('/([^=]+)=(.*)/', $line, $matches)) {
                    $volumes[$volName][trim($matches[1])] = trim($matches[2]);
                }
            }
        }

        // Add additional mapping from lv.result or space_meta.status
        $metaFile = $extractedPath . '/dsm/run/space/space_meta.status';
        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            if ($meta && isset($meta['volumes'])) {
                foreach ($meta['volumes'] as $id => $data) {
                    $name = '/volume' . ($id + 1);
                    if (isset($volumes[$name])) {
                        $volumes[$name]['fs_type'] = $data['fs_type'] ?? 'unknown';
                        $volumes[$name]['pool_id'] = $data['pool_id'] ?? null;
                    }
                }
            }
        }

        return $volumes;
    }
}
