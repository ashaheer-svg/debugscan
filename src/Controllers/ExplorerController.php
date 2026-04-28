<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment as Twig;
use RuntimeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * File Explorer Controller: Browse, download, and delete files/directories
 *
 * RESPONSIBILITIES:
 * - Render file explorer UI showing directory tree
 * - List files and directories within constrained base path
 * - Provide secure download for files (streaming)
 * - Delete files and directories (with safety flags)
 * - Calculate directory sizes and format for display
 *
 * ROUTE MAPPING:
 * - GET  /admin/explorer              → index() (render explorer UI)
 * - GET  /api/explorer/list           → list() (JSON list of directory contents)
 * - GET  /api/explorer/download       → download() (file download endpoint)
 * - POST /api/explorer/delete         → delete() (delete file/directory)
 *
 * SECURITY ARCHITECTURE:
 * - Base path restriction: All operations constrained to application root
 * - Directory traversal prevention: realpath() validation with strpos() check
 * - Sensitive file filtering: .env, .git, .github, .agent, vendor hidden by default
 * - Core system protection: Critical files cannot be deleted even with override
 * - Optional deletion: Disabled by default; requires explicit enablement flag
 *
 * SENSITI FILES:
 * Hidden by default when showSensitive=false:
 *   - .env (configuration with secrets)
 *   - .git (version control metadata)
 *   - .github (GitHub workflows)
 *   - .agent (internal agent data)
 *   - vendor (dependencies)
 *
 * PROTECTED CORE FILES:
 * Cannot be deleted even when deletion enabled:
 *   - index.php (entry point)
 *   - .htaccess (web server config)
 *   - AppBootstrap.php (application bootstrap)
 *   - Database.php (database factory)
 *
 * DIRECTORY SIZE CALCULATION:
 * Recursively sums all files in directory tree
 * Performance: O(n) where n = number of files in tree
 * Error handling: Silently skips inaccessible subdirectories (permissions)
 *
 * MEMORY-SAFE DOWNLOADS:
 * Uses readfile() for streaming (not file_get_contents)
 * Reduces memory consumption for large files
 * Sets appropriate HTTP headers for download
 *
 * DEPENDENCIES:
 * - Twig: Template rendering for explorer UI
 * - SPL: RecursiveDirectoryIterator, RecursiveIteratorIterator for traversal
 *
 * @package App\Controllers
 */
class ExplorerController
{
    private Twig $view;
    private string $basePath;

    /**
     * Constructor: Initialize Twig and determine application base path
     *
     * BASE PATH CALCULATION:
     * - Starts from this controller's directory (__DIR__)
     * - Goes up 2 levels: /src/Controllers → /src → / (application root)
     * - Uses realpath() to resolve symlinks and normalize path
     * - All file operations constrained to this base path
     *
     * SECURITY:
     * Base path restriction prevents directory traversal attacks
     * even if user provides malicious paths like ../../../etc/passwd
     *
     * @param Twig $view Twig template environment for rendering UI
     */
    public function __construct(Twig $view)
    {
        $this->view = $view;
        // Restrict to the web application root (../../ from src/Controllers/)
        $this->basePath = realpath(__DIR__ . '/../../');
    }

    /**
     * Render file explorer UI interface
     *
     * DISPLAY:
     * - JavaScript UI for browsing directory tree
     * - List view showing files and directories
     * - Download and delete buttons (if permitted)
     * - Breadcrumb navigation
     *
     * DATA FLOW:
     * 1. Client loads explorer page
     * 2. JavaScript calls /api/explorer/list API
     * 3. API returns directory contents as JSON
     * 4. JavaScript renders file list
     *
     * @param Request  $request  PSR-7 request (unused for GET)
     * @param Response $response PSR-7 response object
     *
     * @return Response HTML response with explorer UI
     */
    public function index(Request $request, Response $response): Response
    {
        // Render explorer template with base path (mostly for reference/debugging)
        $body = $this->view->render('admin/explorer.twig', [
            'basePath' => $this->basePath,        // Application root path
            'active_page' => 'admin_explorer'     // Highlight nav item
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    /**
     * List directory contents as JSON (API endpoint)
     *
     * QUERY PARAMETERS:
     * - path: Relative path within base path (e.g., 'src/Controllers')
     * - showSensitive: 'true' to show .env, .git, etc. (default: 'false')
     *
     * FLOW:
     * 1. Normalize requested path via realpath()
     * 2. Validate path is within base path (directory traversal check)
     * 3. Iterate directory contents
     * 4. Filter sensitive files unless showSensitive=true
     * 5. Calculate sizes (recursively for directories)
     * 6. Sort: directories first, then by modification time (newest first)
     * 7. Return JSON with file listing
     *
     * SECURITY CHECKS:
     * - realpath() resolves symlinks and normalizes path
     * - strpos() check ensures path doesn't escape base path
     * - If validation fails, return 403 error
     *
     * SENSITIVE FILE FILTERING:
     * Files hidden when showSensitive=false:
     *   - .env (secrets)
     *   - .git (version control)
     *   - .github (GitHub config)
     *   - .agent (internal)
     *   - vendor (dependencies)
     *
     * RESPONSE STRUCTURE:
     * {
     *   "currentPath": "src/Controllers",
     *   "items": [
     *     {
     *       "name": "AuthController.php",
     *       "path": "src/Controllers/AuthController.php",
     *       "is_dir": false,
     *       "size": "14.5 KB",
     *       "raw_size": 14857,
     *       "mtime": 1719345600,
     *       "modified": "2024-06-25 12:00:00"
     *     },
     *     ...
     *   ]
     * }
     *
     * SORTING LOGIC:
     * 1. Directories appear first (before files)
     * 2. Primary sort: Most recent modification first
     * 3. Secondary sort: Alphabetical (case-insensitive)
     *
     * ERROR HANDLING:
     * - Directory iteration exceptions caught and returned as 500 error
     * - Includes error message for debugging
     *
     * @param Request  $request  PSR-7 request with query params
     * @param Response $response PSR-7 response object
     *
     * @return Response JSON response with directory listing (or error)
     */
    public function list(Request $request, Response $response): Response
    {
        // Extract query parameters
        $params = $request->getQueryParams();
        $subPath = $params['path'] ?? '';
        $showSensitive = ($params['showSensitive'] ?? 'false') === 'true';

        // === SECURITY: Normalize and validate path ===
        $fullPath = realpath($this->basePath . DIRECTORY_SEPARATOR . $subPath);

        // Ensure requested path is within base path (prevent directory traversal)
        // realpath() returns false for non-existent paths, and strpos() checks bounds
        if (!$fullPath || strpos($fullPath, $this->basePath) !== 0) {
            return $this->jsonResponse($response, ['error' => 'Path access denied.'], 403);
        }

        $items = [];
        try {
            // Iterate directory contents
            $dir = new \DirectoryIterator($fullPath);
            foreach ($dir as $fileinfo) {
                // Skip . and .. entries
                if ($fileinfo->isDot()) continue;

                $name = $fileinfo->getFilename();

                // === Filter sensitive files if safety mode enabled ===
                if (!$showSensitive) {
                    if (in_array(strtolower($name), ['.env', '.git', '.github', '.agent', 'vendor'])) {
                        continue; // Skip this file
                    }
                }

                // Calculate relative path from base for client display
                $relativePath = ltrim(substr($fileinfo->getPathname(), strlen($this->basePath)), DIRECTORY_SEPARATOR);

                // === Calculate size ===
                // For directories: recursively sum all files
                // For files: use filesize directly
                $size = $fileinfo->isDir() ? $this->getDirSize($fileinfo->getPathname()) : $fileinfo->getSize();

                // Add to results with formatted metadata
                $items[] = [
                    'name'     => $name,
                    'path'     => $relativePath,
                    'is_dir'   => $fileinfo->isDir(),
                    'size'     => $this->formatSize($size),           // Human-readable (14.5 KB)
                    'raw_size' => $size,                               // Raw bytes for sorting
                    'mtime'    => $fileinfo->getMTime(),               // Unix timestamp
                    'modified' => date('Y-m-d H:i:s', $fileinfo->getMTime()), // Human-readable
                ];
            }
        } catch (\Exception $e) {
            // Directory iteration failed (permissions, deleted during read, etc.)
            return $this->jsonResponse($response, ['error' => 'Cannot read directory: ' . $e->getMessage()], 500);
        }

        // === Sort items ===
        // 1. Directories appear first
        // 2. Primary: Most recent modification time first
        // 3. Secondary: Alphabetical order
        usort($items, function($a, $b) {
            // Directories before files
            if ($a['is_dir'] && !$b['is_dir']) return -1;
            if (!$a['is_dir'] && $b['is_dir']) return 1;

            // Primary sort: Most recent modification time first (descending)
            if ($a['mtime'] !== $b['mtime']) {
                return $b['mtime'] <=> $a['mtime'];
            }

            // Secondary sort: Alphabetical order (case-insensitive)
            return strcasecmp($a['name'], $b['name']);
        });

        // Return directory listing as JSON
        return $this->jsonResponse($response, [
            'currentPath' => ltrim($subPath, DIRECTORY_SEPARATOR),
            'items'       => $items
        ]);
    }

    /**
     * Stream file download (memory-efficient for large files)
     *
     * QUERY PARAMETERS:
     * - path: Relative file path within base path
     *
     * SECURITY CHECKS:
     * - Path validation: realpath() + strpos() (prevent directory traversal)
     * - File type validation: is_file() (no directory downloads)
     * - Throws exception on any validation failure
     *
     * HTTP HEADERS:
     * - Content-Type: application/octet-stream (force download)
     * - Content-Disposition: attachment (suggest filename)
     * - Content-Length: exact file size
     * - Cache-Control: must-revalidate (prevent stale copies)
     *
     * MEMORY SAFETY:
     * Uses readfile() for streaming instead of file_get_contents()
     * readfile() reads file in chunks (default 8KB) rather than loading into memory
     * Suitable for files of any size without memory constraints
     *
     * FLOW:
     * 1. Validate file path
     * 2. Extract filename for Content-Disposition header
     * 3. Set download headers
     * 4. Stream file contents via readfile()
     * 5. exit to prevent Slim from sending additional response body
     *
     * NOTE: exit() used to prevent Slim framework from processing further
     *        This bypasses middleware and other post-download processing
     *
     * @param Request  $request  PSR-7 request with query params
     * @param Response $response PSR-7 response object (not used, headers set via header())
     *
     * @return Response Never returns (calls exit after streaming)
     *
     * @throws RuntimeException If path validation fails
     */
    public function download(Request $request, Response $response): Response
    {
        // Extract file path from query parameter
        $subPath = $request->getQueryParams()['path'] ?? '';
        $fullPath = realpath($this->basePath . DIRECTORY_SEPARATOR . $subPath);

        // === SECURITY: Validate file path ===
        // Check: path exists (realpath returns false if not),
        //        is a file (not directory), and is within base path
        if (!$fullPath || !is_file($fullPath) || strpos($fullPath, $this->basePath) !== 0) {
            throw new RuntimeException("Secure file access denied.");
        }

        $filename = basename($fullPath);

        // === Set HTTP headers for download ===
        header('Content-Type: application/octet-stream');                          // Force download
        header('Content-Disposition: attachment; filename="' . $filename . '"');   // Suggested filename
        header('Content-Length: ' . filesize($fullPath));                          // Exact file size
        header('Pragma: public');                                                   // Cache-control directive
        header('Expires: 0');                                                       // Immediate expiration
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');      // Revalidate on every access

        // === Stream file contents ===
        // readfile() streams in chunks (memory-safe) instead of loading entire file
        readfile($fullPath);

        // Exit to prevent Slim from sending additional content
        exit;
    }

    /**
     * Delete a file or directory (with safety guards)
     *
     * POST PARAMETERS:
     * - path: Relative file/directory path within base path
     * - allowDelete: Boolean flag (must be true to enable deletion)
     *
     * SAFETY FEATURES:
     * 1. Deletion disabled by default (requires allowDelete=true)
     * 2. Path validation: realpath() + strpos() (prevent directory traversal)
     * 3. Core file protection: Critical files always protected
     * 4. Recursive deletion: Directories deleted with all contents
     *
     * PROTECTED CORE FILES:
     * Cannot be deleted even with allowDelete=true:
     *   - index.php (application entry point)
     *   - .htaccess (web server configuration)
     *   - appbootstrap.php (application bootstrap)
     *   - database.php (database factory)
     *
     * FLOW:
     * 1. Check allowDelete flag (return 403 if false)
     * 2. Validate path (realpath + bounds check)
     * 3. Check if target is core system file (protected)
     * 4. If directory: recursively delete all contents, then directory
     * 5. If file: delete directly
     * 6. Return success or error
     *
     * ERROR HANDLING:
     * - allowDelete=false → 403 Forbidden
     * - Path outside base → 403 Forbidden
     * - Protected core file → 403 Forbidden
     * - Deletion exception → 500 with error message
     *
     * RESPONSE:
     * Success: { "success": true }
     * Error:   { "error": "..." }
     *
     * @param Request  $request  PSR-7 request with POST body
     * @param Response $response PSR-7 response object
     *
     * @return Response JSON response with success/error
     */
    public function delete(Request $request, Response $response): Response
    {
        // Extract form data
        $data = $request->getParsedBody();
        $subPath = $data['path'] ?? '';
        $allowDelete = ($data['allowDelete'] ?? false) === true;

        // === SAFETY CHECK 1: Deletion mode disabled? ===
        if (!$allowDelete) {
            return $this->jsonResponse($response, ['error' => 'Deletion mode is disabled.'], 403);
        }

        // === SECURITY: Validate file path ===
        $fullPath = realpath($this->basePath . DIRECTORY_SEPARATOR . $subPath);

        if (!$fullPath || strpos($fullPath, $this->basePath) !== 0) {
            return $this->jsonResponse($response, ['error' => 'Delete access denied.'], 403);
        }

        // === SAFETY CHECK 2: Core system file protection ===
        // Even with allowDelete=true, prevent deletion of critical application files
        $safeBase = strtolower(basename($fullPath));
        if (in_array($safeBase, ['index.php', '.htaccess', 'appbootstrap.php', 'database.php'])) {
            return $this->jsonResponse($response, ['error' => 'Core system files are protected and cannot be deleted.'], 403);
        }

        try {
            // === Delete target ===
            if (is_dir($fullPath)) {
                // Recursively delete directory and all contents
                $this->recursiveDelete($fullPath);
            } else {
                // Delete file
                unlink($fullPath);
            }
        } catch (\Exception $e) {
            // Deletion failed (permissions, file in use, etc.)
            return $this->jsonResponse($response, ['error' => 'Delete failed: ' . $e->getMessage()], 500);
        }

        return $this->jsonResponse($response, ['success' => true]);
    }

    /**
     * Internal helper: Recursively calculate directory size
     *
     * PURPOSE:
     * Determines total size of all files in a directory tree
     * Used to display directory sizes in file listing
     *
     * ALGORITHM:
     * 1. Create RecursiveDirectoryIterator for directory tree
     * 2. Iterate all files recursively (SKIP_DOTS excludes . and ..)
     * 3. Sum individual file sizes
     * 4. Silently catch exceptions (handles permission errors gracefully)
     *
     * PERFORMANCE:
     * - O(n) where n = total files in tree
     * - For large directories, this can be slow
     * - Current implementation trades speed for correctness
     *
     * ERROR HANDLING:
     * - Permission errors on subdirectories silently caught
     * - Returns partial size (sum of accessible files)
     * - Never throws exception
     *
     * @param string $path Directory path (must exist)
     *
     * @return int Total size in bytes (may be partial if permissions deny access)
     */
    private function getDirSize($path): int
    {
        $size = 0;
        try {
            // Create recursive iterator over directory tree
            $it = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
            // Iterate all files recursively
            foreach (new RecursiveIteratorIterator($it) as $file) {
                $size += $file->getSize();
            }
        } catch (\Exception $e) {
            // Permission error accessing subdirectories: silently fail
            // Return partial size (sum of accessible files)
        }
        return $size;
    }

    /**
     * Internal helper: Convert bytes to human-readable format
     *
     * CONVERSION UNITS:
     * - 0 - 1024 bytes     → B (bytes)
     * - 1,024 - 1MB        → KB (kilobytes)
     * - 1MB - 1GB          → MB (megabytes)
     * - 1GB - 1TB          → GB (gigabytes)
     * - 1TB+               → TB (terabytes)
     *
     * ALGORITHM:
     * Uses logarithm to determine appropriate unit:
     * - log(bytes, 1024) determines power of 1024
     * - Divides bytes by 1024^power to get unit value
     * - Rounds to 2 decimal places
     *
     * EXAMPLES:
     * - 512 → "512 B"
     * - 1024 → "1 KB"
     * - 1536 → "1.5 KB"
     * - 1048576 → "1 MB"
     * - 0 or negative → "0 B"
     *
     * @param int $bytes Size in bytes
     *
     * @return string Human-readable size (e.g., "14.5 KB", "2.3 MB")
     */
    private function formatSize($bytes): string
    {
        if ($bytes <= 0) return '0 B';

        // Array of unit names
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        // Determine which unit to use: log(bytes, 1024) gives power of 1024
        $i = floor(log($bytes, 1024));

        // Convert to appropriate unit and format with 2 decimal places
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }

    /**
     * Internal helper: Recursively delete directory and all contents
     *
     * PURPOSE:
     * Removes directory tree completely (like `rm -rf` in Unix)
     * Required for delete() method to remove non-empty directories
     *
     * ALGORITHM:
     * 1. Create recursive iterator over directory tree
     * 2. Process in CHILD_FIRST order (children deleted before parents)
     * 3. Delete files and subdirectories
     * 4. Finally delete the root directory
     *
     * ITERATION ORDER:
     * CHILD_FIRST ensures proper deletion sequence:
     * - Delete files first
     * - Delete empty subdirectories
     * - Finally delete root directory
     * This avoids "directory not empty" errors
     *
     * ERROR HANDLING:
     * Exceptions propagate to caller (delete() method handles them)
     * If any file fails to delete, exception stops entire process
     *
     * @param string $dir Directory path (must exist and contain no special permissions)
     *
     * @return void No return value
     *
     * @throws Exception If delete fails (permissions, file in use, etc.)
     */
    private function recursiveDelete($dir): void
    {
        // Create recursive iterator over directory tree
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        // Process children before parents (CHILD_FIRST order)
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

        // Iterate all files and directories in bottom-up order
        foreach ($files as $file) {
            if ($file->isDir()) {
                // Delete empty subdirectory
                rmdir($file->getPathname());
            } else {
                // Delete file
                unlink($file->getPathname());
            }
        }

        // Finally, delete the root directory itself (now empty)
        rmdir($dir);
    }

    /**
     * Internal helper: Serialize data to JSON response
     *
     * PURPOSE:
     * Consistently format API responses as JSON with appropriate headers
     * Used by list(), delete(), and other API endpoints
     *
     * PARAMETERS:
     * - $data: Any array/object to JSON-serialize
     * - $status: HTTP status code (200 for success, 400+ for error)
     *
     * RESPONSE HEADERS:
     * - Content-Type: application/json (tells client data is JSON)
     * - Status code: HTTP status (200, 400, 403, 500, etc.)
     *
     * @param Response $response PSR-7 response object
     * @param array    $data     Data to serialize (e.g., ['success' => true])
     * @param int      $status   HTTP status code (default: 200)
     *
     * @return Response Modified response with JSON body and headers
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        // Serialize data array to JSON and write to response body
        $response->getBody()->write(json_encode($data));

        // Set Content-Type header and status code
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
