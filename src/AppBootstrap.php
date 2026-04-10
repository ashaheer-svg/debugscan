<?php

declare(strict_types=1);

namespace App;

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Slim\App;
use Slim\Factory\AppFactory;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use PDO;
use App\Services\AuthService;
use App\Services\FileService;
use App\Services\ScanService;
use App\Services\AiService;
use App\Services\ParseService;
use App\Controllers\AuthController;
use App\Controllers\TenantController;
use App\Controllers\AdminController;
use App\Helpers\DatabaseSessionHandler;
use App\Middleware\AuthMiddleware;
use App\Middleware\ViewDataMiddleware;



class AppBootstrap
{
    public static function create(): App
    {
        // Load environment variables
        if (file_exists(__DIR__ . '/../.env')) {
            $dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/../');
            $dotenv->load();
        }

        // Initialize Container
        $containerBuilder = new ContainerBuilder();

        // Add application dependencies to container
        $containerBuilder->addDefinitions([
            PDO::class => function () {
                return Database::getConnection();
            },
            Environment::class => function () {
                $loader = new FilesystemLoader(__DIR__ . '/../templates');
                $twig = new Environment($loader, [
                    'cache' => false, // Set to a path in production
                    'debug' => (getenv('APP_DEBUG') ?: 'false') === 'true',
                ]);

                // Register custom filters
                $twig->addFilter(new \Twig\TwigFilter('json_decode', function ($string) {
                    if (!$string || !is_string($string)) return [];
                    $decoded = json_decode($string, true);
                    return is_array($decoded) ? $decoded : [];
                }));

                return $twig;
            },
            AuthService::class => function ($container) {
                return new AuthService($container->get(PDO::class));
            },
            AuthController::class => function ($container) {
                return new AuthController(
                    $container->get(Environment::class),
                    $container->get(AuthService::class)
                );
            },
            TenantController::class => function ($container) {
                return new TenantController(
                    $container->get(Environment::class),
                    $container->get(PDO::class),
                    new FileService($container->get(PDO::class), __DIR__ . '/../storage/uploads', __DIR__ . '/../storage/extracted'),
                    new ScanService($container->get(PDO::class)),
                    new ParseService()
                );
            },
            AdminController::class => function ($container) {
                $apiKey = getenv('GROQ_API_KEY');
                return new AdminController(
                    $container->get(Environment::class),
                    $container->get(PDO::class),
                    new AiService($apiKey === false ? null : $apiKey)
                );
            },
            ViewDataMiddleware::class => function ($container) {
                return new ViewDataMiddleware(
                    $container->get(Environment::class),
                    $container->get(PDO::class)
                );
            },
        ]);


        $container = $containerBuilder->build();

        // Setup Database Session Handler
        $sessionHandler = new DatabaseSessionHandler($container->get(PDO::class));
        session_set_save_handler($sessionHandler, true);
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Initialize Slim App
        AppFactory::setContainer($container);
        $app = AppFactory::create();

        // Add Middleware
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(
            (getenv('APP_DEBUG') ?: 'false') === 'true',
            true,
            true
        );

        // Routes
        $app->get('/login', [AuthController::class, 'showLogin']);
        $app->post('/auth/login', [AuthController::class, 'login']);
        $app->get('/auth/logout', [AuthController::class, 'logout']);

        // Authenticated Routes
        $app->group('', function ($group) {
            $group->get('/dashboard', [TenantController::class, 'dashboard']);
            $group->get('/projects', [TenantController::class, 'projects']);
            $group->post('/projects/create', [TenantController::class, 'createProject']);
            $group->get('/projects/view/{id}', [TenantController::class, 'viewProject']);
            $group->post('/projects/upload/{id}', [TenantController::class, 'uploadLog']);
            $group->post('/projects/scan/{id}', [TenantController::class, 'startScan']);
            $group->get('/scans/status/{id}', [TenantController::class, 'getScanStatus']);
            $group->get('/scans/report/{id}', [TenantController::class, 'viewReport']);
            $group->post('/scans/delete/{id}', [TenantController::class, 'deleteScan']);
            
            // Profile Routes
            $group->post('/profile/update', [AuthController::class, 'updateProfile']);
            
            // Log File Analysis Routes
            $group->get('/files/raw/{id}', [TenantController::class, 'viewRawData']);
            $group->get('/files/report/{id}', [TenantController::class, 'viewHardwareReport']);
            $group->post('/files/delete/{id}', [TenantController::class, 'deleteLogFile']);
            
            // Admin Routes
            $group->get('/admin', [AdminController::class, 'dashboard']);
            $group->get('/admin/tenants', [AdminController::class, 'tenants']);
            $group->post('/admin/tenants/create', [AdminController::class, 'createTenant']);
            $group->post('/admin/tenants/update', [AdminController::class, 'updateTenant']);
            $group->post('/admin/tenants/toggle-status', [AdminController::class, 'toggleTenantStatus']);
            $group->post('/admin/tenants/delete', [AdminController::class, 'deleteTenant']);
            $group->post('/admin/tenants/allocate', [AdminController::class, 'allocateTokens']);
            $group->get('/admin/logs', [AdminController::class, 'logs']);
            $group->get('/admin/settings', [AdminController::class, 'settings']);
            $group->post('/admin/settings/update', [AdminController::class, 'updateSettings']);
            $group->post('/admin/settings/reset', [AdminController::class, 'resetSystem']);
            $group->get('/admin/scans', [AdminController::class, 'scans']);
            $group->get('/admin/scans/status/{id}', [AdminController::class, 'getScanStatus']);
            $group->post('/admin/scans/abort/{id}', [AdminController::class, 'abortScan']);
            $group->get('/admin/scans/raw/{id}', [AdminController::class, 'downloadRawData']);
            $group->get('/admin/scans/report/{id}', [AdminController::class, 'downloadReport']);
        })->add($container->get(ViewDataMiddleware::class))
          ->add(new AuthMiddleware($container->get(PDO::class)));


        return $app;
    }
}
