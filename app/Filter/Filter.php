<?php

namespace App\Filter;

abstract class Filter
{
    /**
     * Safe preg_replace wrapper that returns original string on error
     *
     * @param string $pattern
     * @param string $replacement
     * @param string $subject
     * @return string
     */
    protected static function safePregReplace(string $pattern, string $replacement, string $subject): string
    {
        $result = @preg_replace($pattern, $replacement, $subject);
        return $result !== null ? $result : $subject;
    }

    /**
     * Safe preg_replace_callback wrapper that returns original string on error.
     *
     * @param string $pattern
     * @param callable $callback
     * @param string $subject
     * @return string
     */
    protected static function safePregReplaceCallback(string $pattern, callable $callback, string $subject): string
    {
        $result = @preg_replace_callback($pattern, $callback, $subject);
        return $result !== null ? $result : $subject;
    }

    /**
     * Filter the $data string and return it
     *
     * @param string $data
     * @return string
     */
    abstract public static function filter(string $data): string;
}
