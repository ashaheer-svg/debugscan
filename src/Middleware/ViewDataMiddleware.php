<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Twig\Environment;
use PDO;

class ViewDataMiddleware implements MiddlewareInterface
{
    private Environment $twig;
    private PDO $pdo;

    public function __construct(Environment $twig, PDO $pdo)
    {
        $this->twig = $twig;
        $this->pdo = $pdo;
    }

    public function process(Request $request, Handler $handler): Response
    {
        $userId = $request->getAttribute('user_id');

        if ($userId) {
            try {
                $stmt = $this->pdo->prepare("SELECT display_name, tokens_available, role, timezone FROM users WHERE id = :id");
                $stmt->execute(['id' => $userId]);
                $user = $stmt->fetch();

                if ($user) {
                    $this->twig->addGlobal('user_name', $user['display_name']);
                    $this->twig->addGlobal('tokens_available', $user['tokens_available']);
                    $this->twig->addGlobal('user_role', $user['role']);
                    $this->twig->addGlobal('user_timezone', ($user['timezone'] ?? 'UTC') ?: 'UTC');
                    $this->twig->addGlobal('all_timezones', \DateTimeZone::listIdentifiers());
                }

                // DeepDive feature flag — exposed as {{ tenant.deepdive_enabled }} in templates.
                // Wrapped in its own try/catch so a missing column (pre-migration) cannot break the page.
                try {
                    $tenantId = $request->getAttribute('tenant_id');
                    if ($tenantId) {
                        $stmtDD = $this->pdo->prepare("SELECT deepdive_enabled FROM users WHERE id = :id");
                        $stmtDD->execute(['id' => $tenantId]);
                        $this->twig->addGlobal('tenant', [
                            'id'               => $tenantId,
                            'deepdive_enabled' => (bool)$stmtDD->fetchColumn(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->twig->addGlobal('tenant', ['deepdive_enabled' => false]);
                }
            } catch (\Exception $e) {
                // Graceful fallback if migration is pending
                $this->twig->addGlobal('user_timezone', 'UTC');
                $this->twig->addGlobal('all_timezones', \DateTimeZone::listIdentifiers());
            }
        }

        // Global Base Path (Ensures links work in subdirectories if ever needed)
        $this->twig->addGlobal('base_path', '');

        return $handler->handle($request);
    }
}
