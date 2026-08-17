<?php

namespace Cache;

use Client\RedisClient;

class RedisCache extends RedisClient implements CacheInterface
{

    /**
     * @inheritDoc
     */
    public static function Set(string $key, string $value, ?int $duration = null)
    {
        self::Connect();
        if ($duration) {
            self::$connection->setEx($key, $duration, $value);
        }
        else {
            self::$connection->set($key, $value);
        }
    }

    /**
     * @inheritDoc
     */
    public static function Get(string $key): ?string
    {
        self::Connect();
        $value = self::$connection->get($key);
        return $value === false ? null : $value;
    }

    /**
     * @inheritDoc
     */
    public static function Exists(string $key): bool
    {
        self::Connect();
        return (bool)self::$connection->exists($key);
    }

    /**
     * @inheritDoc
     */
    public static function Delete(string $key): bool
    {
        self::Connect();
        return (bool)self::$connection->del($key);
    }

    /**
     * Atomically increment a counter key (used by the rate limiter).
     *
     * @param string $key
     * @return int New value of the counter
     */
    public static function Incr(string $key): int
    {
        self::Connect();
        return (int)self::$connection->incr($key);
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
        self::Connect();
        return (bool)self::$connection->expire($key, $seconds);
    }
}