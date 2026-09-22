<?php

declare(strict_types=1);

namespace App\Agent\Tool;

/**
 * 工具结果的「状态摘要」生成器（供 SSE status/tool_result 帧使用）。
 *
 * 与模型无关，纯文本加工：read/grep/list 类工具原文是给模型看的，用户侧只需
 * 紧凑概况。抽为独立类供旧 LogAgent 与新 AgentRuntime 共用，保证两处帧内容逐字
 * 一致（对齐验收标准「SSE 逐帧一致」）。
 */
final class StatusSummarizer
{
    private const SUMMARY_BYTES = 400;

    /**
     * 依据工具类型产出用户可读摘要；read/grep/list_topics 走结构化提取，
     * 其余取前 400 字节。返回的字符串可能是入参的子集，用于判定 truncated。
     */
    public static function summarize(string $tool, string $result): string
    {
        return match ($tool) {
            'read_log_file', 'list_log_files', 'list_topics', 'grep_log_file' => self::compactSummary($tool, $result),
            'rag_search' => self::buildHitListSummary($result),
            default => mb_strcut($result, 0, self::SUMMARY_BYTES),
        };
    }

    /**
     * read/grep/list_topics/list_log_files 的结构化紧凑摘要（旧 LogAgent::buildCompactSummary 唯一实现）。
     */
    public static function compactSummary(string $tool, string $result): string
    {
        $lines = explode("\n", $result);

        if ($tool === 'read_log_file') {
            $summary = trim($lines[0]);
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), '[文件过大已截断')) {
                    $summary .= "\n" . trim($line);
                }
            }
            return $summary;
        }

        if ($tool === 'grep_log_file') {
            $summaryLines = [trim($lines[0])];
            foreach ($lines as $line) {
                $trim = trim($line);
                if (str_starts_with($trim, '>')) {
                    $summaryLines[] = $trim;
                }
            }
            return mb_strcut(implode("\n", $summaryLines), 0, self::SUMMARY_BYTES);
        }

        if ($tool === 'list_topics') {
            $head = trim($lines[0]);
            foreach ($lines as $line) {
                $trim = trim($line);
                if (str_starts_with($trim, '■')) {
                    $head .= "\n" . $trim;
                }
            }
            return mb_strcut($head !== '' ? $head : $result, 0, 1200);
        }

        return mb_strcut($result, 0, self::SUMMARY_BYTES);
    }

    private static function buildHitListSummary(string $result): string
    {
        if (preg_match('/^在知识库中找到\s*(\d+)\s*条相关文档/u', $result, $countMatch)) {
            preg_match_all('/^\[(\d+)\]\s*(.+?)（来源:\s*([^）]+)）\s*$/mu', $result, $hits, PREG_SET_ORDER);
            if ($hits !== []) {
                $lines = ['共命中 ' . $countMatch[1] . ' 条：'];
                foreach ($hits as $hit) {
                    $lines[] = sprintf('[%s] %s（%s）', $hit[1], trim($hit[2]), trim($hit[3]));
                }
                return mb_strcut(implode("\n", $lines), 0, 3000);
            }
        }

        return mb_strcut($result, 0, self::SUMMARY_BYTES);
    }
}
