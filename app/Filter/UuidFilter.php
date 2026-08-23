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
        // 快速预检：UUID 必然含 8 位连续十六进制，无则跳过全文正则
        if (preg_match('/[0-9a-fA-F]{8}/', $data) === 0) {
            return $data;
        }

        foreach (static::getPatterns() as $pattern) {
            $data = static::safePregReplace('/' . $pattern->getPattern() . '/', $pattern->getReplacement(), $data);
        }
        return $data;
    }
}