<?php

declare(strict_types=1);

namespace App\System;

use App\Client\RedisClient;
use App\Config;
use Hyperf\DbConnection\Db;

/**
 * 存储物理健康度诊断与运维服务。
 *
 * 提供 MariaDB / 本地文件系统 / Redis 内存物理占用统计、
 * 过期日志手动回收执行及缓存按需清空能力。
 */
final class StorageHealthService
{
    /**
     * 获取全系统的存储物理健康度与资源占用。
     *
     * @return array<string, mixed>
     */
    public static function getHealth(): array
    {
        $storageConfig = Config::Get('storage');
        $storageId = (string) ($storageConfig['storageId'] ?? 's');
        $storageTime = (int) ($storageConfig['storageTime'] ?? (7 * 24 * 60 * 60));

        $result = [
            'storageBackend' => $storageId,
            'storageTime' => $storageTime,
            'database' => null,
            'filesystem' => null,
            'redis' => null,
        ];

        // 1. MariaDB 指标
        if ($storageId === 's') {
            $result['database'] = self::getMariaDbHealth();
        }

        // 2. 文件系统指标
        $result['filesystem'] = self::getFilesystemHealth();

        // 3. Redis 缓存指标
        $result['redis'] = self::getRedisHealth();

        return $result;
    }

    /**
     * 手动触发过期日志清理。
     *
     * @return array{deletedCount: int, durationMs: int, storageBackend: string}
     */
    public static function cleanupExpired(): array
    {
        $storageConfig = Config::Get('storage');
        $storageId = (string) ($storageConfig['storageId'] ?? 's');

        $start = microtime(true);
        $deleted = 0;

        try {
            if ($storageId === 'f') {
                $deleted = \App\Storage\FilesystemStorage::CleanupExpired();
            } else {
                $deleted = \App\Storage\MariaDbStorage::CleanupExpired();
            }
        } catch (\Throwable $e) {
            \App\Syslog::error('StorageHealth', 'Manual cleanupExpired error: ' . $e->getMessage());
            throw $e;
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        return [
            'deletedCount' => $deleted,
            'durationMs' => $durationMs,
            'storageBackend' => $storageId,
        ];
    }

    /**
     * 手动清空或按前缀清理 Redis 缓存。
     *
     * @param ?string $prefix 缓存前缀，如 'log:*', 'ai:*', 或 'all'
     * @return array{cleared: bool, deletedKeys: int, prefix: string}
     */
    public static function flushCache(?string $prefix = null): array
    {
        $redis = RedisClient::getRedis();
        if ($redis === null) {
            return [
                'cleared' => false,
                'deletedKeys' => 0,
                'prefix' => (string) $prefix,
            ];
        }

        $prefix = trim((string) ($prefix ?? 'log:*'));
        $deletedCount = 0;

        try {
            if ($prefix === 'all' || $prefix === '*') {
                $redis->flushDB();
                $deletedCount = 1;
            } else {
                $keys = $redis->keys($prefix);
                if (is_array($keys) && !empty($keys)) {
                    $deletedCount = (int) $redis->del(...$keys);
                }
            }

            return [
                'cleared' => true,
                'deletedKeys' => $deletedCount,
                'prefix' => $prefix,
            ];
        } catch (\Throwable $e) {
            \App\Syslog::error('StorageHealth', 'flushCache failed: ' . $e->getMessage());
            return [
                'cleared' => false,
                'deletedKeys' => 0,
                'prefix' => $prefix,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function getMariaDbHealth(): array
    {
        try {
            $dbName = (string) Db::selectOne('SELECT DATABASE() as db')->db;
            $tables = Db::select(
                "SELECT TABLE_NAME as name, 
                        TABLE_ROWS as row_count, 
                        DATA_LENGTH as data_bytes, 
                        INDEX_LENGTH as index_bytes,
                        DATA_FREE as data_free
                 FROM information_schema.TABLES 
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('logs', 'log_files', 'log_metadata')",
                [$dbName]
            );

            $totalDataBytes = 0;
            $totalIndexBytes = 0;
            $totalRows = 0;
            $tableDetails = [];

            foreach ($tables as $t) {
                $d = (int) $t->data_bytes;
                $i = (int) $t->index_bytes;
                $r = (int) $t->row_count;
                $totalDataBytes += $d;
                $totalIndexBytes += $i;
                $totalRows += $r;

                $tableDetails[] = [
                    'table' => (string) $t->name,
                    'rows' => $r,
                    'dataBytes' => $d,
                    'indexBytes' => $i,
                    'totalBytes' => $d + $i,
                ];
            }

            return [
                'database' => $dbName,
                'totalRows' => $totalRows,
                'totalDataBytes' => $totalDataBytes,
                'totalIndexBytes' => $totalIndexBytes,
                'totalBytes' => $totalDataBytes + $totalIndexBytes,
                'tables' => $tableDetails,
            ];
        } catch (\Throwable $e) {
            return [
                'error' => 'MariaDB metrics unavailable: ' . $e->getMessage(),
            ];
        }
    }

    private static function getFilesystemHealth(): array
    {
        $config = Config::Get('filesystem');
        $path = CORE_PATH . ($config['path'] ?? '/storage/logs/');

        $freeBytes = @disk_free_space($path);
        $totalBytes = @disk_total_space($path);

        $logFilesCount = 0;
        $dirBytes = 0;

        if (is_dir($path)) {
            $files = glob($path . '*') ?: [];
            foreach ($files as $file) {
                if (is_file($file)) {
                    $logFilesCount++;
                    $dirBytes += filesize($file) ?: 0;
                }
            }
        }

        return [
            'path' => $config['path'] ?? '/storage/logs/',
            'fileCount' => $logFilesCount,
            'usedBytes' => $dirBytes,
            'diskFreeBytes' => $freeBytes !== false ? (int) $freeBytes : null,
            'diskTotalBytes' => $totalBytes !== false ? (int) $totalBytes : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function getRedisHealth(): array
    {
        $redis = RedisClient::getRedis();
        if ($redis === null) {
            return [
                'enabled' => false,
                'connected' => false,
            ];
        }

        try {
            $info = $redis->info('memory');
            $dbSize = (int) $redis->dbSize();

            return [
                'enabled' => true,
                'connected' => true,
                'dbSize' => $dbSize,
                'usedMemory' => (int) ($info['used_memory'] ?? 0),
                'usedMemoryHuman' => (string) ($info['used_memory_human'] ?? '0B'),
                'usedMemoryPeakHuman' => (string) ($info['used_memory_peak_human'] ?? '0B'),
                'memFragmentationRatio' => (float) ($info['mem_fragmentation_ratio'] ?? 1.0),
            ];
        } catch (\Throwable $e) {
            return [
                'enabled' => true,
                'connected' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
