<?php

declare(strict_types=1);

namespace App\Agent\Tool;

/**
 * 工具结果回传模型前的字节截断（永不切断多字节字符）。
 *
 * 抽出为独立工具，供旧 LogAgent 与新 AgentRuntime 共用，避免两套截断逻辑漂移；
 * 始终附带可见截断标记，让模型知道结果不完整、可据此决定是否换参重查。
 */
final class ResultTruncator
{
    public const DEFAULT_MAX_BYTES = 12000;

    public static function truncate(string $text, int $maxBytes = self::DEFAULT_MAX_BYTES): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return mb_strcut($text, 0, $maxBytes)
            . "\n\n[...工具结果过长，已截断至 {$maxBytes} 字节；如需更多细节，请调整参数后重新调用]";
    }
}
