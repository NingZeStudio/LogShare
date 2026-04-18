<?php

$config = [
    /**
     * A class that should be used to cache data
     * The class should implement \Cache\CacheInterface
     */
    "cacheId" => "\\Cache\\RedisCache",

    /**
     * Redis 配置
     */
    "redis" => [
        "host" => "mclogs-redis",
        "port" => 6379
    ]
];