<?php

namespace Agent;

use Client\AIClient;
use Client\MCPClient;

/**
 * LogAgent: model-driven tool loop for log analysis.
 *
 * Orchestrates an LLM chat completion loop where the model can call tools
 * (web search, RAG search, log file access). The whole flow is streamed
 * back to the client as SSE, including thinking traces and tool events.
 */
class LogAgent
{
    public const DEFAULT_MAX_TOOL_ROUNDS = 3;
    private const MAX_TOOL_RESULT_BYTES = 12000;
    private const STATUS_SUMMARY_BYTES = 400;

    /**
     * Run the agent loop and stream the result as SSE.
     *
     * @param string $content The log content to analyse
     * @param array $options Supported keys:
     *                       - cacheKey: string|null
     *                       - cacheTTL: int
     *                       - logId: string|null (bound log id enabling file tools)
     * @return void
     */
    public static function analyze(string $content, array $options = []): void
    {
        $cacheKey = $options['cacheKey'] ?? null;
        $cacheTTL = $options['cacheTTL'] ?? 1800;
        $logId = $options['logId'] ?? null;

        if ($cacheKey !== null) {
            $cached = self::checkCache($cacheKey);
            if ($cached !== null) {
                self::startSSE();
                self::emitContent($cached);
                self::emitDone();
                return;
            }
        }

        $config = \Config::Get('ai') ?? [];
        $agentConfig = $config['agent'] ?? [];
        $maxRounds = (int) ($agentConfig['maxToolRounds'] ?? self::DEFAULT_MAX_TOOL_ROUNDS);

        $tools = self::buildTools($config, $logId);
        $messages = self::buildMessages($content, $logId, $config);

        self::startSSE();

        $fullAnswer = '';
        $success = false;

        try {
            for ($round = 0; $round < $maxRounds; $round++) {
                $roundToolCalls = [];
                $roundContent = '';

                AIClient::streamChat(
                    $messages,
                    $tools,
                    function (string $delta) use (&$roundContent) {
                        $roundContent .= $delta;
                        self::emitContent($delta);
                    },
                    function (string $reasoning) {
                        self::emitThinking($reasoning);
                    },
                    function (array $toolCalls) use (&$roundToolCalls) {
                        $roundToolCalls = $toolCalls;
                    },
                    function (string $fullContent) use (&$roundContent) {
                        $roundContent = $fullContent;
                    }
                );

                $fullAnswer .= $roundContent;

                if (empty($roundToolCalls)) {
                    $success = true;
                    break;
                }

                $messages[] = self::assistantMessageWithToolCalls($roundToolCalls);

                foreach ($roundToolCalls as $call) {
                    $name = $call['name'] ?? '';
                    $arguments = json_decode($call['arguments'] ?? '', true);
                    if (!is_array($arguments)) {
                        $arguments = [];
                    }

                    self::emitTool($name, $arguments);

                    $result = self::executeTool($name, $arguments, $config, $logId);
                    self::emitToolResult($name, $result);

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $call['id'] ?? '',
                        'content' => self::truncateForModel($result),
                    ];
                }
            }

            if (!$success) {
                self::emitLimit($maxRounds);
            }

            if ($cacheKey !== null && $fullAnswer !== '') {
                self::writeCache($cacheKey, $fullAnswer, $cacheTTL);
            }

            self::emitDone();
        } catch (\Exception $e) {
            self::emitError($e->getMessage());
        }
    }

    private static function buildTools(array $config, ?string $logId): array
    {
        $tools = [];
        $mcp = $config['mcp'] ?? [];

        if (!empty($mcp['webSearch']['url'])) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'web_search_exa',
                    'description' => '搜索互联网，查找 Minecraft 报错信息、mod 兼容性等解决方案。返回与查询相关的网页内容。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => '搜索关键词，使用错误类名或报错关键词'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ];
        }

        if (!empty($mcp['rag']['url'])) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'rag_search',
                    'description' => '在内部知识库中检索相关文档片段。用于查找已知错误与解决方案。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => '检索关键词'],
                            'k' => ['type' => 'number', 'description' => '返回片段数量，默认 5'],
                        ],
                        'required' => ['query'],
                    ],
                ],
            ];
        }

        return $tools;
    }

    private static function buildMessages(string $content, ?string $logId, array $config): array
    {
        $system = $config['systemPrompt'] ?? self::defaultSystemPrompt($logId);

        $userContent = "需要分析的日志内容：\n\n" . self::truncateForModel($content);
        if (strlen($content) > self::MAX_TOOL_RESULT_BYTES) {
            $userContent .= "\n\n[日志内容过长已截断，如需要可用文件工具读取完整内容]";
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userContent],
        ];
    }

    private static function defaultSystemPrompt(?string $logId): string
    {
        $prompt = <<<PROMPT
你是一个专业的 Minecraft 服务器日志分析助手。你的任务是分析玩家提交的日志，定位问题并提供解决方案。

工作方式：
1. 首先分析日志内容，识别崩溃、错误、性能问题或异常。
2. 如需要更多信息，可以使用可用的工具（如搜索网络、检索知识库、查看日志文件）。
3. 给出结论时说明原因，并提供可行的解决步骤。

回答使用简体中文，结构清晰。
PROMPT;

        if ($logId !== null) {
            $prompt .= "\n\n你正在分析的日志 ID 是 {$logId}。你可以使用 list_log_files 查看该日志下的文件列表，使用 read_log_file 读取文件内容进行对比分析。";
        }

        return $prompt;
    }

    private static function assistantMessageWithToolCalls(array $toolCalls): array
    {
        $formatted = [];
        foreach ($toolCalls as $call) {
            $formatted[] = [
                'id' => $call['id'] ?? '',
                'type' => 'function',
                'function' => [
                    'name' => $call['name'] ?? '',
                    'arguments' => $call['arguments'] ?? '',
                ],
            ];
        }

        return [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => $formatted,
        ];
    }

    private static function executeTool(string $name, array $arguments, array $config, ?string $logId): string
    {
        $mcp = $config['mcp'] ?? [];

        try {
            switch ($name) {
                case 'web_search_exa':
                    $endpoint = $mcp['webSearch'] ?? [];
                    return self::callMcpTool('web_search_exa', $arguments, $endpoint);

                case 'rag_search':
                    $endpoint = $mcp['rag'] ?? [];
                    return self::callMcpTool('rag_search', $arguments, $endpoint);

                default:
                    return '未知工具: ' . $name;
            }
        } catch (\Exception $e) {
            return '工具调用失败: ' . $e->getMessage();
        }
    }

    private static function callMcpTool(string $name, array $arguments, array $endpoint): string
    {
        $url = $endpoint['url'] ?? '';
        $headers = $endpoint['headers'] ?? [];
        $timeout = (int) ($endpoint['timeout'] ?? 30);

        if ($url === '') {
            return '该工具未配置，无法调用';
        }

        $client = new MCPClient($url, is_array($headers) ? $headers : [], $timeout);
        $contents = $client->callTool($name, $arguments);

        return implode("\n\n", $contents);
    }

    private static function truncateForModel(string $text): string
    {
        if (strlen($text) <= self::MAX_TOOL_RESULT_BYTES) {
            return $text;
        }
        return mb_substr($text, 0, self::MAX_TOOL_RESULT_BYTES);
    }

    /* ─── SSE emission ─────────────────────────────────────── */

    private static function startSSE(): void
    {
        if (PHP_SAPI !== 'cli') {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
        }

        if (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    private static function emitContent(string $delta): void
    {
        echo "data: " . json_encode(['choices' => [['delta' => ['content' => $delta]]]], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    private static function emitThinking(string $reasoning): void
    {
        echo "event: status\ndata: " . json_encode(['type' => 'thinking', 'delta' => $reasoning], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    private static function emitTool(string $name, array $arguments): void
    {
        echo "event: status\ndata: " . json_encode(['type' => 'tool', 'name' => $name, 'arguments' => $arguments], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    private static function emitToolResult(string $name, string $result): void
    {
        $summary = mb_substr($result, 0, self::STATUS_SUMMARY_BYTES);
        echo "event: status\ndata: " . json_encode([
            'type' => 'tool_result',
            'name' => $name,
            'summary' => $summary,
            'truncated' => strlen($result) > self::STATUS_SUMMARY_BYTES,
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    private static function emitLimit(int $rounds): void
    {
        echo "event: status\ndata: " . json_encode(['type' => 'limit', 'rounds' => $rounds], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    private static function emitDone(): void
    {
        echo "event: done\ndata: {\"status\":\"completed\"}\n\n";
        flush();
    }

    private static function emitError(string $message): void
    {
        echo "event: error\ndata: " . json_encode(['error' => $message], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    /* ─── Cache ────────────────────────────────────────────── */

    private static function checkCache(string $cacheKey): ?string
    {
        try {
            $cached = \Cache\RedisCache::Get($cacheKey);
            if ($cached !== null && $cached !== '') {
                return $cached;
            }
        } catch (\Exception $e) {
            error_log("[LogAgent Cache] 读取失败: " . $e->getMessage());
        }
        return null;
    }

    private static function writeCache(string $cacheKey, string $content, int $cacheTTL): void
    {
        try {
            \Cache\RedisCache::Set($cacheKey, $content, $cacheTTL);
        } catch (\Exception $e) {
            error_log("[LogAgent Cache] 写入失败: " . $e->getMessage());
        }
    }
}