<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Scoring\Score;
use App\Agent\Validation\ValidationResult;

/**
 * 分析质量评分器。
 *
 * 基于工具调用链、验证结果与最终回答文本，产出四维评分：
 * - toolEfficiency: 工具调用效率（重复调用扣分）
 * - evidenceSufficiency: 证据充分性（成功调用次数）
 * - conclusionClarity: 结论清晰度（长度、关键词、结构化）
 * - overall: 加权综合分
 */
final class AnalysisScorer
{
    /**
     * 评分分析结果。
     *
     * @param string $answer 最终回答文本
     * @param ToolSession $session 工具会话
     * @param ValidationResult $validation 验证结果
     */
    public function score(string $answer, ToolSession $session, ValidationResult $validation): Score
    {
        $issues = [];

        // 1. 工具调用效率（100 分起，重复调用扣分）
        $toolEfficiency = 100;
        $lastToolName = null;
        $duplicateCount = 0;

        foreach ($session->toolCallChain as $call) {
            $name = $call['name'] ?? '';
            if ($name === $lastToolName) {
                $duplicateCount++;
                $toolEfficiency -= 15;
            }
            $lastToolName = $name;
        }

        $toolEfficiency = max(0, $toolEfficiency);
        if ($duplicateCount > 0) {
            $issues[] = "存在 {$duplicateCount} 次连续重复工具调用";
        }

        // 2. 证据充分性（基于成功调用次数）
        $okCalls = 0;
        foreach ($session->toolCallChain as $call) {
            if (!empty($call['ok'])) {
                $okCalls++;
            }
        }

        $evidenceSufficiency = match (true) {
            $okCalls === 0 => 20,
            $okCalls === 1 => 55,
            $okCalls === 2 => 75,
            default => 90,
        };

        if ($validation->hasToolEvidence) {
            $evidenceSufficiency += 5;
        }
        $evidenceSufficiency = min(100, $evidenceSufficiency);

        // 3. 结论清晰度（60 分起，按特征加分）
        $conclusionClarity = 60;

        if (strlen($answer) > 200) {
            $conclusionClarity += 20;
        }

        if (str_contains($answer, '根因') || str_contains($answer, '解决方案') || preg_match('/\d+\./', $answer)) {
            $conclusionClarity += 10;
        }

        $rootCause = $validation->structuredOutput['rootCause'] ?? null;
        if ($rootCause !== null) {
            $conclusionClarity += 10;
        }

        $conclusionClarity = min(100, $conclusionClarity);

        // 4. 综合评分（加权平均）
        $overall = (int) round(
            $evidenceSufficiency * 0.4 +
            $toolEfficiency * 0.2 +
            $conclusionClarity * 0.4
        );

        // 5. 回填置信度到 session
        $session->confidence = $overall / 100.0;

        return new Score(
            toolEfficiency: $toolEfficiency,
            evidenceSufficiency: $evidenceSufficiency,
            conclusionClarity: $conclusionClarity,
            overall: $overall,
            issues: $issues,
        );
    }
}
