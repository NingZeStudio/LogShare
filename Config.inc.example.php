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
        ],
        'storageId' => 'm',
        'storageTime' => 7 * 24 * 60 * 60,
        'maxLength' => 10 * 1024 * 1024,
        'maxLines' => 50_000,
        'uploadFiles' => [
            'maxFiles' => 200,
            'maxTotalBytes' => 12 * 1024 * 1024,
        ],
    ],

    /* ─── Cache (Redis) ──────────────────────────────────── */
    'cache' => [
        'cacheId' => '\\Cache\\RedisCache',
        'enabled' => true,
        'redis' => ['host' => 'mclogs-redis', 'port' => 6379],
        'ttl' => 30 * 60,
        'maxSize' => 600 * 1024,
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
            '\\Filter\\IPv6ShortFilter',
            '\\Filter\\UuidFilter',
            '\\Filter\\XuidFilter',
            '\\Filter\\SessionTokenFilter',
            '\\Filter\\ClientIdFilter',
            '\\Filter\\CoordinateFilter',
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
        'agent' => [
            'enabled' => false,
            'maxToolRounds' => 3,
            'maxFileLines' => 500,
            'maxFileBytes' => 16 * 1024,
        ],
        'mcp' => [
            'webSearch' => [
                'url' => 'https://mcp.exa.ai/mcp',
                'headers' => [],
            ],
            'rag' => [
                // 本地 RAG MCP server（SQLite FTS5）：php -S 127.0.0.1:8081 rag/server.php
                // 先构建索引：php rag/build_index.php
                'url' => null,
                'headers' => [],
            ],
        ],
    ],

];
