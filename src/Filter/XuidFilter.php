<?php

namespace Filter;

use Filter\Pattern\PatternWithReplacement;

class XuidFilter extends Filter
{
    /**
     * @return PatternWithReplacement[]
     */
    protected static function getPatterns(): array
    {
        return [
            new PatternWithReplacement(
                'xuid["\s:=]+[0-9]{16,19}',
                'xuid:"****************"'
            ),
            new PatternWithReplacement(
                'XUID["\s:=]+[0-9]{16,19}',
                'XUID:"****************"'
            ),
            new PatternWithReplacement(
                'xboxUserId["\s:=]+[0-9]{16,19}',
                'xboxUserId:"****************"'
            ),
            new PatternWithReplacement(
                'xbox_user_id["\s:=]+[0-9]{16,19}',
                'xbox_user_id:"****************"'
            ),
            new PatternWithReplacement(
                'XUID\([0-9]{16,19}\)',
                'XUID(****************)'
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