<?php

namespace Filter;

use Filter\Pattern\PatternWithReplacement;

class AccessTokenFilter extends Filter
{
    /**
     * @return PatternWithReplacement[]
     */
    protected static function getPatterns(): array
    {
        return [
            new PatternWithReplacement('accessToken":"[a-zA-Z0-9._-]+"', 'accessToken":"********"'),
            new PatternWithReplacement('accessToken":"\\"[a-zA-Z0-9._-]+\\""', 'accessToken":"********"'),
            new PatternWithReplacement('access_token":"[a-zA-Z0-9._-]+"', 'access_token":"********"'),
            new PatternWithReplacement('X-Access-Token: [a-zA-Z0-9._-]+', 'X-Access-Token: ********'),
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
