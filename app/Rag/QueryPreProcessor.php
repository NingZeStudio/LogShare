<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * 查询预处理：LLM 检索词改写 + 规则分类路由。
 *
 * 两个职责共用 ai.rag.queryRewrite.enabled 总开关（默认关闭，行为保持）：
 *  - rewrite：中文症状查询扩写出英文异常类名/错误关键词，喂给 FTS 通道
 *    （知识库条目以英文签名为锚，中文查询在词法通道天然吃亏）；
 *  - classify：纯规则表判定查询特征，输出 topic 权重，对无显式 topic 的
 *    检索结果做目录偏置提权（稳定排序，只动同池内的相对次序）。
 *
 * LLM 改写任何失败（未配置、超时、输出异常）都回退原查询——预处理是
 * 增强项，不能成为检索的单点故障。
 */
final class QueryPreProcessor
{
    /** 改写结果拼接上限（字符）：只取头部几个检索词，防止 prompt 注入式灌词 */
    private const REWRITE_MAX_CHARS = 160;

    /**
     * 规则分类表（plan §3.3）：特征 → topic 权重；多规则命中取各 topic 最大值。
     * 权重 >1 提权、=1 中性；<1 的抑制项留给后续按运营数据添加。
     */
    private const CLASSIFY_RULES = [
        ['re' => '/Mixin|ClassNotFound|NoSuchMethod|NoClassDefFound|NoSuchField|incompatible/i', 'weights' => ['patterns' => 1.3, '日志分析' => 1.2]],
        ['re' => '/SIGSEGV|SIGBUS|OutOfMemory|OOM|exit code|堆内存|段错误/iu', 'weights' => ['patterns' => 1.3]],
        ['re' => '/闪退|崩溃|卡死|黑屏|进不去|打不开/u', 'weights' => ['patterns' => 1.2, '日志分析' => 1.2]],
        ['re' => '/FCL|Pojav|Amethyst|MobileGlues|Zalith|PGW|启动器|渲染器/iu', 'weights' => ['mobile_launcher' => 1.5, 'android-native-lib' => 1.2]],
        ['re' => '/Fabric|Forge|NeoForge|Quilt|PaperMC|Purpur|Geyser/i', 'weights' => ['日志分析' => 1.3]],
        ['re' => '/chunk|NBT|世界加载|存档|地图损坏/iu', 'weights' => ['patterns' => 1.2]],
        ['re' => '/ConnectException|Timeout|Connection|网络|断线|掉线|连不上/iu', 'weights' => ['日志分析' => 1.1]],
        ['re' => '/格式|latest\.log|crash-report|hs_err/iu', 'weights' => ['format' => 1.4]],
    ];

    /** ai.rag.queryRewrite.enabled 总开关 */
    public static function enabled(): bool
    {
        try {
            return (\App\Config::Get('ai')['rag']['queryRewrite']['enabled'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 规则分类：返回 topic → 权重映射（无命中时为空数组）。
     *
     * @return array<string, float>
     */
    public static function classify(string $query): array
    {
        $weights = [];
        foreach (self::CLASSIFY_RULES as $rule) {
            if (preg_match($rule['re'], $query) === 1) {
                foreach ($rule['weights'] as $topic => $w) {
                    $weights[$topic] = max($weights[$topic] ?? 1.0, (float) $w);
                }
            }
        }
        return $weights;
    }

    /**
     * LLM 改写：输出 2-3 个英文检索词（逗号分隔）追加到查询尾部。
     *
     * 失败返回 null（调用方保持原查询）。
     */
    public static function rewrite(string $query): ?string
    {
        $ai = [];
        try {
            $ai = (array) \App\Config::Get('ai');
        } catch (\Throwable) {
            return null;
        }
        if (empty($ai['apiKeys']) && empty($ai['apiKey'])) {
            return null;
        }

        $prompt = "给定一个 Minecraft 日志排障查询，输出 2-3 个英文检索词"
            . "（异常类名、错误关键词），保留原文中的技术术语不变，仅输出逗号分隔的检索词。\n"
            . "查询：{$query}\n检索词：";

        $answer = '';
        try {
            \App\Client\AIClient::streamChat(
                [['role' => 'user', 'content' => $prompt]],
                [],
                function (string $delta) use (&$answer): void {
                    if (strlen($answer) < self::REWRITE_MAX_CHARS * 4) {
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
        } catch (\Throwable $e) {
            \App\Syslog::error('RAG', 'query rewrite failed, keeping original: ' . $e->getMessage());
            return null;
        }

        return self::sanitizeRewrite($answer);
    }

    /**
     * 清洗模型输出：去行首序号/引号、限长、过滤空白；无有效内容返回 null。
     */
    public static function sanitizeRewrite(string $answer): ?string
    {
        $text = trim(preg_replace('/^[\s\-*#\d.、]+/u', '', trim($answer)) ?? '');
        $text = trim($text, " \t\n\"'`;:.。");
        if ($text === '' || !preg_match('//u', $text)) {
            return null;
        }
        return mb_substr($text, 0, self::REWRITE_MAX_CHARS);
    }

    /**
     * topic 偏置：对无显式 topic 的结果集按目录权重做稳定提权。
     *
     * 实现为一次稳定排序（权重相同保持原次序）：命中高权重目录的条目
     * 前移。只作用于最终截断前，不修改任何条目内容或分数。
     *
     * @param array<int, array{source: string, ...}> $results
     * @param array<string, float> $weights
     * @return array<int, array>
     */
    public static function applyTopicBias(array $results, array $weights): array
    {
        if ($results === [] || $weights === []) {
            return $results;
        }

        // 富集排序键后 usort（PHP 排序不稳定，用原索引做次级键还原稳定性）
        $decorated = [];
        foreach ($results as $i => $r) {
            $parts = explode('/', (string) $r['source']);
            $dir = count($parts) > 1 ? $parts[0] : '';
            $decorated[] = [$weights[$dir] ?? 1.0, $i, $r];
        }
        usort($decorated, fn(array $a, array $b): int => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);

        return array_map(static fn(array $t): array => $t[2], $decorated);
    }

    /**
     * 检索前置处理统一入口（RagSearch::search 调用）。
     *
     * @return array{query: string, weights: array<string, float>, rewritten: bool}
     */
    public static function preprocess(string $query): array
    {
        $weights = self::classify($query);

        if (!self::enabled()) {
            return ['query' => $query, 'weights' => $weights, 'rewritten' => false];
        }

        $extra = self::rewrite($query);
        if ($extra === null) {
            return ['query' => $query, 'weights' => $weights, 'rewritten' => false];
        }

        return [
            'query' => $query . ' ' . $extra,
            'weights' => $weights,
            'rewritten' => true,
        ];
    }
}
