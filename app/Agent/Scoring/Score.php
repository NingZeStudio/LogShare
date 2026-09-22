<?php

declare(strict_types=1);

namespace App\Agent\Scoring;

/**
 * 评分结果值对象（不可变）。
 *
 * 封装 AnalysisScorer 产出的四维评分：
 * - toolEfficiency: 工具调用效率（0-100）
 * - evidenceSufficiency: 证据充分性（0-100）
 * - conclusionClarity: 结论清晰度（0-100）
 * - overall: 综合评分（0-100）
 * - issues: 扣分项列表
 */
final class Score
{
    public function __construct(
        public readonly int $toolEfficiency,
        public readonly int $evidenceSufficiency,
        public readonly int $conclusionClarity,
        public readonly int $overall,
        public readonly array $issues,
    ) {
    }

    public function toArray(): array
    {
        return [
            'toolEfficiency' => $this->toolEfficiency,
            'evidenceSufficiency' => $this->evidenceSufficiency,
            'conclusionClarity' => $this->conclusionClarity,
            'overall' => $this->overall,
            'issues' => $this->issues,
        ];
    }
}
