<?php

declare(strict_types=1);

namespace App\Rag;

use App\Client\RedisClient;

/**
 * 检索遥测：每次 search 的各阶段耗时与命中数聚合到 Redis（按天），
 * 慢查询（> ai.rag.telemetry.slowMs，默认 500ms）另存近 100 条明细。
 *
 * 与既有链路的分工：工具调用级耗时已由 ToolResult.durationMs 进入
 * AnalysisTracer（Agent 侧零改动）；本类补齐 RAG 内部的「阶段级」埋点
 * （改写/词法/向量/融合/精排），供 rag:stats 与管理端观测。
 *
 * 全部写入 fail-open：Redis 不可用时静默丢弃，绝不影响检索。
 */
final class RetrievalMetrics
{
    private const KEY_PREFIX = 'rag:metrics:';
    private const SLOW_LIST = 'rag:metrics:slow';
    private const DAY_TTL = 86400 * 30;
    private const SLOW_LIST_MAX = 100;

    /** @return array{enabled: bool, slowMs: int} */
    public static function config(): array
    {
        try {
            $t = (\App\Config::Get('ai')['rag']['telemetry'] ?? []) ?: [];
        } catch (\Throwable) {
            return ['enabled' => true, 'slowMs' => 500];
        }
        return [
            'enabled' => ($t['enabled'] ?? true) === true,
            'slowMs' => max(1, (int) ($t['slowMs'] ?? 500)),
        ];
    }

    /**
     * 记录一次检索的指标快照（search() 内同步调用）。
     *
     * @param array<string, mixed> $m query/k/topic、各 *_ms、lexical_hits/vector_hits/fused 等
     */
    public static function record(array $m): void
    {
        $cfg = self::config();
        if (!$cfg['enabled'] || !isset($m['total_ms'])) {
            return;
        }

        try {
            $redis = RedisClient::getRedis();
            if ($redis === null) {
                return;
            }
            $key = self::KEY_PREFIX . date('Y-m-d');
            $redis->hIncrBy($key, 'queries', 1);
            $redis->hIncrBy($key, 'zero_results', (($m['final'] ?? 0) > 0) ? 0 : 1);

            $floats = ['total_ms', 'rewrite_ms', 'lexical_ms', 'semantic_ms'];
            foreach ($floats as $f) {
                if (isset($m[$f])) {
                    $redis->hIncrByFloat($key, $f . ':sum', (float) $m[$f]);
                    if ((float) $m[$f] > (float) ($redis->hGet($key, $f . ':max') ?: 0)) {
                        $redis->hSet($key, $f . ':max', (string) round((float) $m[$f], 2));
                    }
                }
            }
            $counters = ['rewritten', 'reranked', 'result_cache', 'fused'];
            foreach ($counters as $c) {
                if (!empty($m[$c])) {
                    $redis->hIncrBy($key, $c, (int) (is_bool($m[$c]) ? 1 : $m[$c]));
                }
            }
            if (isset($m['vector_hits'])) {
                $redis->hIncrBy($key, 'vector_searches', 1);
                $redis->hIncrBy($key, 'vector_hits_sum', (int) $m['vector_hits']);
            }
            if (isset($m['semantic_error']) && $m['semantic_error'] !== '') {
                $redis->hIncrBy($key, 'semantic_errors', 1);
            }
            $redis->expire($key, self::DAY_TTL);

            $slowMs = $cfg['slowMs'];
            if ((float) $m['total_ms'] >= $slowMs) {
                $redis->hIncrBy($key, 'slow', 1);
                $entry = json_encode([
                    'at' => time(),
                    'query' => (string) ($m['query'] ?? ''),
                    'k' => (int) ($m['k'] ?? 0),
                    'topic' => $m['topic'] ?? null,
                    'total_ms' => $m['total_ms'],
                    'lexical_ms' => $m['lexical_ms'] ?? null,
                    'semantic_ms' => $m['semantic_ms'] ?? null,
                    'vector_hits' => $m['vector_hits'] ?? null,
                    'reranked' => !empty($m['reranked']),
                ], JSON_UNESCAPED_UNICODE);
                if (is_string($entry)) {
                    $redis->lPush(self::SLOW_LIST, $entry);
                    $redis->lTrim(self::SLOW_LIST, 0, self::SLOW_LIST_MAX - 1);
                    $redis->expire(self::SLOW_LIST, self::DAY_TTL);
                }
                \App\Syslog::warning('RAG', sprintf(
                    'slow query %sms (lex %s ms vec %s ms, hits %s): %s',
                    round((float) $m['total_ms'], 1),
                    round((float) ($m['lexical_ms'] ?? 0), 1),
                    round((float) ($m['semantic_ms'] ?? 0), 1),
                    (string) ($m['vector_hits'] ?? '-'),
                    mb_substr((string) ($m['query'] ?? ''), 0, 80)
                ));
            }
        } catch (\Throwable) {
            // 遥测失败不影响检索
        }
    }

    /** 轻量单点计数（如 SemanticCache 命中/未命中） */
    public static function counter(string $field, int $n = 1): void
    {
        if (!self::config()['enabled']) {
            return;
        }
        try {
            $redis = RedisClient::getRedis();
            if ($redis === null) {
                return;
            }
            $key = self::KEY_PREFIX . date('Y-m-d');
            $redis->hIncrBy($key, $field, $n);
            $redis->expire($key, self::DAY_TTL);
        } catch (\Throwable) {
        }
    }

    /**
     * 读取某天聚合（默认今天）；Redis 不可用返回 null。
     *
     * @return array<string, string>|null
     */
    public static function summary(?string $date = null): ?array
    {
        try {
            $redis = RedisClient::getRedis();
            if ($redis === null) {
                return null;
            }
            $data = $redis->hGetAll(self::KEY_PREFIX . ($date ?? date('Y-m-d')));
            return is_array($data) && $data !== [] ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 最近的慢查询明细（新→旧）。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recentSlow(int $limit = 10): array
    {
        try {
            $redis = RedisClient::getRedis();
            if ($redis === null) {
                return [];
            }
            $raw = $redis->lRange(self::SLOW_LIST, 0, max(1, $limit) - 1);
            if (!is_array($raw)) {
                return [];
            }
            $out = [];
            foreach ($raw as $item) {
                $decoded = json_decode((string) $item, true);
                if (is_array($decoded)) {
                    $out[] = $decoded;
                }
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
