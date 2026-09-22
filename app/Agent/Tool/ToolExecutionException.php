<?php

declare(strict_types=1);

namespace App\Agent\Tool;

/**
 * 工具执行期可恢复失败的信号异常。
 *
 * 抛出的失败代表「值得重试或走 fallback」的临时性错误（网络抖动、上游 5xx 等）；
 * ToolRegistry 依据 RetryStrategy 决定是否重试，重试仍失败则按 fallbackTools()
 * 依次降级。参数校验类硬性错误不抛此异常，直接返回 ToolResult::failure。
 */
final class ToolExecutionException extends \RuntimeException
{
}
