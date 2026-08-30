<?php

namespace App\Filter;

use App\Filter\Pattern\PatternWithReplacement;

class UuidFilter extends Filter
{
    /**
     * @return PatternWithReplacement[]
     */
    protected static function getPatterns(): array
    {
        return [
            new PatternWithReplacement(
                '(?:^|\W)\K[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}(?=\W|$)',
                '********-****-****-****-************'
            ),
            new PatternWithReplacement(
                '(?:^|\W)\K[0-9a-fA-F]{8}[0-9a-fA-F]{4}[0-9a-fA-F]{4}[0-9a-fA-F]{4}[0-9a-fA-F]{12}(?=\W|$)',
                '****************************'
            ),
            new PatternWithReplacement(
                '(?:^|\W)\K\{[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\}(?=\W|$)',
                '{********-****-****-****-************}'
            ),
            new PatternWithReplacement(
                '(?:^|\W)\Kurn:uuid:[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}(?=\W|$)',
                'urn:uuid:********-****-****-****-************'
            ),
        ];
    }

    public static function filter(string $data): string
    {
        // 快速预检：要求「8 位 hex + 连字符」或「16 位以上连续 hex」，覆盖
        // 带连字符与 32 连写两种 UUID 形态；普通数字串（时间戳等）不命中
        if (preg_match('/(?:[0-9a-fA-F]{8}-|[0-9a-fA-F]{16,})/', $data) === 0) {
            return $data;
        }

        foreach (static::getPatterns() as $pattern) {
            $data = static::safePregReplace('/' . $pattern->getPattern() . '/', $pattern->getReplacement(), $data);
        }
        return $data;
    }
}