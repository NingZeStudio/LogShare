<?php

declare(strict_types=1);

namespace App\System;

use App\Client\RedisClient;

/**
 * 系统诊断日志查询与管理服务。
 *
 * 优先从 Redis 环形列表（syslog:recent）检索结构化事件，
 * 在 Redis 不可用或未命中时自动平滑回退解析持久化文件 runtime/logs/system.log。
 */
final class SystemLogManager
{
    private const LOG_FILE = '/runtime/logs/system.log';
    private const REDIS_KEY = 'syslog:recent';

    /**
     * 查询系统日志列表。
     *
     * @param int $limit 返回的最大条数（1-500）
     * @param ?string $level 级别过滤（info / warning / error）
     * @param ?string $keyword 关键词模糊匹配
     * @param ?int $since Unix 时间戳下界
     * @return array<int, array{id: string, datetime: string, timestamp: int, level: string, component: string, message: string}>
     */
    public static function getLogs(int $limit = 100, ?string $level = null, ?string $keyword = null, ?int $since = null): array
    {
        $limit = max(1, min(500, $limit));
        $level = $level !== null && trim($level) !== '' ? strtolower(trim($level)) : null;
        if ($level === 'all') {
            $level = null;
        }
        $keyword = $keyword !== null && trim($keyword) !== '' ? trim($keyword) : null;

        // 1. 尝试从 Redis 环形列表读取
        $logs = self::readFromRedis();

        // 2. Redis 无数据时，回退从本地文件读取
        if ($logs === []) {
            $logs = self::readFromFile();
        }

        // 3. 过滤并截取结果
        $filtered = [];
        foreach ($logs as $entry) {
            if ($level !== null && strtolower($entry['level']) !== $level) {
                continue;
            }

            if ($since !== null && $entry['timestamp'] < $since) {
                continue;
            }

            if ($keyword !== null) {
                $comp = $entry['component'];
                $msg = $entry['message'];
                if (stripos($comp, $keyword) === false && stripos($msg, $keyword) === false) {
                    continue;
                }
            }

            $filtered[] = $entry;
            if (count($filtered) >= $limit) {
                break;
            }
        }

        return $filtered;
    }

    /**
     * 清空系统诊断日志（支持运维/测试重置）。
     */
    public static function clearLogs(): bool
    {
        $cleared = false;

        // 1. 清空 Redis
        try {
            $redis = RedisClient::getRedis();
            if ($redis !== null) {
                $redis->del(self::REDIS_KEY);
                $cleared = true;
            }
        } catch (\Throwable) {
            // ignore
        }

        // 2. 清空本地文件
        try {
            $filePath = self::resolveLogFilePath();
            if (is_file($filePath)) {
                @file_put_contents($filePath, '');
                $cleared = true;
            }
        } catch (\Throwable) {
            // ignore
        }

        return $cleared;
    }

    /**
     * @return array<int, array{id: string, datetime: string, timestamp: int, level: string, component: string, message: string}>
     */
    private static function readFromRedis(): array
    {
        try {
            $redis = RedisClient::getRedis();
            if ($redis === null) {
                return [];
            }

            $rawList = $redis->lRange(self::REDIS_KEY, 0, 999);
            if (!is_array($rawList) || $rawList === []) {
                return [];
            }

            $result = [];
            foreach ($rawList as $raw) {
                if (!is_string($raw) || $raw === '') {
                    continue;
                }
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['message'])) {
                    $result[] = [
                        'id' => (string) ($decoded['id'] ?? bin2hex(random_bytes(6))),
                        'datetime' => (string) ($decoded['datetime'] ?? date('Y-m-d H:i:s')),
                        'timestamp' => (int) ($decoded['timestamp'] ?? time()),
                        'level' => strtolower((string) ($decoded['level'] ?? 'info')),
                        'component' => (string) ($decoded['component'] ?? 'App'),
                        'message' => (string) $decoded['message'],
                    ];
                }
            }
            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array{id: string, datetime: string, timestamp: int, level: string, component: string, message: string}>
     */
    private static function readFromFile(): array
    {
        $filePath = self::resolveLogFilePath();
        if (!is_file($filePath) || !is_readable($filePath)) {
            return [];
        }

        $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || $lines === []) {
            return [];
        }

        // 逆序，最新日志在最前
        $reversed = array_reverse($lines);
        $result = [];
        $pattern = '/^\[(.*?)\] \[(INFO|WARNING|ERROR)\] \[(.*?)\] (.*)$/s';

        foreach ($reversed as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match($pattern, $line, $matches)) {
                $datetime = $matches[1];
                $ts = strtotime($datetime);
                $result[] = [
                    'id' => substr(md5($line . count($result)), 0, 12),
                    'datetime' => $datetime,
                    'timestamp' => $ts !== false ? $ts : time(),
                    'level' => strtolower($matches[2]),
                    'component' => $matches[3],
                    'message' => $matches[4],
                ];
            } else {
                // 非标准格式行，作为纯文本兜底
                $result[] = [
                    'id' => substr(md5($line . count($result)), 0, 12),
                    'datetime' => date('Y-m-d H:i:s'),
                    'timestamp' => time(),
                    'level' => 'info',
                    'component' => 'Syslog',
                    'message' => $line,
                ];
            }

            if (count($result) >= 500) {
                break;
            }
        }

        return $result;
    }

    private static function resolveLogFilePath(): string
    {
        $baseDir = defined('CORE_PATH') ? CORE_PATH : (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2));
        return $baseDir . self::LOG_FILE;
    }
}
