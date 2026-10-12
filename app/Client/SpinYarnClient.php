<?php

namespace App\Client;

/**
 * SpinYarn PHP 扩展封装：进程内 Minecraft 日志反混淆。
 *
 * 扩展（spinyarn.so，底层为 libspinyarn_capi）提供
 * spinyarn_init()/spinyarn_deobfuscate() 等函数。真实契约为
 * spinyarn_init(?string $mappings_dir = null, int $cache_max = 44,
 *   int $cache_high = 40, int $cache_low = 30, ?string $redis_url = null)，
 * 仅 $redis_url 非空时启用 Redis 共享缓存，否则为按需解析（on-demand，无缓存，
 * 所谓“本地缓存”已不存在）。spinyarn_deobfuscate 成功返回 assoc 数组
 * （deobfuscated/classes_mapped/methods_mapped/fields_mapped/total_time_ms），
 * 失败返回 false。另有 spinyarn_has_mapping($handle, $version, $mapping) 抽查。
 * 若扩展未加载（如本地开发环境），调用一律降级为 null，原样透传。
 */
class SpinYarnClient
{
    private static bool $checked = false;
    private static bool $available = false;

    /**
     * Check whether the spinyarn PHP extension is loaded.
     */
    public static function isAvailable(): bool
    {
        if (!self::$checked) {
            self::$available = function_exists('spinyarn_deobfuscate');
            self::$checked = true;
        }
        return self::$available;
    }

    /**
     * 校验 Minecraft 版本格式是否合法。
     *
     * 接受主版本形态：1.20 / 1.20.1，附带可选预发布后缀（-pre1 / -rc.1）与
     * 构建元数据（+build.1，如 1.21.3+vanilla）。
     */
    public static function isValidVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+(\.\d+)?(-[0-9A-Za-z.]+)?(\+[0-9A-Za-z.]+)?$/', $version) === 1;
    }

    /**
     * 归一化映射类型：去首尾空白后转小写，yarn → 'yarn'，vanilla → 'vanilla'，
     * 其他一律返回 null（调用方据此判定非法，不再静默默认 yarn）。
     */
    public static function normalizeMappingType(string $t): ?string
    {
        $v = strtolower(trim($t));
        if ($v === 'yarn') {
            return 'yarn';
        }
        if ($v === 'vanilla') {
            return 'vanilla';
        }
        return null;
    }

    /**
     * Deobfuscate a log content in-process.
     *
     * @param string $content Log text (may contain obfuscated stack traces)
     * @param string $version Minecraft version, e.g. "1.20.1"
     * @param string $mappingType "yarn" (Fabric) or "vanilla" (Mojang official)
     * @param array|null $stats 成功时填入扩展返回的计数
     *   （classes_mapped/methods_mapped/fields_mapped/total_time_ms），失败时为 null
     * @return string|null Deobfuscated text, or null to pass through unchanged
     */
    public static function deobfuscate(string $content, string $version, string $mappingType, ?array &$stats = null): ?string
    {
        $stats = null;
        if (!self::isAvailable()) {
            return null;
        }

        if (!self::isValidVersion($version)) {
            \App\Syslog::error("SpinYarn", "非法版本格式，已透传: version={$version}");
            return null;
        }

        $normalized = self::normalizeMappingType($mappingType);
        if ($normalized === null) {
            \App\Syslog::error("SpinYarn", "非法映射类型，已透传: mappingType={$mappingType}");
            return null;
        }

        try {
            $handle = self::getHandle();
            if ($handle === false) {
                return null;
            }

            // 扩展缺常量定义时按 YARN=0/VANILLA=1 字面量兜底，避免未定义常量 fatal
            if ($normalized === 'vanilla') {
                $mapping = defined('SPINYARN_VANILLA') ? SPINYARN_VANILLA : 1;
            } else {
                $mapping = defined('SPINYARN_YARN') ? SPINYARN_YARN : 0;
            }
            $result = spinyarn_deobfuscate($handle, $content, $version, $mapping);

            if ($result === false || !is_array($result)) {
                // 只记录版本/类型/正文长度，绝不记录日志正文（敏感信息泄露防护）
                \App\Syslog::warning("SpinYarn", "反混淆返回异常，已透传: version={$version} mappingType={$normalized} contentLen=" . strlen($content));
                return null;
            }

            $deobfuscated = $result['deobfuscated'] ?? null;
            if (!is_string($deobfuscated) || $deobfuscated === '') {
                return null;
            }
            $stats = [
                'classes_mapped' => (int) ($result['classes_mapped'] ?? 0),
                'methods_mapped' => (int) ($result['methods_mapped'] ?? 0),
                'fields_mapped' => (int) ($result['fields_mapped'] ?? 0),
                'total_time_ms' => (int) ($result['total_time_ms'] ?? 0),
            ];
            return $deobfuscated;
        } catch (\Throwable $e) {
            \App\Syslog::error("SpinYarn", "反混淆失败: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 已加载扩展的 spinyarn_init 是否接受第 5 个 redis_url 参数。
     *
     * v1.0.0（线上镜像 pin 版本）只声明 4 参、无 Redis 支持；redis_url 是
     * main 分支未发布能力。对旧扩展盲传 5 参会抛 ArgumentCountError，被
     * fail-open 捕获后整个进程生命周期反混淆永久停用（2026-09 线上故障根因）。
     * 以反射探测签名，按版本适配传参。
     */
    public static function supportsRedisArg(): bool
    {
        static $supports = null;
        if ($supports === null) {
            try {
                $rf = new \ReflectionFunction('spinyarn_init');
                $supports = $rf->getNumberOfParameters() >= 5;
            } catch (\Throwable $e) {
                $supports = false;
            }
        }
        return $supports;
    }

    /**
     * 抽查扩展侧是否存在指定版本的映射（文件 + 缓存）。
     *
     * @return bool|null true/false，有扩展缺失/句柄失败/参数非法时返回 null
     */
    public static function hasMapping(string $version, string $mappingType): ?bool
    {
        if (!self::isAvailable()) {
            return null;
        }
        if (!self::isValidVersion($version)) {
            return null;
        }
        $normalized = self::normalizeMappingType($mappingType);
        if ($normalized === null) {
            return null;
        }
        if (!function_exists('spinyarn_has_mapping')) {
            return null;
        }
        try {
            $handle = self::getHandle();
            if ($handle === false) {
                return null;
            }
            $mapping = $normalized === 'vanilla'
                ? (defined('SPINYARN_VANILLA') ? SPINYARN_VANILLA : 1)
                : (defined('SPINYARN_YARN') ? SPINYARN_YARN : 0);
            return (bool) spinyarn_has_mapping($handle, $version, $mapping);
        } catch (\Throwable $e) {
            \App\Syslog::error("SpinYarn", "has_mapping 检查失败: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Lazily create (and reuse) the extension handle for this request.
     *
     * 协程安全性说明：Swoole 协程是单线程事件循环模型，`spinyarn_deobfuscate`
     * 为同步 CPU 调用（gzip 解压 + 映射解析 + 反混淆，期间不发生 IO yield），
     * 调用期间会阻塞当前 worker 的事件循环，同进程其他协程无法推进；多 worker
     * 部署下阻塞被放大 N 倍。无 Redis 共享缓存时每次缓存 miss 都要按需解析
     * 映射表（百 ms 级成本），命中时仅为内存查表。进程级 `static $handle`
     * 复用本身不会产生跨协程串扰（同一时刻只有一个协程真正执行扩展调用），
     * 但调用方仍应控制探针并发与正文体积。
     *
     * 失败节流：初始化失败不再永久缓存 false，失败后 60s 内直接返回 false，
     * 过期后下次调用自动重试；成功句柄仍跨请求复用。对 mappingsDir、redisUrl、
     * 三水位算 hash，配置变化时重建句柄（新句柄成功才替换，失败保留旧句柄）。
     *
     * @return mixed resource handle, or false on failure
     */
    private static function getHandle()
    {
        static $handle = null;
        static $configHash = null;
        static $lastFailureAt = 0;

        $config = \App\Config::Get('spinyarn');
        if (!is_array($config)) {
            $config = [];
        }
        $mappingsDir = self::resolveMappingsDir((string) ($config['mappings_dir'] ?? ''));
        $cacheMax = (int) ($config['cache_max_entries'] ?? 10);
        $cacheHigh = (int) ($config['cache_high_watermark'] ?? 10);
        $cacheLow = (int) ($config['cache_low_watermark'] ?? 7);
        $configuredRedisUrl = self::resolveRedisUrl();
        $redisUrl = self::supportsRedisArg() ? $configuredRedisUrl : null;

        if ($configuredRedisUrl !== null && $redisUrl === null) {
            // Redis 已配置但扩展签名不支持第 5 参：提示一次，按 on-demand（无缓存）模式继续
            static $notified = false;
            if (!$notified) {
                $notified = true;
                \App\Syslog::error("SpinYarn", "loaded spinyarn extension has no redis support (spinyarn_init takes 4 params); continuing on-demand (no cache)");
            }
        }

        $hash = md5(serialize([$mappingsDir, $redisUrl, $cacheMax, $cacheHigh, $cacheLow]));
        $hasOldHandle = ($handle !== null && $handle !== false);

        // 成功句柄且配置未变：直接复用
        if ($hasOldHandle && $configHash === $hash) {
            return $handle;
        }
        // 无句柄且同配置近期失败过：60s 内节流，不再重试
        if (!$hasOldHandle && $configHash === $hash && $lastFailureAt !== 0 && (time() - $lastFailureAt) < 60) {
            return false;
        }

        try {
            $newHandle = $redisUrl !== null
                ? spinyarn_init($mappingsDir, $cacheMax, $cacheHigh, $cacheLow, $redisUrl)
                : spinyarn_init($mappingsDir, $cacheMax, $cacheHigh, $cacheLow);
            // redis 模式初始化被扩展拒绝（返回 false）：回退 on-demand（无缓存）模式重试，
            // 缓存层缺失不应拖垮反混淆本身
            if ($newHandle === false && $redisUrl !== null) {
                \App\Syslog::error("SpinYarn", "redis-backed init failed, retrying on-demand (no cache)");
                $newHandle = spinyarn_init($mappingsDir, $cacheMax, $cacheHigh, $cacheLow);
            }
            if ($newHandle === false || $newHandle === null) {
                \App\Syslog::error("SpinYarn", "初始化失败，已降级为透传");
                if ($hasOldHandle) {
                    // 配置变化但新句柄失败：保留旧句柄，并记住新 hash 避免每次请求重试风暴
                    $configHash = $hash;
                    return $handle;
                }
                $lastFailureAt = time();
                $configHash = $hash;
                return false;
            }
            $handle = $newHandle;
            $configHash = $hash;
            return $handle;
        } catch (\Throwable $e) {
            // 有意 fail-open：初始化失败降级为原样透传；无句柄时节流 60s，有旧句柄时保留旧句柄
            \App\Syslog::error("SpinYarn", "初始化失败: " . $e->getMessage());
            if ($hasOldHandle) {
                $configHash = $hash;
                return $handle;
            }
            $lastFailureAt = time();
            $configHash = $hash;
            return false;
        }
    }

    /**
     * 纯函数：由 cache.redis 数组拼出 redis:// URL。
     *
     * host 为空返回 null；支持可选 username，拼法为
     * redis://[user[:pass]@]host:port/db，password 照常 rawurlencode；
     * port 非 1-65535 或 database < 0 返回 null。
     */
    public static function buildRedisUrl(array $redis): ?string
    {
        $host = trim((string) ($redis['host'] ?? ''));
        if ($host === '') {
            return null;
        }
        $port = isset($redis['port']) ? (int) $redis['port'] : 6379;
        if ($port < 1 || $port > 65535) {
            return null;
        }
        $database = isset($redis['database']) ? (int) $redis['database'] : 0;
        if ($database < 0) {
            return null;
        }
        $username = trim((string) ($redis['username'] ?? ''));
        $password = (string) ($redis['password'] ?? '');
        if ($username === '' && $password === '') {
            $auth = '';
        } elseif ($username !== '' && $password === '') {
            $auth = rawurlencode($username) . '@';
        } elseif ($username === '' && $password !== '') {
            $auth = ':' . rawurlencode($password) . '@';
        } else {
            $auth = rawurlencode($username) . ':' . rawurlencode($password) . '@';
        }
        return 'redis://' . $auth . $host . ':' . $port . '/' . $database;
    }

    /**
     * 按 spinyarn.redis_enabled（缺省跟随 cache.enabled）决定是否拼 Redis URL。
     *
     * 端口/库号非法时打 error 日志并返回 null（不再静默），调用方降级为按需解析。
     */
    private static function resolveRedisUrl(): ?string
    {
        $spinyarn = \App\Config::Get('spinyarn');
        $cache = \App\Config::Get('cache');
        if (!is_array($spinyarn)) {
            $spinyarn = [];
        }
        if (!is_array($cache)) {
            $cache = [];
        }
        $redisEnabled = $spinyarn['redis_enabled'] ?? ($cache['enabled'] ?? false);
        if ($redisEnabled !== true) {
            return null;
        }
        $redis = $cache['redis'] ?? [];
        if (!is_array($redis)) {
            return null;
        }
        $host = trim((string) ($redis['host'] ?? ''));
        if ($host === '') {
            return null;
        }
        $url = self::buildRedisUrl($redis);
        if ($url === null) {
            \App\Syslog::error("SpinYarn", "Redis 配置非法，已降级为按需解析: port=" . var_export($redis['port'] ?? 6379, true) . " database=" . var_export($redis['database'] ?? 0, true));
            return null;
        }
        return $url;
    }

    /**
     * Resolve the mappings directory: empty → null (extension default), absolute
     * path → as-is, relative path → resolved against the project root.
     *
     * @param string $dir
     * @return string|null
     */
    public static function resolveMappingsDir(string $dir): ?string
    {
        $dir = trim($dir);
        if ($dir === '') {
            return null;
        }
        if (str_starts_with($dir, '/')) {
            return $dir;
        }
        return CORE_PATH . '/' . $dir;
    }
}
