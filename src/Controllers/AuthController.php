<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class AuthController
{
    private Environment $view;
    private AuthService $authService;
    private string $basePath;

    public function __construct(Environment $view, AuthService $authService, string $basePath)
    {
        $this->view = $view;
        $this->authService = $authService;
        $this->basePath = $basePath;
    }

    public function showProfile(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $body = $this->view->render('tenant/profile.twig', [
            'timezones' => \DateTimeZone::listIdentifiers(),
            'active_page' => 'profile'
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function showLogin(Request $request, Response $response): Response
    {
        // Redirect if already logged in
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['user_id'])) {
            $redirect = $_SESSION['role'] === 'admin' ? $this->basePath . '/admin' : $this->basePath . '/dashboard';
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $body = $this->view->render('auth/login.twig', [
            'error' => $request->getQueryParams()['error'] ?? null,
            'csrf_token' => $_SESSION['csrf_token'] ?? ''
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        try {
            $user = $this->authService->authenticate($email, $password);
            
            if ($user) {
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['tenant_id'] = $user['tenant_id'] ?: ($user['role'] === 'tenant' ? $user['id'] : null);
                $_SESSION['role']      = $user['role'];
                $_SESSION['name']      = $user['display_name'];
                $_SESSION['timezone']  = $user['timezone'] ?? null;

                $redirect = $user['role'] === 'admin' ? $this->basePath . '/admin' : $this->basePath . '/dashboard';
                return $response->withHeader('Location', $redirect)->withStatus(302);
            }

            return $response->withHeader('Location', $this->basePath . '/login?error=Invalid+credentials')->withStatus(302);
        } catch (\Exception $e) {
            return $response->withHeader('Location', $this->basePath . '/login?error=' . urlencode($e->getMessage()))->withStatus(302);
        }
    }

    public function logout(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_destroy();
        return $response->withHeader('Location', $this->basePath . '/login')->withStatus(302);
    }

    public function updateProfile(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $displayName = trim($data['display_name'] ?? '');
        $timezone = $data['timezone'] ?? null;
        $password = !empty($data['password']) ? $data['password'] : null;

        if (empty($displayName)) {
            $_SESSION['error'] = "Display Name cannot be empty.";
            return $response->withHeader('Location', $request->getHeaderLine('Referer'))->withStatus(302);
        }

        try {
            $userId = $_SESSION['user_id'];
            $this->authService->updateProfile($userId, $displayName, $timezone, $password);
            
            $_SESSION['name'] = $displayName;
            $_SESSION['timezone'] = $timezone;
            $_SESSION['success'] = "Profile updated successfully.";
        } catch (\Exception $e) {
            $_SESSION['error'] = "Failed to update profile: " . $e->getMessage();
        }

        return $response->withHeader('Location', $request->getHeaderLine('Referer'))->withStatus(302);
    }
}
