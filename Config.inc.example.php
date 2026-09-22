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
    'rateLimit' => [
        'enabled' => false,
        'trustedProxies' => ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
        'default' => [60, 60],
        'routes' => [
            '/v1/log' => ['limit' => 30, 'window' => 60],
            '/1/log' => ['limit' => 30, 'window' => 60],
            '/v1/ai' => ['limit' => 10, 'window' => 60],
            '/1/ai' => ['limit' => 10, 'window' => 60],
            '/rag' => ['limit' => 60, 'window' => 60],
        ],
    ],

    /* ─── Security（安全防御与合规规则）──────────────────── */
    'security' => [
        'enabled' => true,
        // 受信任反向代理列表（用于识别客户端真实 IP，支持 CIDR 网段）
        'trustedProxies' => ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
        // 违规内容前置过滤规则
        'contentRules' => [
            'enabled' => true,
            'keywords' => [],
            'patterns' => [],
        ],
        // 预设封禁 IP 列表与策略
        'ipBans' => [],
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
        // 完全禁用 AI 分析（/v1/ai/* 返回 404）。设为 false 时无需配置 apiKeys。
        'enabled' => false,
        'apiKeys' => [
            // Configure AI_API_KEYS in .env to enable AI.
        ],
        'baseUrl' => '',
        'model' => '',
        'timeout' => 180,
        // 自定义 HTTP 请求头（键值对，例如 ['HTTP-Referer' => 'https://logshare.cn', 'X-Title' => 'LogShare']）
        'headers' => [
            // 'HTTP-Referer' => 'https://logshare.cn',
            // 'X-Title' => 'LogShare',
        ],
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
            // 分块策略：heading（默认，与旧版索引逐字节一致）/ sliding / token / hybrid。
            // 切换后需重跑 rag:build；hybrid 保留父块并对超长块二次切分。
            'chunker' => 'heading',
            // RRF 融合 + LLM 精排：开启后语义检索改走 RetrievalPipeline（词法+向量
            // 排名倒数融合，再交 LLM 单次 listwise 精排）。默认关闭时保持既有
            // 「向量优先、词法补充」合并，排序逐字节不变。无 AI 密钥时自动降级
            // 为仅 RRF（Noop 精排）。
            'rerank' => [
                'enabled' => false,
                'maxCandidates' => 30,
            ],
            // 查询预处理：LLM 把中文症状查询扩写出英文异常类名/关键词喂给
            // FTS 通道；规则分类对无显式 topic 的检索做目录偏置提权（分类偏置
            // 随本开关启用）。关闭时检索路径逐字节不变。
            'queryRewrite' => [
                'enabled' => false,
            ],
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

    /* ─── GitHub 启动器/渲染器排障工具配置 ─────────────────── */
    'github' => [
        // 是否启用 Agent Loop 中的 GitHub 排障检索工具（github_list_repos, github_search, github_get_content）
        // 开启后模型可自动在线检索开源启动器与渲染器的 Issues、PRs 与 Discussions
        'enabled' => false,

        // GitHub Personal Access Token (PAT) 列表，支持多 Token 轮询与故障转移
        // 环境变量 GITHUB_TOKENS 或 GITHUB_TOKEN 可用逗号分隔覆盖此项
        // 未配置 Token 时走未认证模式（严格受限于 IP 速率，且无法检索 Discussions）
        'tokens' => [],

        // 可选 HTTP/SOCKS5 代理地址（例如 'http://127.0.0.1:7890'）
        'proxy' => '',

        // 请求超时时间（秒）
        'timeout' => 8,

        // Redis 缓存时长（秒，默认 3600）
        'cache_ttl' => 3600,

        // 单词检索最大返回结果条数（默认 5，最大 10）
        'max_results' => 5,

        // 官方推荐排障仓库映射清单（供 github_list_repos 查询与别名解析）
        'repos' => [
            'fcl' => [
                'name' => 'FoldCraftLauncher',
                'repo' => 'FCL-Team/FoldCraftLauncher',
                'aliases' => ['fcl', 'foldcraft'],
                'desc' => 'Android 平台移动端启动器，涵盖 Java 运行时安装、触控布局、模组配置等问题',
            ],
            'pojav' => [
                'name' => 'PojavLauncher',
                'repo' => 'PojavLauncherTeam/PojavLauncher',
                'aliases' => ['pojav', 'pojavlauncher'],
                'desc' => '全球主流移动端启动器，包含 libglfw、OpenAL、Java 堆栈与安卓各版本兼容问题',
            ],
            'amethyst' => [
                'name' => 'Amethyst-Launcher',
                'repo' => 'Amethyst-Launcher/Amethyst',
                'aliases' => ['amc', 'amethyst'],
                'desc' => 'PojavLauncher 官方续作，针对新 Android 版本权限、运行时与环境适配',
            ],
            'pgw' => [
                'name' => 'PojavLauncher-Glow-Worm',
                'repo' => 'Glow-Worm-Project/PojavLauncher-Glow-Worm',
                'aliases' => ['pgw', 'glowworm'],
                'desc' => '移动端 Pojav 增强分支，主打扩展渲染器支持与运行优化',
            ],
            'mobileglues' => [
                'name' => 'MobileGlues',
                'repo' => 'sparrow-app/MobileGlues',
                'aliases' => ['mg', 'mobileglues'],
                'desc' => '移动端渲染桥接组件，专门处理图形崩溃、着色器报错与光影兼容性问题',
            ],
            'hmcl' => [
                'name' => 'Hello Minecraft! Launcher',
                'repo' => 'HMCL-dev/HMCL',
                'aliases' => ['hmcl'],
                'desc' => '主流跨平台桌面启动器，涵盖 Fabric/Forge 安装器故障、Java 下载与账号授权',
            ],
        ],
    ],

    /* ─── 管理后台接口（Admin API）────────────────────────── */
    'admin' => [
        // 是否启用 /v1/admin/* 管理端点，关闭时返回 404
        'enabled' => false,
        // 管理员鉴权 Token，请求时通过 Authorization: Bearer <token> 或 X-Admin-Token 传入
        // 可由环境变量 ADMIN_TOKEN 覆盖
        'token' => 'change-this-to-a-secure-random-token',
    ],

    /* ─── 统一事件队列（EventQueue）────────────────────────── */
    'eventQueue' => [
        // 是否启用异步事件队列（关闭时降级为同步执行）
        'enabled' => true,
        // Redis Stream 键名与消费组名
        'stream' => 'events:log:stream',
        'group' => 'log-event-workers',
        // 是否异步执行 SpinYarn 反混淆
        'asyncDeobfuscate' => true,
        // 是否异步执行关键词与正则安全审计
        'asyncSecurityAudit' => true,
    ],

];

