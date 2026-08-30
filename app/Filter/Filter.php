<?php

namespace App\Filter;

abstract class Filter
{
    /**
     * Safe preg_replace wrapper that returns original string on error.
     *
     * 正则执行失败（如回溯超限）时返回原文——对脱敏过滤器这是 fail-open，
     * 必须写入错误日志保持可观测，避免敏感信息静默入库。
     *
     * @param string $pattern
     * @param string $replacement
     * @param string $subject
     * @return string
     */
    protected static function safePregReplace(string $pattern, string $replacement, string $subject): string
    {
        $result = @preg_replace($pattern, $replacement, $subject);
        if ($result === null) {
            \App\Syslog::error('Filter', 'preg_replace 执行失败，本次未打码: ' . $pattern);
            return $subject;
        }
        return $result;
    }

    /**
     * Safe preg_replace_callback wrapper that returns original string on error.
     *
     * 语义同 safePregReplace：失败返回原文并记录错误日志（fail-open 可观测）。
     *
     * @param string $pattern
     * @param callable $callback
     * @param string $subject
     * @return string
     */
    protected static function safePregReplaceCallback(string $pattern, callable $callback, string $subject): string
    {
        $result = @preg_replace_callback($pattern, $callback, $subject);
        if ($result === null) {
            \App\Syslog::error('Filter', 'preg_replace_callback 执行失败，本次未打码: ' . $pattern);
            return $subject;
        }
        return $result;
    }

    /**
     * Filter the $data string and return it
     *
     * @param string $data
     * @return string
     */
    abstract public static function filter(string $data): string;
}
