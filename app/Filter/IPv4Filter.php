<?php

namespace App\Filter;

use App\Filter\Pattern\Pattern;
use App\Filter\Pattern\PatternWithReplacement;

class IPv4Filter extends Filter
{
    /**
     * @return PatternWithReplacement[]
     */
    protected static function getPatterns(): array
    {
        return [
            new PatternWithReplacement('(?<!version:? )(?<!([0-9]|-|\w))([0-9]{1,3}\.){3}[0-9]{1,3}(?![0-9])', '**.**.**.**'),
        ];
    }

    /**
     * @return Pattern[]
     */
    protected static function getExemptions(): array
    {
        return [
            new Pattern('^127\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$'),
            new Pattern('^0\.0\.0\.0$'),
            new Pattern('^1\.[01]\.[01]\.1$'),
            new Pattern('^8\.8\.[84]\.[84]$'),
        ];
    }

    public static function filter(string $data): string
    {
        // 快速预检：IPv4 必然含「4 组数字点分」形式，无则跳过全文正则
        if (preg_match('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', $data) === 0) {
            return $data;
        }

        foreach (static::getPatterns() as $pattern) {
            $data = preg_replace_callback('/' . $pattern->getPattern() . '/', function ($matches) use ($pattern) {
                foreach (static::getExemptions() as $exemption) {
                    if (preg_match('/' . $exemption->getPattern() . '/', $matches[0])) {
                        return $matches[0];
                    }
                }
                return $pattern->getReplacement();
            }, $data);
        }
        return $data;
    }
}
