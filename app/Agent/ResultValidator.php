<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Validation\ValidationResult;

/**
 * 结果验证器：检查工具调用证据、未核实声明、来源可追溯性。
 */
final class ResultValidator
{
    /**
     * 验证分析结果。
     *
     * @param string $answer 最终回答文本
     * @param ToolSession $session 工具会话
     */
    public function validate(string $answer, ToolSession $session): ValidationResult
    {
        // 1. 检查是否有工具调用证据
        $hasToolEvidence = false;
        $sourceTraceability = [];

        foreach ($session->toolCallChain as $call) {
            if (!empty($call['ok'])) {
                $hasToolEvidence = true;
                $sourceTraceability[] = [
                    'name' => $call['name'] ?? 'unknown',
                    'verified' => true,
                ];
            } else {
                $sourceTraceability[] = [
                    'name' => $call['name'] ?? 'unknown',
                    'verified' => false,
                ];
            }
        }

        // 2. 检查未核实声明（包含"可能"、"未核实"、"待确认"等标记词）
        $unverifiedClaims = [];
        $markers = ['可能', '未核实', '待确认', '或许', '大概', '估计'];

        if (!$hasToolEvidence) {
            foreach ($markers as $marker) {
                if (str_contains($answer, $marker)) {
                    $unverifiedClaims[] = "回答包含不确定性表述「{$marker}」，但缺乏工具调用证据支撑";
                    break;
                }
            }
        }

        // 3. 尝试提取结构化输出
        $structuredOutput = $this->extractStructuredOutput($answer);

        return new ValidationResult(
            hasToolEvidence: $hasToolEvidence,
            unverifiedClaims: $unverifiedClaims,
            sourceTraceability: $sourceTraceability,
            structuredOutput: $structuredOutput,
        );
    }

    /**
     * 从回答中提取结构化输出（{rootCause, confidence, evidence[], steps[]}）。
     *
     * 优先解析正文末尾的 ```json 代码块（提示词已要求模型按此 schema 输出）；
     * 无 JSON 块时从中文正文启发式补齐，保证四个字段恒为结构良好值，
     * 前端可稳定分栏展示、下游评分可依赖。
     */
    private function extractStructuredOutput(string $answer): array
    {
        // 1. 首选：正文末尾的 ```json ... ``` 代码块
        if (preg_match('/```json\s*(\{.*?\})\s*```/s', $answer, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $this->normalizeStructured($decoded, $answer);
            }
        }

        // 2. 次选：独立的含 rootCause 键的 JSON 对象（容错网关吞掉围栏）
        if (preg_match('/\{[^{}]*"rootCause"[^{}]*\}/s', $answer, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $this->normalizeStructured($decoded, $answer);
            }
        }

        // 3. 兜底：从中文正文推断根因 / 证据 / 步骤
        return $this->normalizeStructured([], $answer);
    }

    /**
     * 把任意来源的结构化片段归一为固定四字段，缺项用正文启发式补齐。
     *
     * @param array $raw 模型 JSON 或空数组
     */
    private function normalizeStructured(array $raw, string $answer): array
    {
        $rootCause = isset($raw['rootCause']) && is_string($raw['rootCause']) ? trim($raw['rootCause']) : '';
        if ($rootCause === '') {
            if (preg_match('/根因[是为：:]\s*(.+?)(?:\n|$)/u', $answer, $m)) {
                $rootCause = trim($m[1]);
            } elseif (preg_match('/root\s*cause[是为：:]\s*(.+?)(?:\n|$)/iu', $answer, $m)) {
                $rootCause = trim($m[1]);
            }
        }

        $evidence = is_array($raw['evidence'] ?? null)
            ? array_values(array_filter($raw['evidence'], 'is_string'))
            : [];

        $steps = is_array($raw['steps'] ?? null)
            ? array_values(array_filter($raw['steps'], 'is_string'))
            : $this->deriveSteps($answer);

        $confidence = isset($raw['confidence']) && is_numeric($raw['confidence'])
            ? round(max(0.0, min(1.0, (float) $raw['confidence'])), 2)
            : ($rootCause !== '' && $evidence !== [] ? 0.6 : ($rootCause !== '' ? 0.4 : 0.0));

        return [
            'rootCause' => $rootCause,
            'confidence' => $confidence,
            'evidence' => $evidence,
            'steps' => $steps,
        ];
    }

    /** 从正文「解决方案 / 步骤」段落提取有序步骤（无结构化 JSON 时的兜底）。 */
    private function deriveSteps(string $answer): array
    {
        $steps = [];
        if (preg_match_all('/^\s*(?:\d+[\.、)]|[-*])\s+(.+)$/m', $answer, $m)) {
            $steps = array_map('trim', $m[1]);
        }

        return array_slice($steps, 0, 10);
    }
}
