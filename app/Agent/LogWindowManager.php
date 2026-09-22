<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * 动态日志窗口管理（对齐 plan.md §3.5）。
 *
 * 初始窗口构建算法（Tier1/Tier2 错误锚点 + 前置因果 + 后置堆栈的整行对齐聚焦）
 * 从 LogAgent 原样迁移为唯一实现，保证行为逐字一致；在此基础上扩展：
 * - expandWithAnchors：把 grep/read 命中的新锚点并入「已检查区间」（会话级）；
 * - summary：产出「哪些行区间已检查、发现何种信号」的中文摘要，供后续轮注入。
 *
 * 迁移优先：本轮不改变 buildInitialWindow 的截取策略，仅新增可独立测试的扩散/
 * 摘要方法，避免影响 SSE 逐帧一致性验收。
 */
final class LogWindowManager
{
    private const DEFAULT_MAX_BYTES = 12000;

    /**
     * 长日志智能聚焦窗口（与旧 LogAgent::buildInitialLogWindow 完全一致）。
     *
     * 短日志（< maxBytes）原样返回；否则定位错误锚点，命中则以锚点为核心截取聚焦
     * 窗口，未命中返回 null（上层改用概况 + 常用 grep 引导）。
     */
    public function buildInitialWindow(string $content, int $maxBytes = self::DEFAULT_MAX_BYTES): LogWindow
    {
        if (strlen($content) < $maxBytes) {
            return LogWindow::full($content);
        }

        $lines = explode("\n", $content);
        $totalLines = count($lines);
        $anchorIndex = self::findErrorAnchorLine($lines);

        if ($anchorIndex === null) {
            return LogWindow::none();
        }

        $preContextBytesLimit = (int) min(2500, $maxBytes * 0.25);
        $anchorLineBytes = strlen($lines[$anchorIndex]) + 1;
        $totalBytes = $anchorLineBytes;

        $startIndex = $anchorIndex;
        while ($startIndex > 0) {
            $prevLineBytes = strlen($lines[$startIndex - 1]) + 1;
            if ($totalBytes + $prevLineBytes > $preContextBytesLimit + $anchorLineBytes) {
                break;
            }
            $startIndex--;
            $totalBytes += $prevLineBytes;
        }

        $endIndex = $anchorIndex;
        while ($endIndex + 1 < $totalLines) {
            $nextLineBytes = strlen($lines[$endIndex + 1]) + 1;
            if ($totalBytes + $nextLineBytes > $maxBytes) {
                break;
            }
            $endIndex++;
            $totalBytes += $nextLineBytes;
        }

        while ($startIndex > 0) {
            $prevLineBytes = strlen($lines[$startIndex - 1]) + 1;
            if ($totalBytes + $prevLineBytes > $maxBytes) {
                break;
            }
            $startIndex--;
            $totalBytes += $prevLineBytes;
        }

        $windowLines = array_slice($lines, $startIndex, $endIndex - $startIndex + 1);
        $windowText = implode("\n", $windowLines);

        $startLineNum = $startIndex + 1;
        $endLineNum = $endIndex + 1;
        $anchorLineNum = $anchorIndex + 1;

        $headerNotice = '';
        if ($startIndex > 0) {
            $headerNotice = "[前文已省略第 1 - " . ($startLineNum - 1) . " 行 ...]\n\n";
        }

        $footerNotice = '';
        if ($endIndex < $totalLines - 1) {
            $footerNotice = "\n\n[后文已省略第 " . ($endLineNum + 1) . " - {$totalLines} 行。当前已自动定位到第 {$anchorLineNum} 行错误发生处（截取上下文第 {$startLineNum} - {$endLineNum} 行，共 {$totalLines} 行）；如需查看其它区间请使用 read_log_file 工具]";
        } else {
            $footerNotice = "\n\n[已自动定位到第 {$anchorLineNum} 行错误发生处直至文件末尾（截取上下文第 {$startLineNum} - {$endLineNum} 行，共 {$totalLines} 行）]";
        }

        return new LogWindow(
            $headerNotice . $windowText . $footerNotice,
            $anchorLineNum,
            $startLineNum,
            $endLineNum,
            $totalLines
        );
    }

    /**
     * 扫描日志行数组，匹配首个高置信度错误锚点行（0-indexed）。
     * 与前端 logParser 的 Tier1/Tier2 体系严格对齐（从 LogAgent 原样迁移）。
     *
     * @param string[] $lines
     */
    public static function findErrorAnchorLine(array $lines): ?int
    {
        $tier1Patterns = [
            '/^Caused by:\s*/i',
            '/^Traceback\s*\(most\s+recent\s+call\s+last\)\s*:/i',
            '/^\s*(?:Stacktrace|Details):/i',
            '/^-- Affected level --$/i',
            '/Exception in thread "[^"]+"/i',
            '/(?:\[|:\s*|(?:\/\s*))(?:FATAL|CRITICAL|EMERGENCY|SEVERE)(?:\]|:|\s)/i',
            '/(?:\[|:\s*|(?:\/\s*))ERR(?:OR)?(?:\]|:|\s)/i',
            '/^(?:\s*\[?\s*)?(?:(?:ERROR?|FATAL|CRITICAL)\s*[:;])/i',
            '/\b[A-Za-z0-9_$]+(?:Exception|Error|Throwable)(?::\s+|\s+at\s+|$)/',
        ];

        $tier2Patterns = [
            '/^\s*at\s+[A-Za-z0-9_$]+(?:\.[A-Za-z0-9_$]+)+/',
            '/^\s*File\s+"[^"]*",\s+line\s+\d+/i',
            '/^\s*Suppressed:\s+/i',
            '/^\s*(?:Failed\s+to|Cannot\s+|Unable\s+to|Could\s+not|Illegal\s+|Invalid\s+|Unsupported\s+|Not\s+found\s*[:;]|Missing\s+)/i',
        ];

        $firstTier2Index = null;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            foreach ($tier1Patterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    return $index;
                }
            }

            if ($firstTier2Index === null) {
                foreach ($tier2Patterns as $pattern) {
                    if (preg_match($pattern, $line)) {
                        $firstTier2Index = $index;
                        break;
                    }
                }
            }
        }

        if ($firstTier2Index !== null && preg_match('/^\s*at\s+/i', $lines[$firstTier2Index])) {
            for ($k = $firstTier2Index - 1; $k >= max(0, $firstTier2Index - 3); $k--) {
                $prev = trim($lines[$k]);
                if ($prev !== '' && !preg_match('/^\s*at\s+/i', $prev)) {
                    $firstTier2Index = $k;
                    break;
                }
            }
        }

        return $firstTier2Index;
    }

    /**
     * 把 grep/read 命中的新锚点并入会话「已检查区间」。1-indexed，闭区间。
     *
     * @param array<int, int> $hitLines 命中的行号集合（1-indexed）
     */
    public function recordAnchors(ToolSession $session, array $hitLines, int $contextLines = 1): void
    {
        foreach ($hitLines as $line) {
            $line = (int) $line;
            if ($line < 1) {
                continue;
            }
            $session->markRangeChecked(max(1, $line - $contextLines), $line + $contextLines);
        }
    }

    /**
     * 生成「已检查区域」中文摘要，供后续轮 system prompt 注入，帮助模型收敛
     * 到已探明因果、避免重复通读。
     */
    public function summary(ToolSession $session): string
    {
        if ($session->anchoredRanges === []) {
            return '';
        }

        $parts = [];
        foreach ($session->anchoredRanges as $range) {
            $parts[] = sprintf('第 %d-%d 行', $range[0], $range[1]);
        }

        return '[已检查区域] ' . implode('、', $parts) . '；如需其周边上下文请定向 read_log_file 指定行区间。';
    }
}
