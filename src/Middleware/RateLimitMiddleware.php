<?php

declare(strict_types=1);

namespace App\Middleware;

use Predis\Client as RedisClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

class RateLimitMiddleware implements MiddlewareInterface
{
    private RedisClient $redis;
    private int $limit;
    private int $window;

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
