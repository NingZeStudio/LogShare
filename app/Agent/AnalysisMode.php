<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * 分析模式（对齐 plan.md §3.1）。决定工具集、轮次上限与检索预算。
 *
 * - quick：仅非工具直答，1 轮，不检索；
 * - deep：全部工具，最多 50 轮，web≤5、总检索≤6；
 * - launcher：GitHub + RAG 优先，20 轮，web≤3、github≤5、总检索≤6。
 */
final class AnalysisMode
{
    public const QUICK = 'quick';
    public const DEEP = 'deep';
    public const LAUNCHER = 'launcher';

    /** @return array{maxRounds:int,maxWebSearch:int,maxTotalRetrieval:int,maxGithubSearch:int} */
    public static function limits(string $mode): array
    {
        return match ($mode) {
            self::QUICK => [
                'maxRounds' => 1,
                'maxWebSearch' => 0,
                'maxTotalRetrieval' => 0,
                'maxGithubSearch' => 0,
            ],
            self::LAUNCHER => [
                'maxRounds' => 20,
                'maxWebSearch' => 3,
                'maxTotalRetrieval' => 6,
                'maxGithubSearch' => 5,
            ],
            default => [
                'maxRounds' => LogAgent::DEFAULT_MAX_TOOL_ROUNDS,
                'maxWebSearch' => 5,
                'maxTotalRetrieval' => 6,
                'maxGithubSearch' => 5,
            ],
        };
    }

    public static function isKnown(string $mode): bool
    {
        return in_array($mode, [self::QUICK, self::DEEP, self::LAUNCHER], true);
    }
}
