<?php

declare(strict_types=1);

namespace App\System;

use App\Client\SpinYarnClient;
use App\Config;

/**
 * SpinYarn 映射状态检查与反混淆探针测试服务。
 */
final class SpinYarnManager
{
    /**
     * 获取 SpinYarn 扩展状态与本地 mappings 文件库清单。
     *
     * @return array<string, mixed>
     */
    public static function getStatus(): array
    {
        $extensionLoaded = extension_loaded('spinyarn');
        $version = $extensionLoaded ? (phpversion('spinyarn') ?: 'unknown') : null;
        $functionAvailable = function_exists('spinyarn_deobfuscate');

        $cfg = Config::Get('spinyarn');
        $mappingsDirRel = (string) ($cfg['mappings_dir'] ?? 'mappings');
        // 目录解析复用客户端逻辑；null 表示使用扩展默认目录，回显实际生效值
        $mappingsDir = SpinYarnClient::resolveMappingsDir($mappingsDirRel);
        $dirExists = $mappingsDir !== null && is_dir($mappingsDir);
        $yarnMappings = [];
        $vanillaMappings = [];

        if ($mappingsDir !== null && $dirExists) {
            // 1. 扫描 Yarn 映射 (*.tiny.gz 或 *.tiny)，版本段允许 + 与 _
            $files = @scandir($mappingsDir) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || is_dir($mappingsDir . '/' . $file)) {
                    continue;
                }
                if (preg_match('/^([0-9A-Za-z.\-_+]+)\.tiny(?:\.gz)?$/', $file, $m)) {
                    $path = $mappingsDir . '/' . $file;
                    $yarnMappings[] = [
                        'version' => $m[1],
                        'filename' => $file,
                        'size' => (int) @filesize($path),
                        'mtime' => (int) @filemtime($path),
                    ];
                }
            }

            // 2. 扫描 Vanilla 映射 (vanilla/*.txt)，版本段允许 + 与 _
            $vanillaDir = $mappingsDir . '/vanilla';
            if (is_dir($vanillaDir)) {
                $vFiles = @scandir($vanillaDir) ?: [];
                foreach ($vFiles as $file) {
                    if ($file === '.' || $file === '..' || is_dir($vanillaDir . '/' . $file)) {
                        continue;
                    }
                    if (preg_match('/^([0-9A-Za-z.\-_+]+)\.txt$/', $file, $m)) {
                        $path = $vanillaDir . '/' . $file;
                        $vanillaMappings[] = [
                            'version' => $m[1],
                            'filename' => $file,
                            'size' => (int) @filesize($path),
                            'mtime' => (int) @filemtime($path),
                        ];
                    }
                }
            }
        }

        // 版本自然排序
        usort($yarnMappings, fn($a, $b) => version_compare($b['version'], $a['version']));
        usort($vanillaMappings, fn($a, $b) => version_compare($b['version'], $a['version']));

        $cacheMax = (int) ($cfg['cache_max_entries'] ?? 10);
        $cacheHigh = (int) ($cfg['cache_high_watermark'] ?? 10);
        $cacheLow = (int) ($cfg['cache_low_watermark'] ?? 7);

        // Redis 是否配出 URL（脱敏，只给 true/false，不回显 URL 本体）
        $redisConfigured = self::isRedisConfigured();
        $redisSupported = SpinYarnClient::supportsRedisArg();

        // 有扩展时对最新 1-2 个版本做 has_mapping 抽查（function_exists 守卫）
        $spotCheck = [];
        if ($extensionLoaded && $functionAvailable && function_exists('spinyarn_has_mapping')) {
            $candidates = [];
            if (!empty($yarnMappings)) {
                $candidates[] = ['version' => $yarnMappings[0]['version'], 'mappingType' => 'yarn'];
            }
            if (!empty($vanillaMappings)) {
                $candidates[] = ['version' => $vanillaMappings[0]['version'], 'mappingType' => 'vanilla'];
            }
            foreach (array_slice($candidates, 0, 2) as $c) {
                $spotCheck[] = [
                    'version' => $c['version'],
                    'mappingType' => $c['mappingType'],
                    'hasMapping' => SpinYarnClient::hasMapping($c['version'], $c['mappingType']),
                ];
            }
        }

        return [
            'extensionLoaded' => $extensionLoaded,
            'version' => $version,
            'extensionVersion' => $version,
            'functionAvailable' => $functionAvailable,
            'mappingsDir' => $mappingsDir,
            'dirExists' => $dirExists,
            'totalYarnCount' => count($yarnMappings),
            'totalVanillaCount' => count($vanillaMappings),
            'yarnMappings' => $yarnMappings,
            'vanillaMappings' => $vanillaMappings,
            'cacheMaxEntries' => $cacheMax,
            'cacheHighWatermark' => $cacheHigh,
            'cacheLowWatermark' => $cacheLow,
            'redisConfigured' => $redisConfigured,
            'redisSupported' => $redisSupported,
            'spotCheck' => $spotCheck,
        ];
    }

    /**
     * 是否拼出了 Redis URL（仅返回 bool，不回显 URL 本体）。
     */
    private static function isRedisConfigured(): bool
    {
        try {
            $spinyarn = Config::Get('spinyarn');
            $cache = Config::Get('cache');
            $redisEnabled = $spinyarn['redis_enabled'] ?? ($cache['enabled'] ?? false);
            if ($redisEnabled !== true) {
                return false;
            }
            $redis = $cache['redis'] ?? [];
            if (!is_array($redis)) {
                return false;
            }
            return SpinYarnClient::buildRedisUrl($redis) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 在线反混淆测试探针。
     *
     * @param string $content 混淆的堆栈或文本
     * @param string $version 目标 Minecraft 版本 (如 "1.20.1")
     * @param string $mappingType 映射类型 ("yarn" 或 "vanilla")
     * @return array<string, mixed>
     */
    public static function testDeobfuscate(string $content, string $version, string $mappingType = 'yarn'): array
    {
        $startMs = (int) round(microtime(true) * 1000);
        $available = SpinYarnClient::isAvailable();

        if (!$available) {
            return [
                'success' => false,
                'available' => false,
                'message' => 'SpinYarn PHP 扩展未加载或未在当前环境就绪，反混淆功能降级为透传。',
                'original' => $content,
                'deobfuscated' => null,
                'durationMs' => 0,
            ];
        }

        $stats = null;
        $deobfuscated = SpinYarnClient::deobfuscate($content, $version, $mappingType, $stats);
        $durationMs = max(1, (int) round(microtime(true) * 1000) - $startMs);
        $changed = $deobfuscated !== null && $deobfuscated !== $content;

        return [
            'success' => true,
            'available' => true,
            'changed' => $changed,
            'version' => $version,
            'mappingType' => $mappingType,
            'durationMs' => $durationMs,
            'stats' => $stats,
            'original' => $content,
            'deobfuscated' => $deobfuscated ?? $content,
            'message' => $changed
                ? "成功匹配 {$version} ({$mappingType}) 映射并完成符号反混淆"
                : "已执行处理，但未匹配到待还原的混淆符号或版本无对应映射",
        ];
    }
}
