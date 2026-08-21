<?php

declare(strict_types=1);

namespace App\Middleware;

use App\ApiError;
use App\Cache\RedisCache;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Per-IP rate limiter via Redis INCR + EXPIRE, replacing the old `Router::checkRateLimit`.
 * Fails open when Redis is unavailable.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $config = \App\Config::Get('rateLimit');
        $limit = (int) ($config['limit'] ?? 36000);
        $window = (int) ($config['window'] ?? 60);

        $server = $request->getServerParams();
        $ip = $server['remote_addr'] ?? 'unknown';
        $key = "rl:{$request->getMethod()}:{$request->getUri()->getPath()}:{$ip}";

        try {
            $current = RedisCache::Incr($key);
            if ($current === 1) {
                RedisCache::Expire($key, $window);
            }
            if ($current > $limit) {
                throw new ApiError(429, 'Rate limit exceeded. Please try again later.');
            }
        } catch (ApiError $e) {
            throw $e;
        } catch (\Throwable $e) {
            // fail-open: skip rate limiting when Redis is unavailable
            error_log("[RateLimit] 限流检查失败: " . $e->getMessage());
        }

        return $handler->handle($request);
    }
}
