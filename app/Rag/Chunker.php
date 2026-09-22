<?php

namespace App\Rag;

/**
 * Chunker: 知识库分块策略集合。
 *
 * byHeading 是 RagSearch::chunkMarkdown 的逐字节移植（返回形态见 Chunk），
 * 默认策略下索引产物与旧版完全一致；滑动窗口 / token-aware / HYBRID 仅在
 * ai.rag.chunker 显式配置后启用，切换后需重跑 rag:build。
 *
 * 偏移量统一为字节偏移（mb_strcut 保证不切断多字节字符）。
 */
final class Chunker
{
    /** HYBRID 二次切分的触发阈值（估算 token 数） */
    public const HYBRID_MAX_TOKENS = 1024;

    /** 估算 token 数的启发式系数：CJK 1 字符 ≈ 1 token，拉丁 4 字节 ≈ 1 token */
    private const LATIN_BYTES_PER_TOKEN = 4;

    /**
     * 按 `## ` Heading 切分（原 RagSearch::chunkMarkdown 逻辑）。
     *
     * `# ` 页标题保留并前缀到每个 chunk 标题，保证文档主标题仍可被检索。
     *
     * @return Chunk[]
     */
    public static function byHeading(string $source, string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        // Preserve the `# ` page title (H1) for searchability
        $docTitle = null;
        if (preg_match('/^#\s+(.+)$/m', $content, $matches) === 1) {
            $docTitle = trim($matches[1]);
        }

        $sections = preg_split('/^##\s+(.+)$/m', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE);
        if ($sections === false || count($sections) <= 1) {
            return [self::make($source, $docTitle ?? basename($source), $content)];
        }

        $chunks = [];
        $heading = null;
        // $sections alternates: [preamble, heading1, body1, heading2, body2, ...]
        for ($i = 0; $i < count($sections); $i += 2) {
            [$raw, $offset] = $sections[$i];
            $body = trim($raw);

            if ($heading === null) {
                // Preamble before the first H2: drop the H1 line, keep the intro text
                $preamble = preg_replace('/^#\s+[^\n]*\n?/m', '', $raw);
                if (trim((string) $preamble) !== '') {
                    $preamble = trim((string) $preamble);
                    $chunks[] = self::make(
                        $source,
                        $docTitle ?? basename($source),
                        $preamble,
                        self::sliceStart((string) $raw, $preamble, (int) $offset)
                    );
                }
            } elseif ($body !== '') {
                $chunks[] = self::make(
                    $source,
                    $docTitle !== null ? $docTitle . ' > ' . $heading : $heading,
                    $body,
                    self::sliceStart((string) $raw, $body, (int) $offset)
                );
            }

            // 与旧实现逐字节一致：捕获的 heading 文本不做 trim
            $heading = isset($sections[$i + 1]) ? (string) $sections[$i + 1][0] : null;
        }

        return $chunks;
    }

    /**
     * 滑动窗口切分：$chunkSize/$overlap 为字符窗口大小。
     *
     * 无标题结构可依，title 交由调用方回退（basename / H1）；窗口间保留
     * $overlap 字符重叠，避免关键句恰好被切断。
     *
     * @return Chunk[]
     */
    public static function bySlidingWindow(string $content, int $chunkSize = 1000, int $overlap = 150, string $source = '', ?string $title = null): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }
        $chunkSize = max(100, $chunkSize);
        $overlap = max(0, min($overlap, intdiv($chunkSize, 2)));

        $total = mb_strlen($content);
        $step = $chunkSize - $overlap;
        $chunks = [];
        $charStart = 0;
        $byteStart = 0;
        for ($start = 0; $start < $total; $start += $step) {
            $window = mb_substr($content, $start, $chunkSize);
            $chunks[] = new Chunk(
                source: $source,
                title: $title ?? basename($source),
                body: $window,
                startOffset: $byteStart,
                endOffset: $byteStart + strlen($window),
                tokenCount: self::estimateTokens($window),
            );
            // 下一窗口的起点 = 当前起点 + step 个字符的字节数（窗口间有重叠，
            // 不能用窗口长度递推）
            $byteStart += strlen(mb_substr($content, $charStart, $step));
            $charStart = $start + $step;
            if ($start + $chunkSize >= $total) {
                break;
            }
        }

        return $chunks;
    }

    /**
     * Token-aware 切分：按估算 token 数贪心装填句子，超限前断块。
     *
     * 断点优先落在句子/行边界（。！？.!? 与换行），代码块内部不强行切断；
     * 相邻块之间保留最多 $overlapTokens 的尾部句子做重叠。
     *
     * @return Chunk[]
     */
    public static function byToken(string $content, int $maxTokens = 512, int $overlapTokens = 64, string $source = '', ?string $title = null): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        $chunks = [];
        $group = [];
        $groupTokens = 0;
        $pos = 0;          // 原文字节位置：已消费到上一句末尾
        $groupStart = 0;   // 当前组第一句在原文中的起始字节偏移

        $flush = function () use (&$group, &$groupTokens, &$groupStart, &$pos, &$chunks, $source, $title, $overlapTokens): void {
            $body = implode('', $group);
            if (trim($body) !== '') {
                $chunks[] = new Chunk(
                    source: $source,
                    title: $title ?? basename($source),
                    body: $body,
                    startOffset: $groupStart,
                    endOffset: $pos,
                    tokenCount: self::estimateTokens($body),
                );
            }
            // 重叠：下一组从被冲刷组的尾部若干句开始
            $tail = self::overlapTail($group, $overlapTokens);
            $tailLen = strlen(implode('', $tail));
            $groupStart = $pos - $tailLen;
            $group = $tail;
            $groupTokens = $tailLen === 0 ? 0 : array_sum(array_map([self::class, 'estimateTokens'], $tail));
        };

        foreach (self::unitize(self::splitSentences($content), $maxTokens) as $sentence) {
            $tokens = self::estimateTokens($sentence);
            if ($group !== [] && $groupTokens + $tokens > $maxTokens) {
                $flush();
            }
            $group[] = $sentence;
            $groupTokens += $tokens;
            $pos += strlen($sentence);
        }
        if ($group !== []) {
            $flush();
        }

        return $chunks;
    }

    /**
     * 统一入口：按策略切分，HYBRID 时超长 heading chunk 二次切分并挂 parent。
     *
     * @return Chunk[]
     */
    public static function chunk(string $source, string $content, ChunkStrategy $strategy = ChunkStrategy::HEADING_ONLY): array
    {
        return match ($strategy) {
            ChunkStrategy::HEADING_ONLY => self::byHeading($source, $content),
            ChunkStrategy::SLIDING_WINDOW => self::bySlidingWindow($content, source: $source, title: self::h1Title($content) ?? basename($source)),
            ChunkStrategy::TOKEN_AWARE => self::byToken($content, source: $source, title: self::h1Title($content) ?? basename($source)),
            ChunkStrategy::HYBRID => self::byHybrid($source, $content),
        };
    }

    /**
     * HYBRID：Heading 切分为主，估算 token 超过阈值的 chunk 再做一次
     * 句子边界的 token-aware 二次切分。父 chunk 保留（parent-child 检索时
     * 提供完整上下文），子 chunk 通过 parentId 指向父 chunk 的下标。
     *
     * @return Chunk[]
     */
    private static function byHybrid(string $source, string $content): array
    {
        $parents = self::byHeading($source, $content);
        $out = [];
        foreach ($parents as $parent) {
            $out[] = $parent;
            $parentIndex = array_key_last($out);
            if ($parent->tokenCount <= self::HYBRID_MAX_TOKENS) {
                continue;
            }
            $children = self::byToken(
                $parent->body,
                maxTokens: self::HYBRID_MAX_TOKENS,
                source: $source,
                title: $parent->title,
            );
            if (count($children) <= 1) {
                continue;
            }
            foreach ($children as $child) {
                $out[] = new Chunk(
                    source: $child->source,
                    title: $child->title,
                    body: $child->body,
                    startOffset: $parent->startOffset + $child->startOffset,
                    endOffset: $parent->startOffset + $child->endOffset,
                    parentId: $parentIndex,
                    tokenCount: $child->tokenCount,
                );
            }
        }

        return $out;
    }

    /**
     * token 估算：CJK 字符按 1 token、其余字节按 4 字节 1 token 计。
     * 与主流 BPE tokenizer 偏差在 ±20% 内，作为切分阈值足够。
     */
    public static function estimateTokens(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        $cjk = preg_match_all(self::CJK_PATTERN, $text);
        $cjk = $cjk === false ? 0 : $cjk;
        // 命中的 CJK 字符在本集合内均为 3 字节 UTF-8，其余按字节估
        $otherBytes = strlen($text) - $cjk * 3;
        return $cjk + intdiv(max(0, $otherBytes), self::LATIN_BYTES_PER_TOKEN) + 1;
    }

    private static function h1Title(string $content): ?string
    {
        return preg_match('/^#\s+(.+)$/m', trim($content), $matches) === 1 ? trim($matches[1]) : null;
    }

    /**
     * 切出 trim 后正文在原文中的起始字节偏移。
     */
    private static function sliceStart(string $raw, string $trimmed, int $offset): int
    {
        if ($trimmed === '') {
            return $offset;
        }
        $prefixLen = mb_strpos($raw, $trimmed) === false ? 0 : (int) strpos($raw, $trimmed);
        return $offset + $prefixLen;
    }

    /** CJK 标点与表意文字主区（UTF-8 下均为 3 字节） */
    private const CJK_PATTERN = '/[\x{3000}-\x{303f}\x{4e00}-\x{9fff}\x{ff00}-\x{ffef}]/u';

    /**
     * 按行 + 句边界拆分，拼接后与原文逐字节一致（可逆切分）。
     *
     * 先按换行拆（\n 归属前一段尾部），行内再按中英文句读拆（标点归属
     * 前一句）——两级 lookbehind 均不消费字符，implode 可无损还原，
     * token 贪心装填因此能精确累计字节偏移。
     *
     * @return string[]
     */
    private static function splitSentences(string $content): array
    {
        $units = [];
        foreach (preg_split('/(?<=\n)/u', $content) ?: [$content] as $line) {
            if ($line === '') {
                continue;
            }
            foreach (preg_split('/(?<=[。！？!?;；])/u', $line) ?: [$line] as $sentence) {
                if ($sentence !== '') {
                    $units[] = $sentence;
                }
            }
        }
        return $units === [] ? [$content] : $units;
    }

    /**
     * 单句估算 token 超过 $maxTokens 时按字符匀速硬切（兜底：KB 里存在
     * 整段无句读的巨型行）。切片严格顺序消费，拼接还原不变。
     *
     * @param string[] $sentences
     * @return string[]
     */
    private static function unitize(array $sentences, int $maxTokens): array
    {
        $out = [];
        foreach ($sentences as $sentence) {
            if (self::estimateTokens($sentence) <= $maxTokens) {
                $out[] = $sentence;
                continue;
            }
            $piece = '';
            $pieceTokens = 0;
            foreach (preg_split('//u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                $piece .= $char;
                $pieceTokens += preg_match(self::CJK_PATTERN, $char) === 1 ? 1 : intdiv(strlen($char) + 3, 4);
                if ($pieceTokens >= $maxTokens) {
                    $out[] = $piece;
                    $piece = '';
                    $pieceTokens = 0;
                }
            }
            if ($piece !== '') {
                $out[] = $piece;
            }
        }
        return $out;
    }

    /**
     * 取尾部若干句，使估算 token 不超过 $overlapTokens（至少留 1 句会被裁光，
     * 全部超限时返回空数组——重叠只是加分项，不值得撑爆块）。
     *
     * @param string[] $sentences
     * @return string[]
     */
    private static function overlapTail(array $sentences, int $overlapTokens): array
    {
        $tail = [];
        $tokens = 0;
        for ($i = count($sentences) - 1; $i >= 0; $i--) {
            $t = self::estimateTokens($sentences[$i]);
            if ($tail !== [] && $tokens + $t > $overlapTokens) {
                break;
            }
            // 单句本身超预算：放弃它，不产生超大重叠
            if ($t > $overlapTokens) {
                break;
            }
            array_unshift($tail, $sentences[$i]);
            $tokens += $t;
        }
        return $tail;
    }

    /**
     * 构造带 token 估算与字节偏移的 chunk。
     */
    private static function make(string $source, string $title, string $body, int $startOffset = 0): Chunk
    {
        return new Chunk(
            source: $source,
            title: $title,
            body: $body,
            startOffset: $startOffset,
            endOffset: $startOffset + strlen($body),
            tokenCount: self::estimateTokens($body),
        );
    }
}
