<?php

declare(strict_types=1);

namespace App\Agent\Support;

use App\Agent\ToolSession;
use App\Client\MCPClient;

/**
 * MCP 客户端工厂：按 endpoint 配置缓存并复用 MCPClient 实例。
 *
 * 从 LogAgent::mcpClient() 迁移而来，供旧 LogAgent 路径与新 Tool 类共同使用，
 * 保证同一 endpoint 在一次分析运行内只初始化一次（对齐 plan.md §3.2）。
 */
final class McpClientFactory
{
    /**
     * 获取已初始化的 MCPClient（按 url 缓存到 session）。
     */
    public static function get(array $endpoint, ToolSession $session): MCPClient
    {
        $url = (string) ($endpoint['url'] ?? '');
        if ($url === '') {
            throw new \InvalidArgumentException('MCP endpoint url is empty');
        }

        if (!isset($session->mcpClients[$url])) {
            $headers = is_array($endpoint['headers'] ?? null) ? $endpoint['headers'] : [];
            if (($endpoint['authToken'] ?? '') !== '') {
                $headers[] = 'Authorization: Bearer ' . $endpoint['authToken'];
            }
            $timeout = (int) ($endpoint['timeout'] ?? 30);
            $session->mcpClients[$url] = new MCPClient($url, $headers, $timeout);
        }

        return $session->mcpClients[$url];
    }

    /**
     * 调用 MCP 工具并返回拼接后的文本结果。
     */
    public static function call(string $name, array $arguments, array $endpoint, ToolSession $session): string
    {
        $url = $endpoint['url'] ?? '';
        if ($url === '') {
            return '该工具未配置，无法调用';
        }

        $client = self::get($endpoint, $session);
        $contents = $client->callTool($name, $arguments);

        return implode("\n\n", $contents);
    }
}
