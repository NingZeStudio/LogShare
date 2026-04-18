<?php

$config = [

    /**
     * Available storages with ID, name and class
     *
     * The class should implement \Storage\StorageInterface
     */
    "storages" => [
        "m" => [
            "name" => "MongoDB",
            "class" => "\\Storage\\Mongo",
            "enabled" => true
        ],
        "f" => [
            "name" => "Filesystem",
            "class" => "\\Storage\\Filesystem",
            "enabled" => false
        ],
        "r" => [
            "name" => "Redis",
            "class" => "\\Storage\\Redis",
            "enabled" => false
        ]
    ],

    /**
     * Current storage id for new data
     *
     * Should be a key in the $storages array
     */
    "storageId" => "m",

    /**
     * Time in seconds to store data after put or last renew
     */
    "storageTime" => 7 * 24 * 60 * 60,

    /**
     * Redis cache TTL in seconds (30 minutes)
     * New logs will be cached in Redis for this duration
     */
    "redisCacheTTL" => 30 * 60,

    /**
     * Maximum log size (in bytes) to be cached in Redis
     * Logs larger than this will only be stored in MongoDB
     * Default: 600KB (600 * 1024 bytes)
     */
    "redisCacheMaxSize" => 600 * 1024,

    /**
     * Maximum string length to store
     *
     * If exceeded, will return error instead of truncating
     */
    "maxLength" => 10 * 1024 * 1024,

    /**
     * Maximum number of lines to store
     *
     * If exceeded, will return error instead of truncating
     */
    "maxLines" => 50_000

];
