<?php

namespace Client;

use Redis;

class RedisClient
{
    private const CONNECT_TIMEOUT = 1.5;

    /**
     * @var ?Redis
     */
    protected static ?Redis $connection = null;

    /**
     * Connect to redis
     *
     * @throws \Exception When Redis is unreachable
     */
    protected static function Connect(): void
    {
        if (self::$connection === null) {
            $config = \Config::Get('cache');
            $redisConfig = $config['redis'] ?? ['host' => 'mclogs-redis', 'port' => 6379];
            $timeout = $redisConfig['timeout'] ?? self::CONNECT_TIMEOUT;

            self::$connection = new Redis();
            if (!self::$connection->connect($redisConfig['host'], $redisConfig['port'], $timeout)) {
                self::$connection = null;
                throw new \Exception('Redis connection failed: ' . $redisConfig['host'] . ':' . $redisConfig['port']);
            }
        }
    }
}