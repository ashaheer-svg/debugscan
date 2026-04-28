<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * SecurityHeadersMiddleware: Apply HTTP security headers to all responses
 *
 * PURPOSE:
 * Mitigate browser-level attacks (XSS, clickjacking, MIME sniffing, etc.)
 * Added automatically to all responses (applied in routing/middleware stack)
 *
 * HEADERS:
 * - X-Frame-Options: Prevent clickjacking (SAMEORIGIN only)
 * - X-Content-Type-Options: Prevent MIME sniffing
 * - X-XSS-Protection: Browser XSS filter (fallback for older browsers)
 * - Referrer-Policy: Limit referrer leakage
 * - Content-Security-Policy: Restrict script/style/font sources
 * - Strict-Transport-Security: Force HTTPS (1 year)
 *
 * @package App\Middleware
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * Add security headers to outgoing response
     *
     * Applied to all responses in routing order
     * Headers persist through response chain
     *
     * @param Request $request HTTP request
     * @param Handler $handler Next handler in chain
     *
     * @return Response HTTP response with security headers added
     */
    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);

        return $response
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-XSS-Protection', '1; mode=block')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; frame-ancestors 'none';")
            ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
