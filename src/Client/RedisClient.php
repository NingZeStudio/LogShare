<?php

namespace Client;

use Redis;

class RedisClient
{
    /**
     * @var ?Redis
     */
    protected static ?Redis $connection = null;

    /**
     * Connect to redis
     */
    protected static function Connect()
    {
        if(self::$connection === null) {
            $config = \Config::Get('cache');
            $redisConfig = $config['redis'] ?? ['host' => 'mclogs-redis', 'port' => 6379];

            self::$connection = new Redis();
            self::$connection->connect($redisConfig['host'], $redisConfig['port']);
        }
    }
}