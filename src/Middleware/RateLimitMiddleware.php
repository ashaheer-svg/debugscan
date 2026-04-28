<?php

declare(strict_types=1);

namespace App\Middleware;

use Predis\Client as RedisClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * RateLimitMiddleware: Prevent API abuse via request throttling
 *
 * PURPOSE:
 * Limit requests per IP address within time window
 * Use Redis for distributed rate limit tracking
 * Admin users bypass limits (trusted)
 *
 * CONFIGURATION:
 * limit: Max requests per window (default: 60)
 * window: Time window in seconds (default: 60)
 *
 * WORKFLOW:
 * 1. Extract client IP from REMOTE_ADDR
 * 2. Check if admin (bypass)
 * 3. Get counter from Redis (key: "ratelimit:{md5(ip)}")
 * 4. If counter >= limit: return 429 Too Many Requests
 * 5. Increment counter, set expiry to window duration
 * 6. Pass to next handler
 *
 * RESPONSE:
 * 429 status code
 * JSON body with retry_after (seconds)
 * Allows client to back off intelligently
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private RedisClient $redis;
    private int $limit;
    private int $window;

    /**
     * Constructor: Initialize with Redis client and limits
     *
     * @param RedisClient $redis Redis connection
     * @param int $limit Max requests per window (default: 60)
     * @param int $window Window duration in seconds (default: 60)
     */
    public function __construct(RedisClient $redis, int $limit = 60, int $window = 60)
    {
        $this->redis = $redis;
        $this->limit = $limit;
        $this->window = $window;
    }

    public function process(Request $request, Handler $handler): Response
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $key = "ratelimit:" . md5($ip);

        // Administrator Bypass
        if (session_status() === PHP_SESSION_ACTIVE && ($_SESSION['role'] ?? '') === 'admin') {
            return $handler->handle($request);
        }

        try {
            $current = $this->redis->get($key);

            if ($current !== null && (int)$current >= $this->limit) {
                $response = new SlimResponse();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Too many requests. Please try again in a minute.',
                    'retry_after' => $this->redis->ttl($key)
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(429);
            }

            if ($current === null) {
                $this->redis->setex($key, $this->window, 1);
            } else {
                $this->redis->incr($key);
            }
        } catch (\Exception $e) {
            // If Redis is down, fail open but log it
            error_log("RateLimitMiddleware Redis Error: " . $e->getMessage());
        }

        return $handler->handle($request);
    }
}
