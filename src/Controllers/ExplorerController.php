<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment as Twig;
use RuntimeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ExplorerController
{
    private Twig $view;
    private string $basePath;

    public function __construct(Twig $view)
    {
        $this->view = $view;
        // Restrict to the web application root
        $this->basePath = realpath(__DIR__ . '/../../');
    }

    public function index(Request $request, Response $response): Response
    {
        $body = $this->view->render('admin/explorer.twig', [
            'basePath' => $this->basePath,
            'active_page' => 'admin_explorer'
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $subPath = $params['path'] ?? '';
        $showSensitive = ($params['showSensitive'] ?? 'false') === 'true';

        $fullPath = realpath($this->basePath . DIRECTORY_SEPARATOR . $subPath);

        // Security check: Ensure requested path is within base path
        if (!$fullPath || strpos($fullPath, $this->basePath) !== 0) {
            return $this->jsonResponse($response, ['error' => 'Path access denied.'], 403);
        }

        $items = [];
        try {
            $dir = new \DirectoryIterator($fullPath);
            foreach ($dir as $fileinfo) {
                if ($fileinfo->isDot()) continue;

                $name = $fileinfo->getFilename();

                // Filter sensitive files if safety toggle is off
                if (!$showSensitive) {
                    if (in_array(strtolower($name), ['.env', '.git', '.github', '.agent', 'vendor'])) {
                        continue;
                    }
                }

                $relativePath = ltrim(substr($fileinfo->getPathname(), strlen($this->basePath)), DIRECTORY_SEPARATOR);
                
                // Optimized directory sizing
                $size = $fileinfo->isDir() ? $this->getDirSize($fileinfo->getPathname()) : $fileinfo->getSize();

                $items[] = [
                    'name' => $name,
                    'path' => $relativePath,
                    'is_dir' => $fileinfo->isDir(),
                    'size' => $this->formatSize($size),
                    'raw_size' => $size,
                    'mtime' => $fileinfo->getMTime(),
                    'modified' => date('Y-m-d H:i:s', $fileinfo->getMTime()),
                ];
            }
        } catch (\Exception $e) {
            return $this->jsonResponse($response, ['error' => 'Cannot read directory: ' . $e->getMessage()], 500);
        }

        // Sort: Directories first, then by Date/Time (Most recent first)
        usort($items, function($a, $b) {
            if ($a['is_dir'] && !$b['is_dir']) return -1;
            if (!$a['is_dir'] && $b['is_dir']) return 1;
            
            // Primary sort: Most recent first
            if ($a['mtime'] !== $b['mtime']) {
                return $b['mtime'] <=> $a['mtime'];
            }
            // Secondary sort: Alphabetical
            return strcasecmp($a['name'], $b['name']);
        });

        return $this->jsonResponse($response, [
            'currentPath' => ltrim($subPath, DIRECTORY_SEPARATOR),
            'items' => $items
        ]);
    }

    public function download(Request $request, Response $response): Response
    {
        $subPath = $request->getQueryParams()['path'] ?? '';
        $fullPath = realpath($this->basePath . DIRECTORY_SEPARATOR . $subPath);

        if (!$fullPath || !is_file($fullPath) || strpos($fullPath, $this->basePath) !== 0) {
            throw new RuntimeException("Secure file access denied.");
        }

        $filename = basename($fullPath);
        
        // Memory-safe streaming download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($fullPath));
        header('Pragma: public');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        
        readfile($fullPath);
        exit; // Terminate to prevent Slim from sending extra content
    }

    public function delete(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $subPath = $data['path'] ?? '';
        $allowDelete = ($data['allowDelete'] ?? false) === true;

        if (!$allowDelete) {
            return $this->jsonResponse($response, ['error' => 'Deletion mode is disabled.'], 403);
        }

        $fullPath = realpath($this->basePath . DIRECTORY_SEPARATOR . $subPath);

        if (!$fullPath || strpos($fullPath, $this->basePath) !== 0) {
            return $this->jsonResponse($response, ['error' => 'Delete access denied.'], 403);
        }

        // Final safety: Do not allow deleting core config files even with force enabled
        $safeBase = strtolower(basename($fullPath));
        if (in_array($safeBase, ['index.php', '.htaccess', 'appbootstrap.php', 'database.php'])) {
            return $this->jsonResponse($response, ['error' => 'Core system files are protected and cannot be deleted.'], 403);
        }

        try {
            if (is_dir($fullPath)) {
                $this->recursiveDelete($fullPath);
            } else {
                unlink($fullPath);
            }
        } catch (\Exception $e) {
            return $this->jsonResponse($response, ['error' => 'Delete failed: ' . $e->getMessage()], 500);
        }

        return $this->jsonResponse($response, ['success' => true]);
    }

    private function getDirSize($path): int
    {
        $size = 0;
        try {
            $it = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
            foreach (new RecursiveIteratorIterator($it) as $file) {
                $size += $file->getSize();
            }
        } catch (\Exception $e) {
            // Silently fail for inaccessible subdirs (permissions)
        }
        return $size;
    }

    private function formatSize($bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }

    private function recursiveDelete($dir): void
    {
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) {
            if ($file->isDir()){
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
