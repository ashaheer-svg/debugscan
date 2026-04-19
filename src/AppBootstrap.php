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
use App\Services\ExtractionConfigService;
use App\Services\MailService;
use App\Controllers\AuthController;
use App\Controllers\TenantController;
use App\Controllers\AdminController;
use App\Controllers\ExplorerController;
use App\Controllers\ExtractionConfigController;
use App\Helpers\DatabaseSessionHandler;
use App\Middleware\AuthMiddleware;
use App\Middleware\ViewDataMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Services\ReportPlanService;
use Slim\Csrf\Guard;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Psr\Container\ContainerInterface;

class AppBootstrap
{
    public static function create(): App
    {
        // Load environment variables
        self::loadEnv();

        // Initialize Container
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addDefinitions(self::getDefinitions());
        $container = $containerBuilder->build();

        // Initialize Slim App
        AppFactory::setContainer($container);
        $app = AppFactory::create();

        // Setup System Environment
        $isDebugMode = self::setupSystem($container);

        // Configure Middleware
        self::setupMiddleware($app, $container, $isDebugMode);

        // Ensure storage directories exist
        self::ensureStorageExists();

        // Register Routes
        self::registerRoutes($app, $container);

        return $app;
    }

    private static function loadEnv(): void
    {
        if (file_exists(__DIR__ . '/../.env')) {
            $dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/../');
            $dotenv->load();
        }
    }

    private static function getDefinitions(): array
    {
        return [
            'base_path' => '',
            PDO::class => function () {
                return Database::getConnection();
            },
            Environment::class => function () {
                $loader = new FilesystemLoader(__DIR__ . '/../templates');
                $twig = new Environment($loader, [
                    'cache' => false,
                    'debug' => (getenv('APP_DEBUG') ?: 'false') === 'true',
                ]);

                // Register custom filters
                $twig->addFilter(new \Twig\TwigFilter('json_decode', function ($string) {
                    if ($string === null || $string === '') return null;
                    return json_decode((string)$string, true);
                }));

                return $twig;
            },
            AuthService::class => function (ContainerInterface $container) {
                return new AuthService($container->get(PDO::class));
            },
            AuthController::class => function (ContainerInterface $container) {
                return new AuthController(
                    $container->get(Environment::class),
                    $container->get(AuthService::class),
                    $container->get('base_path'),
                    $container->get(PDO::class)
                );
            },
            TenantController::class => function (ContainerInterface $container) {
                return new TenantController(
                    $container->get(Environment::class),
                    $container->get(PDO::class),
                    new FileService($container->get(PDO::class), __DIR__ . '/../storage/uploads', __DIR__ . '/../storage/extracted'),
                    $container->get(ScanService::class),
                    new ParseService(),
                    $container->get('base_path'),
                    $container->get(MailService::class),
                    $container->get(ReportPlanService::class)
                );
            },
            ScanService::class => function (ContainerInterface $container) {
                return new ScanService(
                    $container->get(PDO::class),
                    $container->get(ReportPlanService::class)
                );
            },
            ReportPlanService::class => function (ContainerInterface $container) {
                return new ReportPlanService($container->get(PDO::class));
            },
            MailService::class => function (ContainerInterface $container) {
                return new MailService($container->get(PDO::class));
            },
            AdminController::class => function (ContainerInterface $container) {
                $apiKey = getenv('GROQ_API_KEY');
                return new AdminController(
                    $container->get(Environment::class),
                    $container->get(PDO::class),
                    new AiService($apiKey === false ? null : $apiKey),
                    $container->get('base_path'),
                    $container->get(MailService::class),
                    $container->get(ReportPlanService::class)
                );
            },
            ViewDataMiddleware::class => function (ContainerInterface $container) {
                return new ViewDataMiddleware(
                    $container->get(Environment::class),
                    $container->get(PDO::class)
                );
            },
            ExtractionConfigService::class => function (ContainerInterface $container) {
                return new ExtractionConfigService($container->get(PDO::class));
            },
            ExtractionConfigController::class => function (ContainerInterface $container) {
                return new ExtractionConfigController(
                    $container->get(Environment::class),
                    $container->get(ExtractionConfigService::class),
                    $container->get('base_path'),
                    $container->get(ReportPlanService::class)
                );
            },
            ResponseFactoryInterface::class => function () {
                return new ResponseFactory();
            },
            \Predis\Client::class => function () {
                $redisUrl = getenv('REDIS_URL') ?: 'tcp://127.0.0.1:6379';
                return new \Predis\Client($redisUrl);
            },
            Guard::class => function (ContainerInterface $container) {
                $responseFactory = $container->get(ResponseFactoryInterface::class);
                $guard = new Guard($responseFactory);
                $guard->setPersistentTokenMode(true);
                $guard->setFailureHandler(function ($request, $handler) {
                    $response = new \Slim\Psr7\Response();
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => 'Security Error: CSRF token validation failed. Please refresh and try again.'
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
                });
                return $guard;
            },
            RateLimitMiddleware::class => function (ContainerInterface $container) {
                $redis = $container->get(\Predis\Client::class);
                // 120 requests per 60 seconds (1 minute)
                return new RateLimitMiddleware($redis, 120, 60);
            },
        ];
    }

    private static function setupSystem(ContainerInterface $container): bool
    {
        $isDebugMode = (getenv('APP_DEBUG') ?: 'false') === 'true';

        // Global Timezone Synchronization
        try {
            $pdo = $container->get(PDO::class);
            $stmt = $pdo->query("SELECT timezone, debug_mode FROM system_settings LIMIT 1");
            $sysSettings = $stmt->fetch();
            if ($sysSettings && isset($sysSettings['debug_mode']) && $sysSettings['debug_mode']) {
                $isDebugMode = true;
            }
            $userTz = $_SESSION['timezone'] ?? null;
            if ($userTz && in_array($userTz, \DateTimeZone::listIdentifiers())) {
                date_default_timezone_set($userTz);
            } else {
                $tz = $sysSettings['timezone'] ?? null;
                if ($tz && in_array($tz, \DateTimeZone::listIdentifiers())) {
                    date_default_timezone_set($tz);
                } else {
                    date_default_timezone_set('UTC');
                }
            }
        } catch (\Exception $e) {
            date_default_timezone_set('UTC');
        }

        // Setup Database Session Handler
        try {
            $sessionHandler = new DatabaseSessionHandler($container->get(PDO::class));
            session_set_save_handler($sessionHandler, true);
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
        } catch (\Exception $e) {
            error_log("Bootstrap Session Failure: " . $e->getMessage());
        }

        return $isDebugMode;
    }

    private static function setupMiddleware(App $app, ContainerInterface $container, bool $isDebugMode): void
    {
        $basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if (strlen($basePath) > 1) {
            $app->setBasePath($basePath);
        }

        $app->addRoutingMiddleware();
        
        // Security Middlewares
        $app->add(SecurityHeadersMiddleware::class);
        // Global CSRF Token Injection for Twig (Runs AFTER Guard is handled)
        $app->add(function($request, $handler) use ($container) {
            $csrf = $container->get(Guard::class);
            $twig = $container->get(Environment::class);
            
            $nameKey = $csrf->getTokenNameKey();
            $valueKey = $csrf->getTokenValueKey();
            $name = $csrf->getTokenName();
            $value = $csrf->getTokenValue();

            $twig->addGlobal('csrf', [
                'keys' => [ 'name' => $nameKey, 'value' => $valueKey ],
                'name' => $name,
                'value' => $value,
                'inputs' => sprintf('<input type="hidden" name="%s" value="%s"><input type="hidden" name="%s" value="%s">', $nameKey, $name, $valueKey, $value)
            ]);

            return $handler->handle($request);
        });

        $app->add(Guard::class);
        $app->addBodyParsingMiddleware();
        
        // Rate Limiting (Global - Covers Login/Auth)
        $app->add(RateLimitMiddleware::class);

        // Standard Error Handling
        $errorMiddleware = $app->addErrorMiddleware($isDebugMode, true, true);
        if (!$isDebugMode) {
            $errorMiddleware->setDefaultErrorHandler(function ($request, $exception, $displayErrorDetails) use ($app) {
                $response = $app->getResponseFactory()->createResponse();
                $isApi = str_contains($request->getUri()->getPath(), '/api/');
                
                if ($isApi) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => 'An internal server error occurred. Please contact technical support.'
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
                }

                $response->getBody()->write('<h2>Internal Server Error</h2><p>Our engineering team has been notified. Please try again later.</p>');
                return $response->withStatus(500);
            });
        }
    }

    private static function ensureStorageExists(): void
    {
        $storageRoot = __DIR__ . '/../storage';
        foreach (['uploads', 'extracted', 'logs', 'reports'] as $dir) {
            $path = $storageRoot . '/' . $dir;
            if (!is_dir($path)) @mkdir($path, 0755, true);
        }
    }

    private static function registerRoutes(App $app, ContainerInterface $container): void
    {
        $app->get('/login', [AuthController::class, 'showLogin']);
        $app->post('/auth/login', [AuthController::class, 'login']);
        $app->get('/auth/logout', [AuthController::class, 'logout']);
        $app->get('/redeem/{code}', [AdminController::class, 'redeemTokens']);

        $app->group('/', function ($group) {
            $group->get('', [TenantController::class, 'dashboard']);
            $group->get('dashboard', [TenantController::class, 'dashboard']);
            $group->get('scans', [TenantController::class, 'scans']);
            $group->get('projects', [TenantController::class, 'projects']);
            $group->post('projects/create', [TenantController::class, 'createProject']);
            $group->get('projects/view/{id}', [TenantController::class, 'viewProject']);
            $group->post('projects/upload/{id}', [TenantController::class, 'uploadLog']);
            $group->post('projects/resolve-mismatch', [TenantController::class, 'resolveSerialMismatch']);
            $group->post('projects/scan/{id}', [TenantController::class, 'startScan']);
            $group->get('scans/status/{id}', [TenantController::class, 'getScanStatus']);
            $group->get('scans/report/{id}', [TenantController::class, 'viewReport']);
            $group->post('scans/delete/{id}', [TenantController::class, 'deleteScan']);
            
            $group->get('tokens/transactions', [TenantController::class, 'transactions']);
            $group->get('audit', [TenantController::class, 'audit']);
            $group->get('audit/export', [TenantController::class, 'exportAudit']);
            $group->post('tokens/purchase', [TenantController::class, 'requestTokens']);
            
            $group->get('profile', [AuthController::class, 'showProfile']);
            $group->post('profile/update', [AuthController::class, 'updateProfile']);
            
            $group->get('files/report/{id}', [TenantController::class, 'viewHardwareReport']);
            $group->post('files/delete/{id}', [TenantController::class, 'deleteLogFile']);
            
            // Admin Routes
            $group->get('admin', [AdminController::class, 'dashboard']);
            $group->get('admin/tenants', [AdminController::class, 'tenants']);
            $group->post('admin/tenants/create', [AdminController::class, 'createTenant']);
            $group->post('admin/tenants/update', [AdminController::class, 'updateTenant']);
            $group->post('admin/tenants/toggle-status', [AdminController::class, 'toggleTenantStatus']);
            $group->post('admin/tenants/delete', [AdminController::class, 'deleteTenant']);
            $group->post('admin/tenants/allocate', [AdminController::class, 'allocateTokens']);
            $group->get('admin/tenants/audit/{id}', [AdminController::class, 'tenantAudit']);
            $group->get('admin/tenants/audit/{id}/export', [AdminController::class, 'exportTenantAudit']);
            $group->get('admin/logs', [AdminController::class, 'logs']);
            $group->get('admin/settings', [AdminController::class, 'settings']);
            $group->post('admin/settings/update', [AdminController::class, 'updateSettings']);
            $group->post('admin/settings/test-email', [AdminController::class, 'testEmail']);
            $group->post('admin/settings/reset', [AdminController::class, 'resetSystem']);
            $group->get('admin/scans', [AdminController::class, 'scans']);
            $group->get('admin/scans/status/{id}', [AdminController::class, 'getScanStatus']);
            $group->get('admin/scans/prompt/{id}', [AdminController::class, 'getScanPromptData']);
            $group->get('admin/files/prompt/{id}', [AdminController::class, 'getPromptData']);
            $group->get('admin/files/download/{id}', [AdminController::class, 'downloadFile']);
            $group->post('admin/scans/abort/{id}', [AdminController::class, 'abortScan']);
            $group->get('admin/scans/raw/{id}', [AdminController::class, 'downloadRawData']);
            $group->get('admin/scans/report/{id}', [AdminController::class, 'downloadReport']);
            $group->get('admin/ai-audit', [AdminController::class, 'aiAudit']);
            
            $group->get('admin/explorer', [ExplorerController::class, 'index']);
            $group->get('admin/explorer/list', [ExplorerController::class, 'list']);
            $group->get('admin/explorer/download', [ExplorerController::class, 'download']);
            $group->post('admin/explorer/delete', [ExplorerController::class, 'delete']);

            $group->get('admin/extraction-config/{id}', [ExtractionConfigController::class, 'showPage']);
            $group->post('admin/extraction-config/save/{id}', [ExtractionConfigController::class, 'saveConfig']);

            $group->get('admin/report-plans', [AdminController::class, 'reportPlans']);
            $group->post('admin/report-plans/save', [AdminController::class, 'saveReportPlan']);
            $group->post('admin/report-plans/delete', [AdminController::class, 'deleteReportPlan']);
        })->add($container->get(ViewDataMiddleware::class))
          ->add(new AuthMiddleware($container->get(PDO::class), $container->get('base_path')));
    }
}
