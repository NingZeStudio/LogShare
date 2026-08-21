<?php

namespace App\Filter;

use App\Filter\Pattern\PatternWithReplacement;

class IPv6ShortFilter extends Filter
{
    /**
     * @return PatternWithReplacement[]
     */
    protected static function getPatterns(): array
    {
        return [
            new PatternWithReplacement(
                '(?<=^|\W)(::(?:ffff:)?(?:[0-9a-fA-F]{1,4}:){0,5}(?:[0-9a-fA-F]{1,4})?|(?:[0-9a-fA-F]{1,4}:){1,6}:|(?:[0-9a-fA-F]{1,4}:){1,5}:[0-9a-fA-F]{1,4}|(?:[0-9a-fA-F]{1,4}:){1,4}(?::[0-9a-fA-F]{1,4}){1,2}|(?:[0-9a-fA-F]{1,4}:){1,3}(?::[0-9a-fA-F]{1,4}){1,3}|(?:[0-9a-fA-F]{1,4}:){1,2}(?::[0-9a-fA-F]{1,4}){1,4}|[0-9a-fA-F]{1,4}:(?::[0-9a-fA-F]{1,4}){1,5}|:(?::[0-9a-fA-F]{1,4}){1,6})(?=%[\w-]+)?(?=$|\W)',
                '****:****:****:****:****:****:****:****'
            ),
            new PatternWithReplacement(
                '(?<=^|\W)::ffff:(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(?=$|\W)',
                '****:****:****:****:****:****:****:****'
            ),
            new PatternWithReplacement(
                '(?<=^|\W)::(?:(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(?=$|\W)',
                '****:****:****:****:****:****:****:****'
            ),
        ];
    }

    /**
     * @return \App\Filter\Pattern\Pattern[]
     */
    protected static function getExemptions(): array
    {
        return [
            new \App\Filter\Pattern\Pattern('^::$'),
            new \App\Filter\Pattern\Pattern('^::1$'),
            new \App\Filter\Pattern\Pattern('^::ffff:127\.\d{1,3}\.\d{1,3}\.\d{1,3}$'),
        ];
    }

    public static function filter(string $data): string
    {
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