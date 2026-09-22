<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Agent\ToolSession;

/**
 * 单个 Agent 工具的契约。
 *
 * 每个工具自包含地声明：对外名称、交给 LLM 的 function-calling schema、
 * 执行逻辑、重试策略与失败降级链。新增工具只需实现本接口并在 ToolRegistry
 * 注册一行，无需改动 AgentRuntime 主体（对齐 plan.md §3.2 目标）。
 */
interface ToolInterface
{
    /** 工具名，须与 schema 中 function.name 及模型调用时的 name 一致 */
    public function name(): string;

    /** OpenAI function-calling 格式的工具定义（含 name/description/parameters） */
    public function definition(): array;

    /**
     * 执行工具调用并返回最终文本结果（回传给模型）。
     *
     * 实现内部抛 ToolExecutionException 表示「可重试/可降级」的临时失败；
     * 硬性参数错误应直接返回可读文本而非抛异常。
     */
    public function run(array $arguments, ToolSession $session): string;

    /** 重试策略，默认由 AbstractTool 提供（本地类不重试） */
    public function retryStrategy(): RetryStrategy;

    /** 失败时依次尝试降级的工具名列表（默认为空） */
    public function fallbackTools(): array;
}
