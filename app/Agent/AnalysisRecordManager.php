<?php

declare(strict_types=1);

namespace App\Agent;

use App\Cache\RedisCache;
use App\Client\RedisClient;

/**
 * LogAgent 运行记录、链路追踪与质量评分持久化管理。
 *
 * 记录每次 LogAgent 运行产生的 AnalysisResult、完整 Trace、
 * 四维评分与验证结果。采用 Redis Sorted Sets（时间/分数/耗时索引）+
 * 字符串 JSON 详情，配合本地日志文件双层持久化，支持 7 天自动生命周期。
 */
final class AnalysisRecordManager
{
    private const RECORD_PREFIX = 'ai:analyses:record:';
    private const TIME_INDEX = 'ai:analyses:zset';
    private const SCORE_INDEX = 'ai:analyses:score:zset';
    private const SLOW_INDEX = 'ai:analyses:slow:zset';
    private const LOCAL_LOG_FILE = '/runtime/logs/ai_analyses.log';

    public const TTL_SECONDS = 604800; // 7 天
    private const MAX_INDEX_ENTRIES = 5000;

    /**
     * 持久化记录一次 LogAgent 分析结果。
     */
    public static function record(AnalysisResult $result, AgentContext $ctx): void
    {
        $id = $ctx->cacheKey ?? ($result->cacheKey ?? ('ai:trace:' . bin2hex(random_bytes(8))));
        $now = time();

        $overallScore = 0.0;
        if (is_array($result->score) && isset($result->score['overall']) && is_numeric($result->score['overall'])) {
            $overallScore = round((float) $result->score['overall'], 2);
        }

        $durationMs = 0.0;
        if (isset($result->metrics['durationMs']) && is_numeric($result->metrics['durationMs'])) {
            $durationMs = (float) $result->metrics['durationMs'];
        }

        $rootCause = '';
        if (is_array($result->validation) && isset($result->validation['structuredOutput']['rootCause'])) {
            $rootCause = trim((string) $result->validation['structuredOutput']['rootCause']);
        }

        $record = [
            'id' => $id,
            'cacheKey' => $ctx->cacheKey ?? $result->cacheKey,
            'logId' => $ctx->logId,
            'mode' => $ctx->mode,
            'promptVersion' => $ctx->promptVersion,
            'model' => $result->trace['model'] ?? (\App\Config::Get('ai')['model'] ?? null),
            'startedAt' => $result->trace['startedAt'] ?? date('c'),
            'timestamp' => $now,
            'success' => $result->success,
            'rounds' => $result->rounds,
            'toolCallsCount' => count($result->toolCallChain),
            'durationMs' => $durationMs,
            'score' => $result->score ?? [
                'overall' => 0.0,
                'toolEfficiency' => 0.0,
                'evidenceSufficiency' => 0.0,
                'conclusionClarity' => 0.0,
            ],
            'validation' => $result->validation ?? [],
            'rootCause' => $rootCause,
            'toolCallChain' => $result->toolCallChain,
            'trace' => $result->trace,
            'fullAnswerSnippet' => mb_substr($result->fullAnswer, 0, 300),
        ];

        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return;
        }

        // 1. Redis 持久化
        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                $recordKey = self::RECORD_PREFIX . $id;
                $redis->setex($recordKey, self::TTL_SECONDS, $json);

                // 添加多维索引
                $redis->zAdd(self::TIME_INDEX, $now, $id);
                $redis->zAdd(self::SCORE_INDEX, $overallScore, $id);
                $redis->zAdd(self::SLOW_INDEX, $durationMs, $id);

                // 适度修剪索引，避免极端高并发下无上限膨胀
                $redis->zRemRangeByRank(self::TIME_INDEX, 0, - (self::MAX_INDEX_ENTRIES + 1));
                $redis->zRemRangeByRank(self::SLOW_INDEX, 0, - (self::MAX_INDEX_ENTRIES + 1));
            } catch (\Throwable $e) {
                \App\Syslog::error('AnalysisRecord', 'Redis record failed: ' . $e->getMessage());
            }
        }

        // 2. 本地文件追加归档（容错兜底）
        try {
            $filePath = CORE_PATH . self::LOCAL_LOG_FILE;
            $dir = dirname($filePath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($filePath, $json . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * 分页查询分析记录列表（不包含冗长 trace 原始树，提升列表吞吐）。
     *
     * @param array<string, mixed> $filters
     * @return array{total: int, page: int, pageSize: int, items: array<int, mixed>}
     */
    public static function list(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));

        $redis = RedisClient::getRedis();
        if ($redis === null) {
            return self::listFromFile($filters, $page, $pageSize);
        }

        try {
            $mode = isset($filters['mode']) && is_string($filters['mode']) && $filters['mode'] !== '' ? $filters['mode'] : null;
            $promptVersion = isset($filters['promptVersion']) && is_string($filters['promptVersion']) && $filters['promptVersion'] !== '' ? $filters['promptVersion'] : null;
            $keyword = isset($filters['keyword']) && is_string($filters['keyword']) && trim($filters['keyword']) !== '' ? trim($filters['keyword']) : null;
            $minScore = isset($filters['minScore']) && is_numeric($filters['minScore']) ? (float) $filters['minScore'] : null;
            $maxScore = isset($filters['maxScore']) && is_numeric($filters['maxScore']) ? (float) $filters['maxScore'] : null;
            $success = isset($filters['success']) && $filters['success'] !== '' ? filter_var($filters['success'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
            $since = isset($filters['since']) && is_numeric($filters['since']) ? (int) $filters['since'] : null;
            $until = isset($filters['until']) && is_numeric($filters['until']) ? (int) $filters['until'] : null;

            // 获取时间范围内的 ID 集合
            $minScoreTime = $since ?? '-inf';
            $maxScoreTime = $until ?? '+inf';

            $ids = $redis->zRevRangeByScore(self::TIME_INDEX, (string) $maxScoreTime, (string) $minScoreTime);
            if (!is_array($ids) || empty($ids)) {
                return ['total' => 0, 'page' => $page, 'pageSize' => $pageSize, 'items' => []];
            }

            // 批量获取详情并进行内存级精确过滤
            $recordKeys = array_map(fn($id) => self::RECORD_PREFIX . $id, $ids);
            $chunks = array_chunk($recordKeys, 200);
            $matched = [];

            foreach ($chunks as $chunkKeys) {
                $rawRecords = $redis->mGet($chunkKeys);
                if (!is_array($rawRecords)) {
                    continue;
                }

                foreach ($rawRecords as $rawJson) {
                    if (!is_string($rawJson) || $rawJson === '') {
                        continue;
                    }

                    $decoded = json_decode($rawJson, true);
                    if (!is_array($decoded)) {
                        continue;
                    }

                    // 过滤条件检查
                    if ($mode !== null && ($decoded['mode'] ?? '') !== $mode) {
                        continue;
                    }
                    if ($promptVersion !== null && ($decoded['promptVersion'] ?? '') !== $promptVersion) {
                        continue;
                    }
                    if ($success !== null && ($decoded['success'] ?? false) !== $success) {
                        continue;
                    }
                    if ($minScore !== null && ($decoded['score']['overall'] ?? 0) < $minScore) {
                        continue;
                    }
                    if ($maxScore !== null && ($decoded['score']['overall'] ?? 0) > $maxScore) {
                        continue;
                    }
                    if ($keyword !== null) {
                        $searchHaystack = ($decoded['id'] ?? '') . ' ' . ($decoded['logId'] ?? '') . ' ' . ($decoded['rootCause'] ?? '');
                        if (stripos($searchHaystack, $keyword) === false) {
                            continue;
                        }
                    }

                    // 列表展示项（精简 trace 字段，减轻网络传输体积）
                    $matched[] = [
                        'id' => $decoded['id'] ?? '',
                        'cacheKey' => $decoded['cacheKey'] ?? '',
                        'logId' => $decoded['logId'] ?? null,
                        'mode' => $decoded['mode'] ?? AnalysisMode::DEEP,
                        'promptVersion' => $decoded['promptVersion'] ?? 'v1',
                        'model' => $decoded['model'] ?? '',
                        'startedAt' => $decoded['startedAt'] ?? '',
                        'timestamp' => $decoded['timestamp'] ?? 0,
                        'success' => $decoded['success'] ?? false,
                        'rounds' => $decoded['rounds'] ?? 0,
                        'toolCallsCount' => $decoded['toolCallsCount'] ?? 0,
                        'durationMs' => $decoded['durationMs'] ?? 0.0,
                        'score' => $decoded['score'] ?? null,
                        'rootCause' => $decoded['rootCause'] ?? '',
                        'fullAnswerSnippet' => $decoded['fullAnswerSnippet'] ?? '',
                    ];
                }
            }

            $total = count($matched);
            $offset = ($page - 1) * $pageSize;
            $pagedItems = array_slice($matched, $offset, $pageSize);

            return [
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
                'items' => $pagedItems,
            ];
        } catch (\Throwable $e) {
            \App\Syslog::error('AnalysisRecord', 'list query failed: ' . $e->getMessage());
            return self::listFromFile($filters, $page, $pageSize);
        }
    }

    /**
     * 获取指定分析记录的完整详情（含完整 trace 与 toolCallChain）。
     */
    public static function get(string $id): ?array
    {
        $redis = RedisClient::getRedis();
        if ($redis !== null) {
            try {
                $raw = $redis->get(self::RECORD_PREFIX . $id);
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            } catch (\Throwable) {
                // fallback
            }
        }

        return self::getFromFile($id);
    }

    /**
     * 仅获取 trace 详情。
     */
    public static function getTrace(string $id): ?array
    {
        $record = self::get($id);
        if ($record === null) {
            return null;
        }

        return $record['trace'] ?? [];
    }

    /**
     * 删除单条分析记录，并清理对应的缓存。
     */
    public static function delete(string $id): bool
    {
        $deleted = false;
        $redis = RedisClient::getRedis();

        if ($redis !== null) {
            try {
                // 读取缓存 key 并连带清理写好的回答缓存
                $recordKey = self::RECORD_PREFIX . $id;
                $raw = $redis->get($recordKey);
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    $cacheKey = $decoded['cacheKey'] ?? null;
                    if (is_string($cacheKey) && $cacheKey !== '') {
                        RedisCache::Delete(\App\Ai\AnalysisQueue::cacheKeyFor($cacheKey));
                    }
                }

                $redis->del($recordKey);
                $redis->zRem(self::TIME_INDEX, $id);
                $redis->zRem(self::SCORE_INDEX, $id);
                $redis->zRem(self::SLOW_INDEX, $id);
                $deleted = true;
            } catch (\Throwable $e) {
                \App\Syslog::error('AnalysisRecord', 'delete failed: ' . $e->getMessage());
            }
        }

        return $deleted;
    }

    /**
     * 质量评分看板（Scoreboard）聚合统计。
     *
     * @return array<string, mixed>
     */
    public static function getScoreboard(int $days = 7): array
    {
        $filters = [];
        if ($days > 0) {
            $filters['since'] = time() - ($days * 86400);
        }
        $listRes = self::list($filters, 1, 1000);
        $items = $listRes['items'];
        $total = count($items);

        if ($total === 0) {
            return [
                'totalAnalyses' => 0,
                'averageScore' => [
                    'overall' => 0.0,
                    'toolEfficiency' => 0.0,
                    'evidenceSufficiency' => 0.0,
                    'conclusionClarity' => 0.0,
                ],
                'lowScoreCount' => 0,
                'lowScoreRatio' => 0.0,
                'averageDurationMs' => 0.0,
                'averageRounds' => 0.0,
                'modeDistribution' => [
                    'deep' => 0,
                    'launcher' => 0,
                    'quick' => 0,
                ],
                'scoreDistribution' => [
                    'excellent' => 0, // 85-100
                    'good' => 0,      // 70-84
                    'pass' => 0,      // 60-69
                    'low' => 0,       // <60
                ],
            ];
        }

        $sumOverall = 0.0;
        $sumToolEff = 0.0;
        $sumEvidence = 0.0;
        $sumClarity = 0.0;
        $sumDuration = 0.0;
        $sumRounds = 0;
        $lowCount = 0;

        $modes = ['deep' => 0, 'launcher' => 0, 'quick' => 0];
        $distribution = ['excellent' => 0, 'good' => 0, 'pass' => 0, 'low' => 0];

        foreach ($items as $item) {
            $score = $item['score'] ?? [];
            $overall = (float) ($score['overall'] ?? 0.0);
            $sumOverall += $overall;
            $sumToolEff += (float) ($score['toolEfficiency'] ?? 0.0);
            $sumEvidence += (float) ($score['evidenceSufficiency'] ?? 0.0);
            $sumClarity += (float) ($score['conclusionClarity'] ?? 0.0);

            $sumDuration += (float) ($item['durationMs'] ?? 0.0);
            $sumRounds += (int) ($item['rounds'] ?? 0);

            $m = (string) ($item['mode'] ?? 'deep');
            if (isset($modes[$m])) {
                $modes[$m]++;
            } else {
                $modes[$m] = 1;
            }

            if ($overall >= 85) {
                $distribution['excellent']++;
            } elseif ($overall >= 70) {
                $distribution['good']++;
            } elseif ($overall >= 60) {
                $distribution['pass']++;
            } else {
                $distribution['low']++;
                $lowCount++;
            }
        }

        return [
            'totalAnalyses' => $total,
            'averageScore' => [
                'overall' => round($sumOverall / $total, 1),
                'toolEfficiency' => round($sumToolEff / $total, 1),
                'evidenceSufficiency' => round($sumEvidence / $total, 1),
                'conclusionClarity' => round($sumClarity / $total, 1),
            ],
            'lowScoreCount' => $lowCount,
            'lowScoreRatio' => round(($lowCount / $total) * 100, 1),
            'averageDurationMs' => round($sumDuration / $total, 1),
            'averageRounds' => round($sumRounds / $total, 1),
            'modeDistribution' => $modes,
            'scoreDistribution' => $distribution,
        ];
    }

    /**
     * 慢分析排行列表。
     */
    public static function getSlowAnalyses(int $limit = 20, float $minDurationMs = 5000.0): array
    {
        $limit = max(1, min(100, $limit));
        $redis = RedisClient::getRedis();

        if ($redis !== null) {
            try {
                $ids = $redis->zRevRangeByScore(self::SLOW_INDEX, '+inf', (string) $minDurationMs, ['limit' => [0, $limit]]);
                if (is_array($ids) && !empty($ids)) {
                    $keys = array_map(fn($id) => self::RECORD_PREFIX . $id, $ids);
                    $records = $redis->mGet($keys);
                    $results = [];
                    foreach ($records as $raw) {
                        if (is_string($raw) && $raw !== '') {
                            $decoded = json_decode($raw, true);
                            if (is_array($decoded)) {
                                $results[] = $decoded;
                            }
                        }
                    }
                    return $results;
                }
            } catch (\Throwable) {
                // fallback
            }
        }

        // 文件降级检索
        $list = self::list([], 1, 200)['items'];
        usort($list, fn($a, $b) => ($b['durationMs'] ?? 0) <=> ($a['durationMs'] ?? 0));
        return array_values(array_filter(array_slice($list, 0, $limit), fn($i) => ($i['durationMs'] ?? 0) >= $minDurationMs));
    }

    /**
     * 低分分析列表（overall score < maxScore，默认 60 分）。
     */
    public static function getLowScoreAnalyses(int $limit = 20, float $maxScore = 60.0): array
    {
        $limit = max(1, min(100, $limit));
        $redis = RedisClient::getRedis();

        if ($redis !== null) {
            try {
                // 升序取 score 最低的分数
                $ids = $redis->zRangeByScore(self::SCORE_INDEX, '0', (string) $maxScore, ['limit' => [0, $limit]]);
                if (is_array($ids) && !empty($ids)) {
                    $keys = array_map(fn($id) => self::RECORD_PREFIX . $id, $ids);
                    $records = $redis->mGet($keys);
                    $results = [];
                    foreach ($records as $raw) {
                        if (is_string($raw) && $raw !== '') {
                            $decoded = json_decode($raw, true);
                            if (is_array($decoded)) {
                                $results[] = $decoded;
                            }
                        }
                    }
                    return $results;
                }
            } catch (\Throwable) {
                // fallback
            }
        }

        // 文件降级检索
        $list = self::list([], 1, 200)['items'];
        usort($list, fn($a, $b) => ($a['score']['overall'] ?? 0) <=> ($b['score']['overall'] ?? 0));
        return array_values(array_filter(array_slice($list, 0, $limit), fn($i) => ($i['score']['overall'] ?? 0) <= $maxScore));
    }

    /* ─── 本地文件存储降级逻辑 ─────────────────────────────────────── */

    private static function listFromFile(array $filters, int $page, int $pageSize): array
    {
        $filePath = CORE_PATH . self::LOCAL_LOG_FILE;
        if (!file_exists($filePath)) {
            return ['total' => 0, 'page' => $page, 'pageSize' => $pageSize, 'items' => []];
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || empty($lines)) {
            return ['total' => 0, 'page' => $page, 'pageSize' => $pageSize, 'items' => []];
        }

        $lines = array_reverse($lines); // 倒序
        $matched = [];

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            $matched[] = $decoded;
        }

        $total = count($matched);
        $offset = ($page - 1) * $pageSize;
        return [
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'items' => array_slice($matched, $offset, $pageSize),
        ];
    }

    private static function getFromFile(string $id): ?array
    {
        $filePath = CORE_PATH . self::LOCAL_LOG_FILE;
        if (!file_exists($filePath)) {
            return null;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return null;
        }

        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ($decoded['id'] ?? '') === $id) {
                return $decoded;
            }
        }

        return null;
    }
}
