<?php

namespace App\Cache;

use App\Client\RedisClient;

class RedisCache extends RedisClient implements CacheInterface
{

    /**
     * @inheritDoc
     */
    public static function Set(string $key, string $value, ?int $duration = null)
    {
        self::opSet($key, $value, $duration);
    }

    /**
     * @inheritDoc
     */
    public static function Get(string $key): ?string
    {
        return self::opGet($key);
    }

    /**
     * @inheritDoc
     */
    public static function Exists(string $key): bool
    {
        return self::opExists($key);
    }

    /**
     * @inheritDoc
     */
    public static function Delete(string $key): bool
    {
        return self::opDel($key);
    }

    /**
     * Atomically increment a counter key (used by the rate limiter).
     *
     * @param string $key
     * @return int New value of the counter
     */
    public static function Incr(string $key): int
    {
        return self::opIncr($key);
    }

    /**
     * Set a TTL on an existing key (used by the rate limiter).
     *
     * @param string $key
     * @param int $seconds
     * @return bool
     */
    public static function Expire(string $key, int $seconds): bool
    {
        return self::opExpire($key, $seconds);
    }
}
