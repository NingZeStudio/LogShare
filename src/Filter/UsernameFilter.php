<?php

namespace Filter;

use Filter\Pattern\PatternWithReplacement;

class UsernameFilter extends Filter
{
    /**
     * @return PatternWithReplacement[]
     */
    protected static function getPatterns(): array
    {
        return [
            new PatternWithReplacement("C:\\\\Users\\\\([^\\\\]+)\\\\", "C:\\Users\\********\\"),
            new PatternWithReplacement("C:\\\\\\\\Users\\\\\\\\([^\\\\]+)\\\\\\\\", "C:\\\\Users\\\\********\\\\"),
            new PatternWithReplacement("C:\\/Users\\/([^\\/]+)\\/", "C:/Users/********/"),
            new PatternWithReplacement("(?<!\w)\\/home\\/[^\\/]+\\/", "/home/********/"),
            new PatternWithReplacement("(?<!\w)\\/Users\\/[^\\/]+\\/", "/Users/********/"),
            new PatternWithReplacement("USERNAME=\w+", "USERNAME=********"),
        ];
    }

    public static function filter(string $data): string
    {
        foreach (static::getPatterns() as $pattern) {
            $data = preg_replace('/' . $pattern->getPattern() . '/', $pattern->getReplacement(), $data);
        }
        return $data;
    }
}
