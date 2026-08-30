<?php

namespace App\Filter;

use App\Filter\Pattern\PatternWithReplacement;

class SessionTokenFilter extends Filter
{
    /**
     * @return PatternWithReplacement[]
     */
    protected static function getPatterns(): array
    {
        return [
            // 引号形态：值被双引号包裹，整体消费两侧引号
            new PatternWithReplacement(
                'accessToken["\s:=]*"[a-zA-Z0-9._-]{20,}"',
                'accessToken:"********"'
            ),
            new PatternWithReplacement(
                'access_token["\s:=]*"[a-zA-Z0-9._-]{20,}"',
                'access_token:"********"'
            ),
            // 无引号形态：值后跟边界（行尾/空白/逗号/分号）即打码，
            // 用前瞻避免消费边界字符
            new PatternWithReplacement(
                'accessToken["\s:=]+[a-zA-Z0-9._-]{20,}(?=$|[\s,;])',
                'accessToken:"********"'
            ),
            new PatternWithReplacement(
                'access_token["\s:=]+[a-zA-Z0-9._-]{20,}(?=$|[\s,;])',
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
                'sessionId["\s:=]*"[a-zA-Z0-9._-]+:[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}"',
                'sessionId:"********:********-****-****-****-************"'
            ),
            new PatternWithReplacement(
                'sessionId["\s:=]+[a-zA-Z0-9._-]+:[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}(?=$|[\s,;])',
                'sessionId:"********:********-****-****-****-************"'
            ),
            new PatternWithReplacement(
                'session_id["\s:=]*"[a-zA-Z0-9._-]+:[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}"',
                'session_id:"********:********-****-****-****-************"'
            ),
            new PatternWithReplacement(
                'session_id["\s:=]+[a-zA-Z0-9._-]+:[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}(?=$|[\s,;])',
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