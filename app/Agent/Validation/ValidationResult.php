<?php

declare(strict_types=1);

namespace App\Agent\Validation;

/**
 * 验证结果值对象（不可变）。
 *
 * 封装 ResultValidator 产出的四类验证结论：
 * - hasToolEvidence: 是否存在工具调用证据
 * - unverifiedClaims: 未核实的声明列表
 * - sourceTraceability: 来源可追溯性评估
 * - structuredOutput: 从回答中提取的结构化输出
 */
final class ValidationResult
{
    public function __construct(
        public readonly bool $hasToolEvidence,
        public readonly array $unverifiedClaims,
        public readonly array $sourceTraceability,
        public readonly array $structuredOutput,
    ) {
    }

    public function toArray(): array
    {
        return [
            'hasToolEvidence' => $this->hasToolEvidence,
            'unverifiedClaims' => $this->unverifiedClaims,
            'sourceTraceability' => $this->sourceTraceability,
            'structuredOutput' => $this->structuredOutput,
        ];
    }
}
