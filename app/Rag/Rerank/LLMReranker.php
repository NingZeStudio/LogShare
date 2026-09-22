<?php

declare(strict_types=1);

namespace App\Rag\Rerank;

use App\Client\AIClient;

/**
 * LLM 精排器：单次调用让模型对候选按相关性重排。
 *
 * 取舍：plan 提出 pairwise 两两比较，但 top-30 的 pairwise 需要数百次调用，
 * 与「单次调用成本 < ¥0.001」自相矛盾；listwise 一次调用（候选截断 + 短
 * snippet）在实测精度上足够逼近 pairwise，成本 O(1)。任何失败（未配置
 * 模型、超时、输出无法解析）都原样返回输入——精排是增强项，绝不能让检索
 * 因它失败。
 */
final class LLMReranker implements RerankInterface
{
    /** 送给模型的重排索引上限：更多候选只会稀释注意力并推高 token 成本 */
    private const MAX_CANDIDATES = 30;

    /** 每条候选进 prompt 的正文截断长度（字符） */
    private const SNIPPET_CHARS = 160;

    /** 模型回答累积上限（字节）：序号列表极短，超限即停止累积防异常长输出 */
    private const ANSWER_CAP_BYTES = 4096;

    public function __construct(private readonly int $maxCandidates = self::MAX_CANDIDATES)
    {
    }

    public function isActive(): bool
    {
        return true;
    }

    public function rerank(string $query, array $candidates): array
    {
        if (count($candidates) < 2) {
            return $candidates;
        }

        $pool = array_slice($candidates, 0, $this->maxCandidates);
        try {
            $order = $this->askModel($query, $pool);
        } catch (\Throwable $e) {
            // 精排是增强项：模型不可用/超时/解析失败一律回退 RRF 顺序
            \App\Syslog::error('RAG', 'LLM rerank failed, keeping RRF order: ' . $e->getMessage());
            return $candidates;
        }
        if ($order === null) {
            return $candidates;
        }

        // 模型只重排 pool 段；池外候选保持原有相对顺序接在尾部
        $out = [];
        $used = [];
        foreach ($order as $idx) {
            if (isset($pool[$idx]) && !isset($used[$idx])) {
                $out[] = $pool[$idx];
                $used[$idx] = true;
            }
        }
        foreach ($pool as $idx => $c) {
            if (!isset($used[$idx])) {
                $out[] = $c;
            }
        }
        return array_merge($out, array_slice($candidates, $this->maxCandidates));
    }

    /**
     * 一次 LLM 调用，解析逗号分隔的 1-based 序号；无法解析时返回 null。
     *
     * @param array<int, array> $pool
     * @return int[]|null 0-based 索引序列
     */
    private function askModel(string $query, array $pool): ?array
    {
        $lines = [];
        foreach ($pool as $i => $c) {
            $body = mb_substr(preg_replace('/\s+/u', ' ', trim((string) $c['snippet'] !== '' ? $c['snippet'] : $c['body'])) ?? '', 0, self::SNIPPET_CHARS);
            $lines[] = ($i + 1) . '. ' . $c['title'] . ' — ' . $body;
        }
        $prompt = "按与查询的相关性从高到低重排以下条目，仅输出逗号分隔的序号（如 3,1,2）。\n查询：{$query}\n"
            . implode("\n", $lines);

        $answer = '';
        AIClient::streamChat(
            [['role' => 'user', 'content' => $prompt]],
            [],
            function (string $delta) use (&$answer): void {
                if (strlen($answer) < self::ANSWER_CAP_BYTES) {
                    $answer .= $delta;
                }
            },
            function (string $reasoning): void {
            },
            function (array $toolCalls, string $reasoning): void {
            },
            function (string $fullContent) use (&$answer): void {
                if ($answer === '') {
                    $answer = $fullContent;
                }
            }
        );

        return self::parseOrder($answer, count($pool));
    }

    /**
     * 从模型回答中提取 1..$size 的去重序号序列；有效序号不足 2 个视为失败。
     *
     * @return int[]|null
     */
    public static function parseOrder(string $answer, int $size): ?array
    {
        preg_match_all('/\d+/', $answer, $m);
        $order = [];
        $seen = [];
        foreach ($m[0] as $n) {
            $idx = (int) $n - 1;
            if ($idx >= 0 && $idx < $size && !isset($seen[$idx])) {
                $seen[$idx] = true;
                $order[] = $idx;
            }
        }
        return count($order) >= 2 ? $order : null;
    }
}
