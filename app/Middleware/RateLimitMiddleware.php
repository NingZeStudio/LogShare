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
 *
 * 注意：当前未注册到全局中间件链（config/autoload/middlewares.php），属预留实现。
 * Redis 故障时本实现返回 503（fail-closed）——若要启用并追求可用性优先，
 * 需先将故障路径改为放行（fail-open），否则 Redis 抖动会放大为全站不可用。
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
        '#^/rag(?:/.*)?$#',
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
        $path = self::normalizePath($request->getUri()->getPath());
        [$limit, $window] = self::limitsFor($request->getMethod(), $path, $config);

        $server = $request->getServerParams();
        $ip = self::clientIp($server, $config);
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
            \App\Syslog::error('RateLimit', '限流检查失败: ' . $e->getMessage());
            throw new ApiError(503, '限流服务暂时不可用，请稍后重试。');
        }

        return $handler->handle($request);
    }

    private static function limitsFor(string $method, string $path, array $config): array
    {
        $defaults = [(int) ($config['limit'] ?? 600), (int) ($config['window'] ?? 60)];
        foreach ((array) ($config['routes'] ?? []) as $prefix => $route) {
            if (is_array($route) && str_starts_with($path, (string) $prefix)) {
                return [
                    (int) ($route['limit'] ?? $defaults[0]),
                    (int) ($route['window'] ?? $defaults[1]),
                ];
            }
        }
        return $defaults;
    }

    private static function clientIp(array $server, array $config): string
    {
        $remote = (string) ($server['remote_addr'] ?? 'unknown');
        $trustedProxies = $config['trustedProxies'] ?? [];
        if (!is_array($trustedProxies) || !in_array($remote, $trustedProxies, true)) {
            return $remote;
        }

        $realIp = $server['http_x_real_ip'] ?? null;
        if (is_string($realIp) && filter_var($realIp, FILTER_VALIDATE_IP) !== false) {
            return $realIp;
        }

        return $remote;
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
