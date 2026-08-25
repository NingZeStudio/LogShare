<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * MCP JSON-RPC "method not found" (-32601)。
 *
 * 独立类型便于 RagController 将其与其他错误区分开，
 * 按规范返回 -32601 而非笼统的 invalid params / internal error。
 */
final class McpMethodNotFoundException extends \RuntimeException
{
}
