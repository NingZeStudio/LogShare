<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Tool\RetryStrategy;
use App\Agent\Tool\ToolExecutionException;
use App\Agent\Tool\ToolInterface;
use App\Agent\Tool\ToolResult;

/**
 * Agent 工具注册与分发中心。
 *
 * 承担三类职责：
 * 1. 注册与发现：ToolInterface 实例统一登记，schema 汇聚供 LLM 消费；
 * 2. 执行：按名称调度，包裹重试与降级链，返回值对象 ToolResult；
 * 3. 观测：调用尝试次数、耗时、是否命中重试/降级由 ToolResult 承载，
 *    上层 AnalysisTracer / ToolSession 直接使用。
 *
 * 硬性参数错误不进入本层的重试分支：工具应通过 run() 直接返回可读文本；
 * 只有 ToolExecutionException 才被视为「可重试 / 可降级」的临时失败。
 * 未知工具、循环降级、全部降级失败等终止情况以 ToolResult::failure 返回，
 * 不向调用方抛异常，保证单点失败不阻塞整体分析（对齐 plan.md §3.3）。
 */
final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    /** 可注入的微秒 sleep，方便单测把指数退避变成 no-op 加速断言 */
    private $sleeper;

    public function __construct()
    {
        $this->sleeper = static function (int $microseconds): void {
            if ($microseconds > 0) {
                usleep($microseconds);
            }
        };
    }

    /** 仅供测试注入的 sleep 替身；传 null 恢复默认行为 */
    public function overrideSleeper(?callable $sleeper): void
    {
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            if ($microseconds > 0) {
                usleep($microseconds);
            }
        };
    }

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /** @return string[] 已注册工具名（注册顺序） */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * 汇聚 function-calling 定义，用于随请求发给模型。
     *
     * @param string[]|null $names 传入则仅返回这些工具的 schema（按给定顺序）；
     *                             未知名称忽略不报错，方便上层按配置开关过滤。
     */
    public function schemas(?array $names = null): array
    {
        $out = [];
        $source = $names === null ? $this->tools : array_filter(
            array_map(fn($n) => $this->tools[$n] ?? null, $names)
        );
        foreach ($source as $tool) {
            $out[] = $tool->definition();
        }

        return $out;
    }

    /**
     * 执行工具调用：先按 retryStrategy 重试，仍失败则按 fallbackTools 依次降级。
     *
     * 全程不抛异常；返回 ToolResult 承载最终文本与元数据（是否重试、实际产出
     * 工具、耗时、尝试次数），上层直接把 ToolResult->content 回传模型即可。
     */
    public function execute(string $name, array $arguments, ToolSession $session): ToolResult
    {
        $started = microtime(true);

        return $this->dispatch($name, $arguments, $session, [$name], $started);
    }

    /**
     * @param string[] $visited 本次调用链上已执行过的工具名（防降级成环）
     */
    private function dispatch(string $name, array $arguments, ToolSession $session, array $visited, float $started): ToolResult
    {
        $tool = $this->tools[$name] ?? null;
        if ($tool === null) {
            return ToolResult::failure(
                '未知工具: ' . $name,
                $name,
                false,
                0,
                $this->elapsedMs($started),
                'not_registered'
            );
        }

        [$content, $attempts, $retried, $failure] = $this->attemptWithRetry($tool, $arguments, $session);

        if (!$failure) {
            return ToolResult::success($content, $name, $retried, $attempts, $this->elapsedMs($started));
        }

        foreach ($tool->fallbackTools() as $fallbackName) {
            if (in_array($fallbackName, $visited, true)) {
                continue;
            }
            $visited[] = $fallbackName;
            $result = $this->dispatch($fallbackName, $arguments, $session, $visited, $started);
            if ($result->ok) {
                return ToolResult::success($result->content, $fallbackName, true, $attempts, $this->elapsedMs($started));
            }
        }

        return ToolResult::failure($content, $name, $retried, $attempts, $this->elapsedMs($started), 'execution_failed');
    }

    /**
     * 按 RetryStrategy 循环尝试执行单个工具。
     *
     * @return array{0:string,1:int,2:bool,3:bool} [最终文本, 已尝试次数, 是否发生过重试, 是否为失败退出]
     */
    private function attemptWithRetry(ToolInterface $tool, array $arguments, ToolSession $session): array
    {
        $strategy = $tool->retryStrategy();
        $attempts = 0;
        $lastError = '';

        while (true) {
            $attempts++;
            try {
                return [$tool->run($arguments, $session), $attempts, $attempts > 1, false];
            } catch (ToolExecutionException $e) {
                $lastError = $e->getMessage();
                $delayMs = $strategy->nextDelayMs($attempts);
                if ($delayMs <= 0) {
                    break;
                }
                ($this->sleeper)($delayMs * 1000);
            } catch (\Throwable $e) {
                // 非 ToolExecutionException 的异常视为不可重试的硬性错误：
                // 保留可读文本回传模型，让模型自行决定后续（对齐 LogAgent
                // 现有 try/catch 兜底策略，避免栈信息直接暴露给前端）
                $lastError = $e->getMessage();
                break;
            }
        }

        $content = '工具调用失败: ' . ($lastError !== '' ? $lastError : $tool->name());

        return [$content, $attempts, $attempts > 1, true];
    }

    private function elapsedMs(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 2);
    }
}
