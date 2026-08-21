<?php

namespace App\Client;

/**
 * Redis connection facade with coroutine-aware connection handling.
 *
 * Under Swoole coroutines the ext-redis `Redis` object would perform
 * synchronous, worker-blocking IO. Instead we use `Swoole\Coroutine\Redis`
 * (non-blocking) and store the connection in the coroutine-scoped Hyperf
 * Context, so each request gets its own connection that is released when the
 * coroutine ends.
 *
 * Outside coroutines (CLI / tests) we fall back to a process-level ext-redis
 * singleton with ping-based reconnection, and throw a catchable Exception when
 * neither ext-redis nor Swoole is available so callers can degrade gracefully.
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
     * @return \Redis|\Swoole\Coroutine\Redis
     * @throws \Exception When Redis is unreachable or unavailable
     */
    protected static function connection()
    {
        $config = \App\Config::Get('cache');
        $redisConfig = $config['redis'] ?? ['host' => 'mclogs-redis', 'port' => 6379];
        $host = (string) ($redisConfig['host'] ?? 'mclogs-redis');
        $port = (int) ($redisConfig['port'] ?? 6379);
        $timeout = (float) ($redisConfig['timeout'] ?? self::CONNECT_TIMEOUT);

        if (self::inCoroutine()) {
            $conn = \Hyperf\Context\Context::get(self::CONTEXT_CONN_KEY);
            if ($conn instanceof \Swoole\Coroutine\Redis && $conn->connected) {
                return $conn;
            }

            $conn = new \Swoole\Coroutine\Redis();
            if (!$conn->connect($host, $port, $timeout)) {
                throw new \Exception('Redis connection failed: ' . $host . ':' . $port);
            }
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

        if (!class_exists('Redis')) {
            // 缺少 ext-redis（如本地开发 / Termux）。抛出可捕获的异常，
            // 避免 `new Redis()` 触发致命 Error。
            throw new \Exception('Redis extension is not installed');
        }

        self::$connection = new \Redis();
        if (!self::$connection->connect($host, $port, $timeout)) {
            self::$connection = null;
            throw new \Exception('Redis connection failed: ' . $host . ':' . $port);
        }
        return self::$connection;
    }

    /**
     * 统一操作封装，屏蔽 Swoole\Coroutine\Redis 与 ext-redis 的 API 差异。
     */

    protected static function opSet(string $key, string $value, ?int $ttl = null): void
    {
        $conn = self::connection();
        if ($conn instanceof \Swoole\Coroutine\Redis) {
            $conn->set($key, $value, $ttl ?? 0);
        } elseif ($ttl) {
            $conn->setEx($key, $ttl, $value);
        } else {
            $conn->set($key, $value);
        }
    }

    protected static function opGet(string $key): ?string
    {
        $conn = self::connection();
        $value = $conn->get($key);
        return $value === false ? null : $value;
    }

    protected static function opExists(string $key): bool
    {
        $conn = self::connection();
        return (bool) $conn->exists($key);
    }

    protected static function opDel(string $key): bool
    {
        $conn = self::connection();
        return (bool) $conn->del($key);
    }

    protected static function opIncr(string $key): int
    {
        $conn = self::connection();
        return (int) $conn->incr($key);
    }

    protected static function opExpire(string $key, int $seconds): bool
    {
        $conn = self::connection();
        return (bool) $conn->expire($key, $seconds);
    }
}
