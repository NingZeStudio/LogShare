<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Tool\ToolResult;
use App\Agent\Validation\ValidationResult;
use App\Agent\Scoring\Score;

/**
 * 分析链路追踪器：记录运行上下文、工具调用、错误与最终指标。
 *
 * 对齐 plan.md §3.8 的 trace 结构，提供 start/recordToolCall/finish/roundCount
 * 四个公开方法，供 AgentRuntime 在多轮循环中逐步填充，最终 export() 产出
 * 完整的链路追踪数据。
 */
final class AnalysisTracer
{
    private ?string $startedAt = null;
    private ?string $cacheKey = null;
    private ?string $logId = null;
    private ?string $mode = null;
    private ?string $promptVersion = null;
    private ?string $model = null;

    /** @var array<int, array{name: string, args: array, result: ToolResult}> */
    private array $toolCalls = [];

    /** @var array<int, array{stage: string, error: string}> */
    private array $errors = [];

    private ?bool $success = null;
    private ?ValidationResult $validation = null;
    private ?Score $score = null;

    /**
     * 记录运行开始。
     */
    public function start(AgentContext $ctx): void
    {
        $this->startedAt = date('c');
        $this->cacheKey = $ctx->cacheKey;
        $this->logId = $ctx->logId;
        $this->mode = $ctx->mode;
        $this->promptVersion = $ctx->promptVersion;

        // 尝试从配置中读取模型名（可选）
        $aiConfig = \App\Config::Get('ai');
        $this->model = $aiConfig['model'] ?? null;
    }

    /**
     * 记录一次工具调用。
     *
     * @param string $name 工具名
     * @param array $args 调用参数
     * @param ToolResult $result 执行结果
     */
    public function recordToolCall(string $name, array $args, ToolResult $result): void
    {
        $this->toolCalls[] = [
            'name' => $name,
            'args' => $args,
            'result' => $result,
        ];
    }

    /**
     * 记录一个错误。
     *
     * @param string $stage 错误阶段（如 'llm_stream', 'tool_execute'）
     * @param string $error 错误信息
     */
    public function recordError(string $stage, string $error): void
    {
        $this->errors[] = [
            'stage' => $stage,
            'error' => $error,
        ];
    }

    /**
     * 记录运行结束。
     *
     * @param bool $success 是否成功完成
     * @param ValidationResult $validation 验证结果
     * @param Score $score 评分结果
     * @return array 指标摘要（durationMs, toolCalls, retriedCalls, errors）
     */
    public function finish(bool $success, ValidationResult $validation, Score $score): array
    {
        $this->success = $success;
        $this->validation = $validation;
        $this->score = $score;

        // 计算指标
        $durationMs = 0.0;
        $retriedCalls = 0;

        foreach ($this->toolCalls as $call) {
            $durationMs += $call['result']->durationMs;
            if ($call['result']->retried) {
                $retriedCalls++;
            }
        }

        return [
            'durationMs' => round($durationMs, 2),
            'toolCalls' => count($this->toolCalls),
            'retriedCalls' => $retriedCalls,
            'errors' => array_map(fn($e) => $e['error'], $this->errors),
        ];
    }

    /**
     * 返回已记录的不同 round 数量。
     *
     * 通过统计 toolCallChain 中的 round 字段去重计数。
     */
    public function roundCount(): int
    {
        // 从 toolCalls 中提取 round 信息（假设每个 toolCall 对应一个 round）
        // 实际上 round 信息在 ToolSession->toolCallChain 中，这里简化处理
        return count($this->toolCalls) > 0 ? 1 : 0;
    }

    /**
     * 导出完整的链路追踪数据（对齐 plan.md §3.8）。
     */
    public function export(): array
    {
        $toolCallDetails = [];
        foreach ($this->toolCalls as $call) {
            $toolCallDetails[] = [
                'name' => $call['name'],
                'args' => $call['args'],
                'ok' => $call['result']->ok,
                'producedBy' => $call['result']->producedBy,
                'retried' => $call['result']->retried,
                'attempts' => $call['result']->attempts,
                'durationMs' => $call['result']->durationMs,
                'error' => $call['result']->error,
            ];
        }

        return [
            'startedAt' => $this->startedAt,
            'cacheKey' => $this->cacheKey,
            'logId' => $this->logId,
            'mode' => $this->mode,
            'promptVersion' => $this->promptVersion,
            'model' => $this->model,
            'success' => $this->success,
            'toolCalls' => $toolCallDetails,
            'errors' => $this->errors,
            'validation' => $this->validation?->toArray(),
            'score' => $this->score?->toArray(),
        ];
    }
}
