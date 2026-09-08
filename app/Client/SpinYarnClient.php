<?php

namespace App\Client;

/**
 * SpinYarn PHP extension wrapper: in-process Minecraft log deobfuscation.
 *
 * The extension (spinyarn.so, backed by libspinyarn_capi) exposes
 * spinyarn_init()/spinyarn_deobfuscate() etc. If the extension is not loaded
 * (e.g. local dev without the extension), every call degrades to null so the
 * log is passed through unchanged.
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
     * Deobfuscate a log content in-process.
     *
     * @param string $content Log text (may contain obfuscated stack traces)
     * @param string $version Minecraft version, e.g. "1.20.1"
     * @param string $mappingType "yarn" (Fabric) or "vanilla" (Mojang official)
     * @return string|null Deobfuscated text, or null to pass through unchanged
     */
    public static function deobfuscate(string $content, string $version, string $mappingType): ?string
    {
        if (!self::isAvailable()) {
            return null;
        }

        try {
            $handle = self::getHandle();
            if ($handle === false) {
                return null;
            }

            $mapping = $mappingType === 'vanilla' ? SPINYARN_VANILLA : SPINYARN_YARN;
            $result = spinyarn_deobfuscate($handle, $content, $version, $mapping);

            if ($result === false || !is_array($result)) {
                return null;
            }

            $deobfuscated = $result['deobfuscated'] ?? null;
            return is_string($deobfuscated) && $deobfuscated !== '' ? $deobfuscated : null;
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
    private static function supportsRedisArg(): bool
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
     * Lazily create (and reuse) the extension handle for this request.
     *
     * 协程安全性说明：Swoole 协程为单线程模型，`spinyarn_deobfuscate` 是同步
     * CPU 操作（期间不发生 IO yield），因此进程级 `static $handle` 复用不会
     * 产生并发串扰——同一时刻只会有一个协程真正执行到扩展调用。
     *
     * @return mixed resource handle, or false on failure
     */
    private static function getHandle()
    {
        static $handle = null;

        if ($handle === null) {
            $config = \App\Config::Get('spinyarn');
            $mappingsDir = self::resolveMappingsDir($config['mappings_dir'] ?? '');
            $cacheMax = (int) ($config['cache_max_entries'] ?? 44);
            $cacheHigh = (int) ($config['cache_high_watermark'] ?? 40);
            $cacheLow = (int) ($config['cache_low_watermark'] ?? 30);
            $configuredRedisUrl = self::resolveRedisUrl();
            $redisUrl = self::supportsRedisArg() ? $configuredRedisUrl : null;

            if ($configuredRedisUrl !== null && $redisUrl === null) {
                // Redis 已配置但扩展签名不支持第 5 参：提示一次，按本地 LRU 模式继续
                static $notified = false;
                if (!$notified) {
                    $notified = true;
                    \App\Syslog::error("SpinYarn", "loaded spinyarn extension has no redis support (spinyarn_init takes 4 params); continuing with local LRU cache");
                }
            }

            try {
                $handle = $redisUrl !== null
                    ? spinyarn_init($mappingsDir, $cacheMax, $cacheHigh, $cacheLow, $redisUrl)
                    : spinyarn_init($mappingsDir, $cacheMax, $cacheHigh, $cacheLow);
                // redis 模式初始化被扩展拒绝（返回 false）：回退本地缓存模式，
                // 缓存层缺失不应拖垮反混淆本身
                if ($handle === false && $redisUrl !== null) {
                    \App\Syslog::error("SpinYarn", "redis-backed init failed, retrying with local cache");
                    $handle = spinyarn_init($mappingsDir, $cacheMax, $cacheHigh, $cacheLow);
                }
            } catch (\Throwable $e) {
                // 有意 fail-open：初始化失败（映射目录不可读等）后整个进程
                // 生命周期内反混淆降级为原样透传，不再重试——日志可读性降级优于
                // 上传链路不可用。失败原因见 error log。
                \App\Syslog::error("SpinYarn", "初始化失败: " . $e->getMessage());
                $handle = false;
            }
        }

        return $handle;
    }

    private static function resolveRedisUrl(): ?string
    {
        $cache = \App\Config::Get('cache');
        if (($cache['enabled'] ?? false) !== true) {
            return null;
        }
        $redis = $cache['redis'] ?? [];
        $host = trim((string) ($redis['host'] ?? ''));
        if ($host === '') {
            return null;
        }
        $port = (int) ($redis['port'] ?? 6379);
        $database = (int) ($redis['database'] ?? 0);
        $password = (string) ($redis['password'] ?? '');
        $auth = $password === '' ? '' : ':' . rawurlencode($password) . '@';
        return 'redis://' . $auth . $host . ':' . $port . '/' . $database;
    }

    /**
     * Resolve the mappings directory: empty → null (extension default), absolute
     * path → as-is, relative path → resolved against the project root.
     *
     * @param string $dir
     * @return string|null
     */
    private static function resolveMappingsDir(string $dir): ?string
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