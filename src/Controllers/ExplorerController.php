<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
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
        return $this->view->render($response, 'admin/explorer.twig', [
            'basePath' => $this->basePath
        ]);
    }

    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $subPath = $params['path'] ?? '';
        $showSensitive = ($params['showSensitive'] ?? 'false') === 'true';

        $fullPath = realpath($this->basePath . DIRECTORY_SEPARATOR . $subPath);

        // Security check: Ensure requested path is within base path
        if (!$fullPath || strpos($fullPath, $this->basePath) !== 0) {
            return $response->withStatus(403)->withJson(['error' => 'Path access denied.']);
        }

        $items = [];
        $dir = new \DirectoryIterator($fullPath);

        foreach ($dir as $fileinfo) {
            if ($fileinfo->isDot()) continue;

            $name = $fileinfo->getFilename();

            // Filter sensitive files if safety toggle is off
            if (!$showSensitive) {
                if (in_array($name, ['.env', '.git', '.github', '.agent', 'vendor'])) {
                    continue;
                }
            }

            $relativePath = ltrim(substr($fileinfo->getPathname(), strlen($this->basePath)), DIRECTORY_SEPARATOR);
            
            $size = $fileinfo->isDir() ? $this->getDirSize($fileinfo->getPathname()) : $fileinfo->getSize();

            $items[] = [
                'name' => $name,
                'path' => $relativePath,
                'is_dir' => $fileinfo->isDir(),
                'size' => $this->formatSize($size),
                'raw_size' => $size,
                'modified' => date('Y-m-d H:i:s', $fileinfo->getMTime()),
            ];
        }

        // Sort: Directories first, then alphabetical
        usort($items, function($a, $b) {
            if ($a['is_dir'] && !$b['is_dir']) return -1;
            if (!$a['is_dir'] && $b['is_dir']) return 1;
            return strcasecmp($a['name'], $b['name']);
        });

        $response->getBody()->write(json_encode([
            'currentPath' => ltrim($subPath, DIRECTORY_SEPARATOR),
            'items' => $items
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function download(Request $request, Response $response): Response
    {
        $subPath = $request->getQueryParams()['path'] ?? '';
        $fullPath = realpath($this->basePath . DIRECTORY_SEPARATOR . $subPath);

        if (!$fullPath || !is_file($fullPath) || strpos($fullPath, $this->basePath) !== 0) {
            throw new RuntimeException("Secure file access denied.");
        }

        $filename = basename($fullPath);
        $response = $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Expires', '0')
            ->withHeader('Cache-Control', 'must-revalidate')
            ->withHeader('Pragma', 'public');

        $response->getBody()->write(file_get_contents($fullPath));
        return $response;
    }

    public function delete(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $subPath = $data['path'] ?? '';
        $allowDelete = ($data['allowDelete'] ?? 'false') === 'true';

        if (!$allowDelete) {
            return $response->withStatus(403)->withJson(['error' => 'Deletion mode is disabled.']);
        }

        $fullPath = realpath($this->basePath . DIRECTORY_SEPARATOR . $subPath);

        if (!$fullPath || strpos($fullPath, $this->basePath) !== 0) {
            return $response->withStatus(403)->withJson(['error' => 'Delete access denied.']);
        }

        // Final safety: Do not allow deleting core config files even with force enabled
        if (in_array(basename($fullPath), ['index.php', '.htaccess', 'AppBootstrap.php'])) {
            return $response->withStatus(403)->withJson(['error' => 'Cannot delete core system files via explorer.']);
        }

        if (is_dir($fullPath)) {
            $this->recursiveDelete($fullPath);
        } else {
            unlink($fullPath);
        }

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function getDirSize($path): int
    {
        $size = 0;
        // Optimization: For huge folders like vendor, we might want to skip or estimate
        // but for now, we provide the user's requested 'capacity shown'
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
        return $size;
    }

    private function formatSize($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $bytes > 1024; $i++) $bytes /= 1024;
        return round($bytes, 2) . ' ' . $units[$i];
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
}
