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

            try {
                $handle = spinyarn_init($mappingsDir, $cacheMax, $cacheHigh, $cacheLow);
            } catch (\Throwable $e) {
                \App\Syslog::error("SpinYarn", "初始化失败: " . $e->getMessage());
                $handle = false;
            }
        }

        return $handle;
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