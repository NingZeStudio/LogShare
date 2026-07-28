<?php

namespace Filter;

use Filter\Pattern\PatternWithReplacement;

class CoordinateFilter extends Filter
{
    /**
     * @return PatternWithReplacement[]
     */
    protected static function getPatterns(): array
    {
        return [
            new PatternWithReplacement(
                'BlockPos\([+-]?\d{1,5}\s*,\s*[+-]?\d{1,5}\s*,\s*[+-]?\d{1,5}\)',
                'BlockPos(*****, *****, *****)'
            ),
            new PatternWithReplacement(
                'Vec3d\([+-]?\d{1,5}(?:\.\d+)?\s*,\s*[+-]?\d{1,5}(?:\.\d+)?\s*,\s*[+-]?\d{1,5}(?:\.\d+)?\)',
                'Vec3d(*****, *****, *****)'
            ),
            new PatternWithReplacement(
                'at\s\([+-]?\d{1,5}(?:\.\d+)?\s*,\s*[+-]?\d{1,5}(?:\.\d+)?\s*,\s*[+-]?\d{1,5}(?:\.\d+)?\)',
                'at (*****, *****, *****)'
            ),
            new PatternWithReplacement(
                'block\sposition\s\([+-]?\d{1,5}(?:\.\d+)?\s*,\s*[+-]?\d{1,5}(?:\.\d+)?\s*,\s*[+-]?\d{1,5}(?:\.\d+)?\)',
                'block position (*****, *****, *****)'
            ),
            new PatternWithReplacement(
                'position\s*[=:]\s*[+-]?\d{1,5}(?:\.\d+)?\s*,\s*[+-]?\d{1,5}(?:\.\d+)?\s*,\s*[+-]?\d{1,5}(?:\.\d+)?',
                'position: *****, *****, *****'
            ),
            new PatternWithReplacement(
                'looking at\s\([+-]?\d{1,5}(?:\.\d+)?\s*,\s*[+-]?\d{1,5}(?:\.\d+)?\s*,\s*[+-]?\d{1,5}(?:\.\d+)?\)',
                'looking at (*****, *****, *****)'
            ),
            new PatternWithReplacement(
                'Looking at\s\([+-]?\d{1,5}(?:\.\d+)?\s*,\s*[+-]?\d{1,5}(?:\.\d+)?\s*,\s*[+-]?\d{1,5}(?:\.\d+)?\)',
                'Looking at (*****, *****, *****)'
            ),
            new PatternWithReplacement(
                'block\sat\s\([+-]?\d{1,5}\s*,\s*[+-]?\d{1,5}\s*,\s*[+-]?\d{1,5}\)',
                'block at (*****, *****, *****)'
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