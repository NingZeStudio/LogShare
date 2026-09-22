<?php

namespace App\Rag;

/**
 * 结果片段提取器：围绕命中词从 chunk 正文中截取可读片段。
 *
 * 取舍策略：
 *  - 短正文（≤ SNIPPET_FULL_BODY_LIMIT）整段返回——分块按 H2 切割，
 *    整段才能保住「签名 → 含义 → 修复步骤」这类结构完整性；
 *  - 超长正文围绕第一个命中词取 ±SNIPPET_HALF_WINDOW 硬窗口，
 *    再向内回退到最近的空白/句读做整洁断点；找不到边界时用硬窗口，
 *    绝不允许出现几十字符的过短片段。
 */
final class SnippetExtractor
{
    /**
     * 正文短于该长度（字符）时整段返回。
     *
     * 分块本身按 H2 语义单元切割，绝大多数在 1-2K 字符内——整段返回才能把
     * 「签名 → 含义 → 修复步骤」这类结构完整交给模型；此前 600 的阈值导致
     * 长文档几乎总是走窗口模式，解法部分被丢掉。
     */
    private const FULL_BODY_LIMIT = 1600;

    /**
     * 超长正文围绕命中词向前/后扩展的最大字符窗口。
     * 实际边界回退到最近的空白/句读（最多回看 200 字符），不硬性要求句子边界，
     * 否则代码与术语密集的英文文档会因边界过密而被掐到几十个字符。
     */
    private const HALF_WINDOW = 800;
    private const BOUNDARY_LOOKBACK = 200;

    /**
     * @param array<int, string> $terms
     */
    public static function extract(string $body, array $terms): string
    {
        $bodyLen = mb_strlen($body);
        if ($bodyLen === 0) {
            return '';
        }

        if ($bodyLen <= self::FULL_BODY_LIMIT) {
            return $body;
        }

        $hitPos = null;
        $hitLen = 0;
        foreach ($terms as $term) {
            if (mb_strlen($term) < 2) {
                continue; // bigram 噪声项不作为窗口锚点
            }
            $pos = mb_stripos($body, $term);
            if ($pos !== false && ($hitPos === null || $pos < $hitPos)) {
                $hitPos = $pos;
                $hitLen = mb_strlen($term);
            }
        }

        // 命中标题、正文无词时，返回正文开头片段
        if ($hitPos === null) {
            return mb_substr($body, 0, self::HALF_WINDOW) . '…';
        }

        // 硬窗口 + 向内找最近的空白/句读做整洁断点（最多回看 BOUNDARY_LOOKBACK）
        $start = max(0, $hitPos - self::HALF_WINDOW);
        $start = self::retreatToBoundary($body, $start, min($hitPos, $start + self::BOUNDARY_LOOKBACK));

        $end = min($bodyLen, $hitPos + $hitLen + self::HALF_WINDOW);
        $end = self::advanceToBoundary($body, max($end - self::BOUNDARY_LOOKBACK, $hitPos + $hitLen), $end);

        return ($start > 0 ? '…' : '')
            . trim(mb_substr($body, $start, $end - $start))
            . ($end < $bodyLen ? "\n…" : '');
    }

    /**
     * From $from, walk forward to the first blank/sentence boundary at or before
     * $to. Returns $to when no boundary is found in range.
     */
    private static function retreatToBoundary(string $body, int $from, int $to): int
    {
        for ($i = $from; $i < $to; $i++) {
            if (self::isBreak(mb_substr($body, $i, 1))) {
                return $i;
            }
        }
        return $to;
    }

    private static function advanceToBoundary(string $body, int $from, int $to): int
    {
        for ($i = $to - 1; $i >= max($from, 0); $i--) {
            if (self::isBreak(mb_substr($body, $i, 1))) {
                return $i;
            }
        }
        return $to;
    }

    private static function isBreak(string $ch): bool
    {
        // 空白与句读都可作为断点：保留换行即保留 Markdown 列表结构
        return trim($ch) === '' || in_array($ch, ['。', '！', '？', '；', '.', '!', '?', ';'], true);
    }
}
