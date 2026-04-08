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

    public function __construct(Environment $view, AuthService $authService)
    {
        $this->view = $view;
        $this->authService = $authService;
    }

    public function showLogin(Request $request, Response $response): Response
    {
        // Redirect if already logged in
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['user_id'])) {
            $redirect = $_SESSION['role'] === 'admin' ? '/admin' : '/dashboard';
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
                $_SESSION['tenant_id'] = $user['tenant_id'];
                $_SESSION['role']      = $user['role'];
                $_SESSION['name']      = $user['display_name'];

                $redirect = $user['role'] === 'admin' ? '/admin' : '/dashboard';
                return $response->withHeader('Location', $redirect)->withStatus(302);
            }

            return $response->withHeader('Location', '/login?error=Invalid+credentials')->withStatus(302);
        } catch (\Exception $e) {
            return $response->withHeader('Location', '/login?error=' . urlencode($e->getMessage()))->withStatus(302);
        }
    }

    public function logout(Request $request, Response $response): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_destroy();
        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}
