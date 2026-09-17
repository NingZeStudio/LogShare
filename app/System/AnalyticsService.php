<?php

declare(strict_types=1);

namespace App\System;

use App\Config;
use Hyperf\DbConnection\Db;

/**
 * 日志生态画像与时序分析服务。
 *
 * 提供来源生态分布、Minecraft 版本与加载器矩阵、时序容量走势等统计分析。
 */
final class AnalyticsService
{
    /**
     * 获取指定天数内的客户端来源/生态分布统计。
     *
     * @param int $days 统计天数（1-90）
     * @return array{days: int, total: int, sources: array<int, array{source: string, count: int, percentage: float}>}
     */
    public static function getSourceStats(int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $since = time() - ($days * 86400);

        $storageConfig = Config::Get('storage');
        $storageId = $storageConfig['storageId'] ?? 's';

        if ($storageId === 's') {
            return self::getSourceStatsFromMariaDb($days, $since);
        }

        return self::getSourceStatsFromFilesystem($days, $since);
    }

    /**
     * 获取指定天数内的 Minecraft 版本与 Mod Loader 分布。
     *
     * @param int $days 统计天数（1-90）
     * @return array{days: int, versions: array<int, array{name: string, count: int, percentage: float}>, loaders: array<int, array{name: string, count: int, percentage: float}>}
     */
    public static function getVersionStats(int $days = 30): array
    {
        $days = max(1, min(90, $days));
        $since = time() - ($days * 86400);

        $storageConfig = Config::Get('storage');
        $storageId = $storageConfig['storageId'] ?? 's';

        if ($storageId === 's') {
            return self::getVersionStatsFromMariaDb($days, $since);
        }

        return self::getVersionStatsFromFilesystem($days, $since);
    }

    /**
     * 获取指定天数内的日志时序走势（按天统计条数与数据量）。
     *
     * @param int $days 统计天数（1-90）
     * @return array{days: int, total_logs: int, total_bytes: int, trends: array<int, array{date: string, count: int, bytes: int}>}
     */
    public static function getTrends(int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $since = time() - ($days * 86400);

        $storageConfig = Config::Get('storage');
        $storageId = $storageConfig['storageId'] ?? 's';

        if ($storageId === 's') {
            return self::getTrendsFromMariaDb($days, $since);
        }

        return self::getTrendsFromFilesystem($days, $since);
    }

    // ── MariaDB 实现 ──

    private static function getSourceStatsFromMariaDb(int $days, int $since): array
    {
        try {
            $rows = Db::table('logs')
                ->select([
                    Db::raw("COALESCE(NULLIF(source, ''), '未指定') as src"),
                    Db::raw('COUNT(*) as cnt'),
                ])
                ->where('created', '>=', $since)
                ->groupBy('src')
                ->orderByDesc('cnt')
                ->get();

            $total = 0;
            $items = [];
            foreach ($rows as $row) {
                $c = (int) ($row->cnt ?? 0);
                $total += $c;
                $items[] = [
                    'source' => (string) ($row->src ?? '未指定'),
                    'count' => $c,
                    'percentage' => 0.0,
                ];
            }

            if ($total > 0) {
                foreach ($items as &$item) {
                    $item['percentage'] = round(($item['count'] / $total) * 100, 1);
                }
                unset($item);
            }

            return [
                'days' => $days,
                'total' => $total,
                'sources' => $items,
            ];
        } catch (\Throwable $e) {
            \App\Syslog::error('Analytics', 'MariaDB getSourceStats failed: ' . $e->getMessage());
            return ['days' => $days, 'total' => 0, 'sources' => []];
        }
    }

    private static function getVersionStatsFromMariaDb(int $days, int $since): array
    {
        try {
            // 1. 版本分布
            $versionRows = Db::table('log_metadata as m')
                ->join('logs as l', 'm.log_id', '=', 'l.id')
                ->select(['m.value as ver', Db::raw('COUNT(*) as cnt')])
                ->where('l.created', '>=', $since)
                ->where('m.key', 'version')
                ->whereNotNull('m.value')
                ->where('m.value', '!=', '')
                ->groupBy('m.value')
                ->orderByDesc('cnt')
                ->limit(20)
                ->get();

            $versionTotal = 0;
            $versions = [];
            foreach ($versionRows as $r) {
                $c = (int) $r->cnt;
                $versionTotal += $c;
                $versions[] = ['name' => (string) $r->ver, 'count' => $c, 'percentage' => 0.0];
            }
            if ($versionTotal > 0) {
                foreach ($versions as &$v) {
                    $v['percentage'] = round(($v['count'] / $versionTotal) * 100, 1);
                }
                unset($v);
            }

            // 2. 加载器分布（type / loader / modloader）
            $loaderRows = Db::table('log_metadata as m')
                ->join('logs as l', 'm.log_id', '=', 'l.id')
                ->select(['m.value as ldr', Db::raw('COUNT(*) as cnt')])
                ->where('l.created', '>=', $since)
                ->whereIn('m.key', ['type', 'loader', 'modloader'])
                ->whereNotNull('m.value')
                ->where('m.value', '!=', '')
                ->groupBy('m.value')
                ->orderByDesc('cnt')
                ->limit(20)
                ->get();

            $loaderTotal = 0;
            $loaders = [];
            foreach ($loaderRows as $r) {
                $c = (int) $r->cnt;
                $loaderTotal += $c;
                $loaders[] = ['name' => (string) $r->ldr, 'count' => $c, 'percentage' => 0.0];
            }
            if ($loaderTotal > 0) {
                foreach ($loaders as &$l) {
                    $l['percentage'] = round(($l['count'] / $loaderTotal) * 100, 1);
                }
                unset($l);
            }

            return [
                'days' => $days,
                'versions' => $versions,
                'loaders' => $loaders,
            ];
        } catch (\Throwable $e) {
            \App\Syslog::error('Analytics', 'MariaDB getVersionStats failed: ' . $e->getMessage());
            return ['days' => $days, 'versions' => [], 'loaders' => []];
        }
    }

    private static function getTrendsFromMariaDb(int $days, int $since): array
    {
        try {
            $rows = Db::table('logs')
                ->select([
                    Db::raw("DATE(FROM_UNIXTIME(created)) as dt"),
                    Db::raw('COUNT(*) as cnt'),
                    Db::raw('SUM(LENGTH(data)) as total_bytes'),
                ])
                ->where('created', '>=', $since)
                ->groupBy('dt')
                ->orderBy('dt', 'asc')
                ->get();

            $totalLogs = 0;
            $totalBytes = 0;
            $trends = [];

            // 预填充完整日期
            $dateMap = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $d = date('Y-m-d', time() - ($i * 86400));
                $dateMap[$d] = ['date' => $d, 'count' => 0, 'bytes' => 0];
            }

            foreach ($rows as $r) {
                $d = (string) $r->dt;
                $c = (int) $r->cnt;
                $b = (int) ($r->total_bytes ?? 0);
                $totalLogs += $c;
                $totalBytes += $b;
                if (isset($dateMap[$d])) {
                    $dateMap[$d]['count'] = $c;
                    $dateMap[$d]['bytes'] = $b;
                } else {
                    $dateMap[$d] = ['date' => $d, 'count' => $c, 'bytes' => $b];
                }
            }

            $trends = array_values($dateMap);

            return [
                'days' => $days,
                'total_logs' => $totalLogs,
                'total_bytes' => $totalBytes,
                'trends' => $trends,
            ];
        } catch (\Throwable $e) {
            \App\Syslog::error('Analytics', 'MariaDB getTrends failed: ' . $e->getMessage());
            return ['days' => $days, 'total_logs' => 0, 'total_bytes' => 0, 'trends' => []];
        }
    }

    // ── Filesystem 实现 ──

    private static function getSourceStatsFromFilesystem(int $days, int $since): array
    {
        $config = Config::Get('filesystem');
        $basePath = CORE_PATH . ($config['path'] ?? '/storage/logs/');
        if (!is_dir($basePath)) {
            return ['days' => $days, 'total' => 0, 'sources' => []];
        }

        $counts = [];
        $total = 0;
        $files = glob($basePath . '*.meta.json') ?: [];
        foreach ($files as $metaFile) {
            $content = @file_get_contents($metaFile);
            if (!$content) {
                continue;
            }
            $meta = json_decode($content, true);
            if (!is_array($meta)) {
                continue;
            }
            $created = (int) ($meta['created'] ?? 0);
            if ($created < $since) {
                continue;
            }
            $source = (string) ($meta['source'] ?? '未指定');
            if (trim($source) === '') {
                $source = '未指定';
            }
            $counts[$source] = ($counts[$source] ?? 0) + 1;
            $total++;
        }

        arsort($counts);
        $items = [];
        foreach ($counts as $source => $c) {
            $items[] = [
                'source' => $source,
                'count' => $c,
                'percentage' => $total > 0 ? round(($c / $total) * 100, 1) : 0.0,
            ];
        }

        return [
            'days' => $days,
            'total' => $total,
            'sources' => $items,
        ];
    }

    private static function getVersionStatsFromFilesystem(int $days, int $since): array
    {
        $config = Config::Get('filesystem');
        $basePath = CORE_PATH . ($config['path'] ?? '/storage/logs/');
        if (!is_dir($basePath)) {
            return ['days' => $days, 'versions' => [], 'loaders' => []];
        }

        $versionCounts = [];
        $loaderCounts = [];
        $files = glob($basePath . '*.meta.json') ?: [];
        $sampled = 0;
        $maxSample = 300;

        foreach ($files as $metaFile) {
            if ($sampled >= $maxSample) {
                break;
            }
            $content = @file_get_contents($metaFile);
            if (!$content) {
                continue;
            }
            $meta = json_decode($content, true);
            if (!is_array($meta) || (int) ($meta['created'] ?? 0) < $since) {
                continue;
            }

            // 读取对应主日志
            $rawId = substr(basename($metaFile), 0, -10);
            $logFile = $basePath . $rawId;
            if (!is_file($logFile)) {
                continue;
            }
            $logData = @json_decode((string) file_get_contents($logFile), true);
            if (!is_array($logData)) {
                continue;
            }

            $sampled++;
            $entries = $logData['metadata'] ?? [];
            foreach ($entries as $entry) {
                $k = (string) ($entry['key'] ?? '');
                $v = (string) ($entry['value'] ?? '');
                if ($k === 'version' && $v !== '') {
                    $versionCounts[$v] = ($versionCounts[$v] ?? 0) + 1;
                } elseif (in_array($k, ['type', 'loader', 'modloader'], true) && $v !== '') {
                    $loaderCounts[$v] = ($loaderCounts[$v] ?? 0) + 1;
                }
            }
        }

        arsort($versionCounts);
        arsort($loaderCounts);

        $vTotal = array_sum($versionCounts);
        $versions = [];
        foreach (array_slice($versionCounts, 0, 20, true) as $k => $c) {
            $versions[] = ['name' => (string) $k, 'count' => $c, 'percentage' => $vTotal > 0 ? round(($c / $vTotal) * 100, 1) : 0.0];
        }

        $lTotal = array_sum($loaderCounts);
        $loaders = [];
        foreach (array_slice($loaderCounts, 0, 20, true) as $k => $c) {
            $loaders[] = ['name' => (string) $k, 'count' => $c, 'percentage' => $lTotal > 0 ? round(($c / $lTotal) * 100, 1) : 0.0];
        }

        return ['days' => $days, 'versions' => $versions, 'loaders' => $loaders];
    }

    private static function getTrendsFromFilesystem(int $days, int $since): array
    {
        $config = Config::Get('filesystem');
        $basePath = CORE_PATH . ($config['path'] ?? '/storage/logs/');

        $dateMap = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', time() - ($i * 86400));
            $dateMap[$d] = ['date' => $d, 'count' => 0, 'bytes' => 0];
        }

        if (!is_dir($basePath)) {
            return ['days' => $days, 'total_logs' => 0, 'total_bytes' => 0, 'trends' => array_values($dateMap)];
        }

        $files = glob($basePath . '*.meta.json') ?: [];
        $totalLogs = 0;
        $totalBytes = 0;

        foreach ($files as $metaFile) {
            $content = @file_get_contents($metaFile);
            if (!$content) {
                continue;
            }
            $meta = json_decode($content, true);
            if (!is_array($meta)) {
                continue;
            }
            $created = (int) ($meta['created'] ?? 0);
            if ($created < $since) {
                continue;
            }

            $d = date('Y-m-d', $created);
            $rawId = substr(basename($metaFile), 0, -10);
            $logFile = $basePath . $rawId;
            $size = is_file($logFile) ? (filesize($logFile) ?: 0) : 0;

            $totalLogs++;
            $totalBytes += $size;

            if (isset($dateMap[$d])) {
                $dateMap[$d]['count']++;
                $dateMap[$d]['bytes'] += $size;
            }
        }

        return [
            'days' => $days,
            'total_logs' => $totalLogs,
            'total_bytes' => $totalBytes,
            'trends' => array_values($dateMap),
        ];
    }
}
