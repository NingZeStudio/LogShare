<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * SSE 客户端断开连接时抛出：SseWriter::write 检测到底层写失败后触发，
 * 上层（LogAgent / AIClient）捕获后立即中止后续轮次与工具调用，
 * 避免为已断开的连接继续消耗上游 API 配额。
 */
final class ClientDisconnectedException extends \RuntimeException
{
}
