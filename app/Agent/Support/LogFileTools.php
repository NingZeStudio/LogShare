<?php

declare(strict_types=1);

namespace App\Agent\Support;

use App\Agent\ToolSession;

/**
 * 日志文件工具（list/read/grep）的唯一实现。
 *
 * 从 LogAgent 的三个私有静态方法逐字迁移而来，供旧 LogAgent 路径与新的
 * Read/Grep/ListLogFiles Tool 类共同复用，确保两条路径产出的文本与去重行为
 * 完全一致（迁移不改行为，对齐 plan.md Step 2「行为完全一致」要求）。
 *
 * 依赖 App\Log / App\Id 的文件系统存储（不触 Swoole），可在纯 PHP 下测试。
 */
final class LogFileTools
{
    /** 列出会话绑定的全部文件（crash-report 置顶标注 [优先]） */
    public static function listLogFiles(?string $logId): string
    {
        if ($logId === null) {
            return '当前会话未绑定日志文件';
        }

        $log = self::loadSessionLog($logId);
        if ($log === null) {
            return '日志不存在: ' . $logId;
        }

        $lines = [
            "日志 {$logId} 文件列表：",
            sprintf('- main（主文件，%d 字节，%d 行）', $log->getSize(), $log->getLineNumbers()),
        ];

        $files = $log->getFiles();
        usort($files, fn($a, $b) => (int) self::isCrashReportName((string) $b['name']) <=> (int) self::isCrashReportName((string) $a['name']));
        foreach ($files as $file) {
            $mark = self::isCrashReportName((string) $file['name']) ? '[优先] ' : '';
            $lines[] = sprintf('- %s%s（%d 字节，%d 行）', $mark, $file['name'], $file['size'], $log->getFileLineNumbers($file['name']));
        }

        return implode("\n", $lines);
    }

    /** 读取文件（行区间 / 字节 offset 两种模式），会话级防重复完整读取 */
    public static function readLogFile(?string $logId, array $arguments, ToolSession $session): string
    {
        if ($logId === null) {
            return '当前会话未绑定日志文件';
        }

        $log = self::loadSessionLog($logId);
        if ($log === null) {
            return '日志不存在: ' . $logId;
        }

        $filename = $arguments['filename'] ?? '';
        $sessionKey = ($filename === '' || $filename === 'main') ? 'main' : $filename;

        if ($sessionKey === 'main') {
            $content = $log->getContent();
        } else {
            $content = $log->getFile($filename);
            if ($content === null) {
                return '文件不存在: ' . $filename;
            }
        }

        if (($session->readFiles[$sessionKey] ?? false) === true) {
            return self::duplicateReadNotice($sessionKey, $content);
        }

        $length = strlen($content);
        $total = substr_count($content, "\n") + 1;
        $hasLineRange = isset($arguments['line_start']) || isset($arguments['line_end']);

        if ($hasLineRange) {
            $lineStart = max(1, (int) ($arguments['line_start'] ?? 1));
            $lineEnd = isset($arguments['line_end']) ? max($lineStart, (int) $arguments['line_end']) : $total;
            $lines = explode("\n", $content);
            $text = implode("\n", array_slice($lines, $lineStart - 1, $lineEnd - $lineStart + 1));
            if ($lineStart === 1 && $lineEnd >= $total) {
                $session->readFiles[$sessionKey] = true;
            }
            return sprintf(
                "文件 %s（共 %d 行，%d 字节；本次行区间=%d-%d）\n内容：\n%s",
                $sessionKey,
                $total,
                $length,
                $lineStart,
                min($lineEnd, $total),
                $text
            );
        }

        $offset = max(0, (int) ($arguments['offset'] ?? 0));
        if ($offset >= $length) {
            return sprintf('文件 %s 已读取完毕（文件总大小 %d 字节，next_offset=%d）。', $sessionKey, $length, $length);
        }

        $maxBytes = isset($arguments['max_bytes']) ? max(1024, (int) $arguments['max_bytes']) : $length - $offset;
        $text = mb_strcut(substr($content, $offset), 0, $maxBytes);
        $nextOffset = $offset + strlen($text);
        if ($offset === 0 && $nextOffset >= $length) {
            $session->readFiles[$sessionKey] = true;
        }
        $tail = $nextOffset < $length
            ? "\n[内容已截断；请使用 offset={$nextOffset} 继续读取，next_offset={$nextOffset}]"
            : "\n[文件已读取完毕，next_offset={$nextOffset}]";

        return sprintf(
            "文件 %s（共 %d 行，%d 字节；本次 offset=%d）\n内容：\n%s%s",
            $sessionKey, $total, $length, $offset, $text, $tail
        );
    }

    /** 逐行关键词检索，行号对齐 + 命中标记 + 上下文区间合并 */
    public static function grepLogFile(?string $logId, array $arguments): string
    {
        if ($logId === null) {
            return '当前会话未绑定日志文件';
        }

        $query = (string) ($arguments['query'] ?? '');
        if ($query === '') {
            return '检索关键词 query 不能为空';
        }

        $log = self::loadSessionLog($logId);
        if ($log === null) {
            return '日志不存在: ' . $logId;
        }

        $filename = $arguments['filename'] ?? '';
        $sessionKey = ($filename === '' || $filename === 'main') ? 'main' : $filename;

        if ($sessionKey === 'main') {
            $content = $log->getContent();
        } else {
            $content = $log->getFile($sessionKey);
            if ($content === null) {
                return '文件不存在: ' . $filename;
            }
        }

        $lines = explode("\n", $content);
        $totalLines = count($lines);

        $caseSensitive = (bool) ($arguments['case_sensitive'] ?? false);
        $contextLines = max(0, min(5, (int) ($arguments['context_lines'] ?? 1)));
        $maxMatches = max(1, min(30, (int) ($arguments['max_matches'] ?? 10)));

        $matchingLines = [];
        foreach ($lines as $idx => $line) {
            $matched = $caseSensitive
                ? str_contains($line, $query)
                : (stripos($line, $query) !== false);
            if ($matched) {
                $matchingLines[] = $idx + 1;
            }
        }

        $totalFound = count($matchingLines);
        if ($totalFound === 0) {
            return sprintf('在文件 %s 中未找到包含 "%s" 的行。', $sessionKey, $query);
        }

        $displayedMatches = array_slice($matchingLines, 0, $maxMatches);
        $matchSet = array_fill_keys($displayedMatches, true);

        $ranges = [];
        foreach ($displayedMatches as $matchLine) {
            $start = max(1, $matchLine - $contextLines);
            $end = min($totalLines, $matchLine + $contextLines);

            if (!empty($ranges) && $start <= $ranges[count($ranges) - 1]['end'] + 1) {
                $lastIdx = count($ranges) - 1;
                $ranges[$lastIdx]['end'] = max($ranges[$lastIdx]['end'], $end);
            } else {
                $ranges[] = ['start' => $start, 'end' => $end];
            }
        }

        $padWidth = strlen((string) $totalLines);

        $blocks = [];
        foreach ($ranges as $range) {
            $blockLines = [];
            for ($lineNum = $range['start']; $lineNum <= $range['end']; $lineNum++) {
                $isMatch = isset($matchSet[$lineNum]);
                $marker = $isMatch ? '> ' : '  ';
                $paddedNum = str_pad((string) $lineNum, $padWidth, ' ', STR_PAD_LEFT);
                $blockLines[] = sprintf('%s%s | %s', $marker, $paddedNum, $lines[$lineNum - 1]);
            }
            $blocks[] = implode("\n", $blockLines);
        }

        $header = sprintf(
            '在文件 %s（共 %d 行）中检索 "%s"（%s）：共找到 %d 处匹配%s',
            $sessionKey,
            $totalLines,
            $query,
            $caseSensitive ? '区分大小写' : '忽略大小写',
            $totalFound,
            $totalFound > $maxMatches ? sprintf('（已展示前 %d 处）：', $maxMatches) : '：'
        );

        $footer = '';
        if ($totalFound > $maxMatches) {
            $footer = sprintf(
                "\n\n[已达上限 %d 处，后续 %d 处匹配已省略；若需查看更多可增大 max_matches，或配合 read_log_file 精准读取对应行区间]",
                $maxMatches,
                $totalFound - $maxMatches
            );
        }

        return $header . "\n\n" . implode("\n--\n", $blocks) . $footer;
    }

    public static function isCrashReportName(string $name): bool
    {
        return stripos($name, 'crash-report') !== false;
    }

    private static function duplicateReadNotice(string $filename, string $content): string
    {
        $total = substr_count($content, "\n") + 1;
        return sprintf(
            '文件 %s 已读取（共 %d 行，%d 字节），其内容已在上文中提供，请直接基于已有内容进行分析，不要重复调用本工具。',
            $filename,
            $total,
            strlen($content)
        );
    }

    private static function loadSessionLog(?string $logId): ?\App\Log
    {
        $id = new \App\Id($logId);
        $log = new \App\Log($id);
        return $log->exists() ? $log : null;
    }
}
