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
        // password / database 为可选项：Redis 开启 requirepass 或 ACL 时填 password
        'redis' => ['host' => 'redis', 'port' => 6379, 'password' => '${REDIS_PASSWORD}', 'database' => 0],
        'ttl' => 30 * 60,
        'maxSize' => 5 * 1024 * 1024,
    ],

    /* ─── ID ───────────────────────────────────────────────── */
    'id' => [
        'characters' => 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890',
        'length' => 6,
    ],

    /* ─── Filter ───────────────────────────────────────────── */
    'filter' => [
        'pre' => [
            '\\App\\Filter\\EncodingFilter',
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
        // 完全禁用 AI 分析（/v1/ai/* 返回 404）。设为 false 时无需配置 apiKeys。
        'enabled' => false,
        'apiKeys' => [
            // Configure AI_API_KEYS in .env to enable AI.
        ],
        'baseUrl' => '',
        'model' => '',
        'timeout' => 180,
        'agent' => [
            'enabled' => false,
            'maxToolRounds' => 3,
            'maxFileLines' => 50_000,
            'maxFileBytes' => 512 * 1024,
        ],
        // AI 分析微队列（Redis Streams 消费者组）：enabled 后全量分析经
        // ai:analyse:queue 入队、由 ai-queue-consumer 进程以 maxConcurrent 个
        // 协程执行，请求侧只做 SSE 中继（首帧推送 queued 状态与排队位置）。
        // maxQueue 为队列深度上限（XLEN），超限直接 429 + Retry-After。
        // waitTimeout 为中继端最长等待秒数，0 或负数 = 无排队超时（等到
        // done/error 或客户端断开为止）。claimIdleMs 之后 pending 条目被
        // XAUTOCLAIM 重投（消费者崩溃恢复，应大于单任务最长执行时间）。
        // jobTtl 为任务 payload/事件流存活秒数（无排队超时时即任务总寿命）。
        // failOpen=true 时 Redis 故障回退请求内 inline 执行（Redis 为可选依赖）。
        // 依赖 ext-redis 与 cache.redis 可用；关闭时行为与旧版逐字节一致。
        'queue' => [
            'enabled' => false,
            'maxConcurrent' => 2,
            'maxQueue' => 50,
            'waitTimeout' => 300,
            'claimIdleMs' => 120000,
            'jobTtl' => 600,
            'failOpen' => true,
        ],
        // 语义 RAG 增强：bge-m3 向量召回作为主排序，词法结果补充。
        // providers 按顺序做故障切换：主供应商不可用时自动落到下一个；
        // 模型 ID 按 provider 各自填写（硅基流动带 BAAI/ 前缀）。
        // 注意与 ai.mcp.rag（内置 RAG MCP 服务端点）无关。开启后需重跑 rag:build 生成向量。
        'rag' => [
            'enabled' => false,
            'timeout' => 30,
            'providers' => [],
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
                'authToken' => '',
                // SQLite 数据库路径（相对项目根）
                'db' => 'rag/index.db',
            ],
        ],
    ],

    /* ─── SpinYarn（反混淆 PHP 扩展）────────────────────── */
    'spinyarn' => [
        // Yarn/Vanilla 映射目录，相对项目根 ./mappings。
        // 映射表由下载脚本（scripts/download_mappings.sh + download_vanilla_mappings.py）
        // 预先生成并提交进仓库；Docker 部署时 bind mount 宿主机 ./mappings。
        'mappings_dir' => 'mappings',
        'cache_max_entries' => 10,
        'cache_high_watermark' => 10,
        'cache_low_watermark' => 7,
    ],

];
