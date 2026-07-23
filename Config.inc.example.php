<?php

/**
 * LogShare Configuration
 *
 * Copy this file to Config.inc.php and fill in your values.
 * Config.inc.php is gitignored.
 */

return [

    /* ─── Storage ─────────────────────────────────────────── */
    'storage' => [
        'storages' => [
            'm' => ['name' => 'MongoDB',     'class' => '\\Storage\\MongoStorage',       'enabled' => true],
            'f' => ['name' => 'Filesystem',  'class' => '\\Storage\\FilesystemStorage',  'enabled' => false],
            'r' => ['name' => 'Redis',        'class' => '\\Storage\\RedisStorage',       'enabled' => false],
        ],
        'storageId' => 'm',
        'storageTime' => 7 * 24 * 60 * 60,
        'redisCacheTTL' => 30 * 60,
        'redisCacheMaxSize' => 600 * 1024,
        'maxLength' => 10 * 1024 * 1024,
        'maxLines' => 50_000,
    ],

    /* ─── Cache ────────────────────────────────────────────── */
    'cache' => [
        'cacheId' => '\\Cache\\RedisCache',
        'redis' => ['host' => 'mclogs-redis', 'port' => 6379],
    ],

    /* ─── ID ───────────────────────────────────────────────── */
    'id' => [
        'characters' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
        'length' => 6,
    ],

    /* ─── Filter ───────────────────────────────────────────── */
    'filter' => [
        'pre' => [
            '\\Filter\\TrimFilter',
            '\\Filter\\LimitBytesFilter',
            '\\Filter\\LimitLinesFilter',
            '\\Filter\\IPv4Filter',
            '\\Filter\\IPv6Filter',
            '\\Filter\\UsernameFilter',
            '\\Filter\\AccessTokenFilter',
        ],
    ],

    /* ─── URLs ─────────────────────────────────────────────── */
    'urls' => [
        'baseUrl' => 'https://logshare.cn',
        'apiBaseUrl' => 'https://api.logshare.cn',
    ],

    /* ─── Legal ────────────────────────────────────────────── */
    'legal' => [
        'abuseEmail' => 'mengze2@foxmail.com',
        'imprint' => 'https://aternos.gmbh/imprint/',
        'privacy' => 'https://aternos.gmbh/en/mclogs/privacy',
    ],

    /* ─── Filesystem ───────────────────────────────────────── */
    'filesystem' => [
        'path' => '/storage/logs/',
    ],

    /* ─── MongoDB ──────────────────────────────────────────── */
    'mongo' => [
        'url' => 'mongodb://mclogs-mongo/',
        'database' => 'mclogs',
    ],

    /* ─── AI ───────────────────────────────────────────────── */
    'ai' => [
        'apiKeys' => [
            // Add your NVIDIA API keys here
        ],
        'baseUrl' => 'https://integrate.api.nvidia.com/v1/chat/completions',
        'model' => 'stepfun-ai/step-3.7-flash',
        'timeout' => 180,
    ],

];
