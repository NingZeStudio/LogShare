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
 *
 * Key 口径为 IP + method + 归一化 path：动态资源段（如 /v1/raw/{id}）折叠为
 * 固定前缀，避免攻击者用随机 id 生成无限个独立计数桶绕过限流。
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * 动态路由前缀：这些前缀下的所有子路径共享同一个限流桶。
     * 注意 POST/DELETE /v1/log（无尾段）不受影响，仅折叠带资源段的请求。
     */
    private const DYNAMIC_PATH_REGEXES = [
        '#^(/v?1/raw)/.+#',
        '#^(/v?1/log)/.+#',
        '#^(/v?1/insights)/.+#',
        '#^(/v?1/ai)/.+#',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 全局缓存关闭（如本地开发无 Redis）时跳过限流，避免每个请求刷降级日志。
        // 生产部署注意：关闭 cache.enabled 会一并失去限流能力。
        static $skipWarned = false;
        if ((\App\Config::Get('cache')['enabled'] ?? true) === false) {
            if (!$skipWarned) {
                $skipWarned = true;
                \App\Syslog::error('RateLimit', 'cache.enabled=false — rate limiting is DISABLED until the cache is re-enabled');
            }
            return $handler->handle($request);
        }

        $config = \App\Config::Get('rateLimit');
        $limit = (int) ($config['limit'] ?? 36000);
        $window = (int) ($config['window'] ?? 60);

        $server = $request->getServerParams();
        $ip = $server['remote_addr'] ?? 'unknown';
        $path = self::normalizePath($request->getUri()->getPath());
        $key = "rl:{$request->getMethod()}:{$path}:{$ip}";

        try {
            // SET NX EX 先行初始化，保证计数 key 永远携带 TTL：
            // 即使进程在 INCR 后崩溃，也不会留下无过期的永久限流 key。
            RedisCache::InitCounter($key, $window);
            $current = RedisCache::Incr($key);
            if ($current > $limit) {
                throw new ApiError(429, 'Rate limit exceeded. Please try again later.');
            }
        } catch (ApiError $e) {
            throw $e;
        } catch (\Throwable $e) {
            // fail-open: skip rate limiting when Redis is unavailable
            \App\Syslog::error('RateLimit', '限流检查失败: ' . $e->getMessage());
        }

        return $handler->handle($request);
    }

    /**
     * Collapse dynamic resource segments into a fixed bucket prefix.
     */
    public static function normalizePath(string $path): string
    {
        foreach (self::DYNAMIC_PATH_REGEXES as $regex) {
            $normalized = preg_replace($regex, '$1/*', $path);
            if ($normalized !== null && $normalized !== $path) {
                return $normalized;
            }
        }
        return $path;
    }
}
