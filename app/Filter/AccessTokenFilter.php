<?php

namespace App\Filter;

use App\Filter\Pattern\PatternWithReplacement;

class AccessTokenFilter extends Filter
{
    /**
     * @return PatternWithReplacement[]
     */
    protected static function getPatterns(): array
    {
        return [
            new PatternWithReplacement('accessToken:"[a-zA-Z0-9._-]+"', 'accessToken:"********"'),
            new PatternWithReplacement('access_token:"[a-zA-Z0-9._-]+"', 'access_token:"********"'),
            // 无引号形态（值后跟边界），与 SessionTokenFilter 的处理保持一致
            new PatternWithReplacement('accessToken[\s:=]+[a-zA-Z0-9._-]{20,}(?=$|[\s,;])', 'accessToken:"********"'),
            new PatternWithReplacement('access_token[\s:=]+[a-zA-Z0-9._-]{20,}(?=$|[\s,;])', 'access_token:"********"'),
            new PatternWithReplacement('X-Access-Token: [a-zA-Z0-9._-]+', 'X-Access-Token: ********'),
        ];
    }

    public static function filter(string $data): string
    {
        foreach (static::getPatterns() as $pattern) {
            $data = static::safePregReplace('/' . $pattern->getPattern() . '/', $pattern->getReplacement(), $data);
        }
        return $data;
    }
}