<?php

namespace App\Client;

/**
 * Redis connection facade with coroutine-safe connection handling.
 *
 * Swoole 5+/6+ hooks ext-redis via SWOOLE_HOOK_ALL, so ext-redis is already
 * coroutine-aware (non-blocking). The remaining hazard is sharing a single
 * process-level connection across concurrent coroutines, which can interleave
 * request/response frames. We therefore store the connection in the
 * coroutine-scoped Hyperf Context so each request gets its own connection that
 * is released when the coroutine ends.
 *
 * Outside coroutines (CLI / tests) we fall back to a process-level singleton
 * with ping-based reconnection, and throw a catchable Exception when ext-redis
 * is unavailable so callers can degrade gracefully.
 */
class RedisClient
{
    private const CONNECT_TIMEOUT = 1.5;
    private const CONTEXT_CONN_KEY = 'logshare_redis_connection';

    /**
     * ext-redis 进程级单例（仅非协程环境使用）。
     *
     * @var ?\Redis
     */
    protected static ?\Redis $connection = null;

    protected static function inCoroutine(): bool
    {
        return extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * Return a usable Redis connection for the current context.
     *
     * @return \Redis
     * @throws \Exception When Redis is unreachable or unavailable
     */
    protected static function connection(): \Redis
    {
        $config = \App\Config::Get('cache');
        $redisConfig = $config['redis'] ?? ['host' => 'mclogs-redis', 'port' => 6379];
        $host = (string) ($redisConfig['host'] ?? 'mclogs-redis');
        $port = (int) ($redisConfig['port'] ?? 6379);
        $timeout = (float) ($redisConfig['timeout'] ?? self::CONNECT_TIMEOUT);

        if (self::inCoroutine()) {
            $conn = \Hyperf\Context\Context::get(self::CONTEXT_CONN_KEY);
            if ($conn instanceof \Redis && $conn->isConnected()) {
                return $conn;
            }

            $conn = self::createConnection($host, $port, $timeout);
            \Hyperf\Context\Context::set(self::CONTEXT_CONN_KEY, $conn);
            return $conn;
        }

        if (self::$connection !== null) {
            try {
                if (@self::$connection->ping()) {
                    return self::$connection;
                }
            } catch (\Throwable $e) {
                // 连接失效（如 Redis 重启）→ 重连
            }
            self::$connection = null;
        }

        self::$connection = self::createConnection($host, $port, $timeout);
        return self::$connection;
    }

    private static function createConnection(string $host, int $port, float $timeout): \Redis
    {
        if (!class_exists('Redis')) {
            // 缺少 ext-redis（如本地开发 / Termux）。抛出可捕获的异常，
            // 避免 `new Redis()` 触发致命 Error。
            throw new \Exception('Redis extension is not installed');
        }

        $conn = new \Redis();
        if (!$conn->connect($host, $port, $timeout)) {
            throw new \Exception('Redis connection failed: ' . $host . ':' . $port);
        }
        return $conn;
    }

    /**
     * 统一操作封装。
     */

    protected static function opSet(string $key, string $value, ?int $ttl = null): void
    {
        $conn = self::connection();
        if ($ttl) {
            $conn->setEx($key, $ttl, $value);
        } else {
            $conn->set($key, $value);
        }
    }

    protected static function opGet(string $key): ?string
    {
        $value = self::connection()->get($key);
        return $value === false ? null : $value;
    }

    protected static function opExists(string $key): bool
    {
        return (bool) self::connection()->exists($key);
    }

    protected static function opDel(string $key): bool
    {
        return (bool) self::connection()->del($key);
    }

    protected static function opIncr(string $key): int
    {
        return (int) self::connection()->incr($key);
    }

    protected static function opExpire(string $key, int $seconds): bool
    {
        return (bool) self::connection()->expire($key, $seconds);
    }
}
