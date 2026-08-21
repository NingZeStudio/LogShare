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
            's' => ['name' => 'MariaDB',     'class' => '\\App\\Storage\\MariaDbStorage',      'enabled' => true],
            'f' => ['name' => 'Filesystem',  'class' => '\\App\\Storage\\FilesystemStorage',  'enabled' => false],
        ],
        'storageId' => 's',
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
        'cacheId' => '\\App\\Cache\\RedisCache',
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
            '\\App\\Filter\\TrimFilter',
            '\\App\\Filter\\LimitBytesFilter',
            '\\App\\Filter\\LimitLinesFilter',
            '\\App\\Filter\\IPv4Filter',
            '\\App\\Filter\\IPv6Filter',
            '\\App\\Filter\\IPv6ShortFilter',
            '\\App\\Filter\\UuidFilter',
            '\\App\\Filter\\XuidFilter',
            '\\App\\Filter\\SessionTokenFilter',
            '\\App\\Filter\\ClientIdFilter',
            '\\App\\Filter\\CoordinateFilter',
            '\\App\\Filter\\UsernameFilter',
            '\\App\\Filter\\AccessTokenFilter',
        ],
    ],

    /* ─── URLs ─────────────────────────────────────────────── */
    'urls' => [
        'baseUrl' => 'https://logshare.cn',
        'apiBaseUrl' => 'https://api.logshare.cn',
    ],

    /* ─── Rate limit（Redis INCR 限流，按 IP + method + path）── */
    'rateLimit' => [
        'limit' => 36000,
        'window' => 60,
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
                // 内置 RAG MCP server（SQLite FTS5 纯本地检索），已整合进 Hyperf 进程
                // （主 http server 的 /rag 路径，由 RagController 承载 MCP JSON-RPC）。
                'url' => 'http://127.0.0.1:9501/rag',
                'headers' => [],
                // SQLite 数据库路径（相对项目根）
                'db' => 'rag/index.db',
            ],
        ],
    ],

    /* ─── SpinYarn（反混淆 PHP 扩展）────────────────────── */
    'spinyarn' => [
        // Yarn/Vanilla 映射目录，相对项目根 ./mappings。
        // 本地开发：运行 scripts/download_mappings.sh 预下载映射；
        // Docker 部署：命名卷挂载到 /app/mappings，auto_download 自动补全，无需改动。
        'mappings_dir' => 'mappings',
        'auto_download' => true,
        'cache_max_entries' => 44,
        'cache_high_watermark' => 40,
        'cache_low_watermark' => 30,
    ],

];
