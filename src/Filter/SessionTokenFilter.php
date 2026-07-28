<?php

namespace Filter;

use Filter\Pattern\PatternWithReplacement;

class SessionTokenFilter extends Filter
{
    /**
     * @return PatternWithReplacement[]
     */
    protected static function getPatterns(): array
    {
        return [
            new PatternWithReplacement(
                'accessToken["\s:=]+[a-zA-Z0-9._-]{20,}"',
                'accessToken:"********"'
            ),
            new PatternWithReplacement(
                'access_token["\s:=]+[a-zA-Z0-9._-]{20,}"',
                'access_token:"********"'
            ),
            new PatternWithReplacement(
                '(?:Authorization|authorization):\sBearer\s[a-zA-Z0-9._-]{20,}',
                'Authorization: Bearer ********'
            ),
            new PatternWithReplacement(
                'X-Access-Token:\s[a-zA-Z0-9._-]{20,}',
                'X-Access-Token: ********'
            ),
            new PatternWithReplacement(
                'x-access-token:\s[a-zA-Z0-9._-]{20,}',
                'x-access-token: ********'
            ),
            new PatternWithReplacement(
                'sessionId["\s:=]+[a-zA-Z0-9._-]+:[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}"',
                'sessionId:"********:********-****-****-****-************"'
            ),
            new PatternWithReplacement(
                'session_id["\s:=]+[a-zA-Z0-9._-]+:[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}"',
                'session_id:"********:********-****-****-****-************"'
            ),
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