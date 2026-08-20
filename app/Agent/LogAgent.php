<?php

namespace App\Agent;

use App\Client\AIClient;
use App\Client\MCPClient;
use Hyperf\Context\Context;
use Hyperf\Engine\Http\EventStream;
use Hyperf\HttpServer\Response;

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

    private const SSE_CONTEXT_KEY = 'logshare_sse_stream';

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
    public static function analyze(string $content, array $options = [], ?Response $response = null): void
    {
        $cacheKey = $options['cacheKey'] ?? null;
        $cacheTTL = $options['cacheTTL'] ?? 1800;
        $logId = $options['logId'] ?? null;

        self::startSSE($response);

        if ($cacheKey !== null) {
            $cached = self::checkCache($cacheKey);
            if ($cached !== null) {
                self::emitContent($cached);
                self::emitDone();
                return;
            }
        }

        $config = \App\Config::Get('ai');
        $agentConfig = $config['agent'] ?? [];
        $maxRounds = (int) ($agentConfig['maxToolRounds'] ?? self::DEFAULT_MAX_TOOL_ROUNDS);

        $tools = self::buildTools($config, $logId);
        $messages = self::buildMessages($content, $logId, $config, self::fetchTopics($config));

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
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'list_topics',
                    'description' => '列出内部知识库涵盖的主题与文档分布。在不知道检索方向、或搜索无结果时，先调用本工具了解知识库有什么，再针对性搜索。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ];
        }

        if ($logId !== null) {
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'list_log_files',
                    'description' => '列出当前日志 ID 下的所有文件（含主文件与附加文件）。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ],
                ],
            ];
            $tools[] = [
                'type' => 'function',
                'function' => [
                    'name' => 'read_log_file',
                    'description' => '读取当前日志下指定文件的指定行区间。主文件名为 main。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'filename' => ['type' => 'string', 'description' => '文件名（主文件为 main，或使用 list_log_files 列出的名称）'],
                            'start_line' => ['type' => 'number', 'description' => '起始行号（1 开始），默认 1'],
                            'end_line' => ['type' => 'number', 'description' => '结束行号，默认到文件末尾'],
                        ],
                        'required' => ['filename'],
                    ],
                ],
            ];
        }

        return $tools;
    }

    private static function buildMessages(string $content, ?string $logId, array $config, string $topicsText = ''): array
    {
        $system = $config['systemPrompt'] ?? self::defaultSystemPrompt($logId);

        if ($topicsText !== '') {
            $system .= "\n\n以下是你可检索的内部知识库所涵盖的主题（帮助判断检索方向，搜索前先浏览）：\n" . $topicsText;
        }

        $userContent = "需要分析的日志内容：\n\n" . self::truncateForModel($content);
        if (strlen($content) > self::MAX_TOOL_RESULT_BYTES) {
            $userContent .= "\n\n[日志内容过长已截断，如需要可用文件工具读取完整内容]";
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userContent],
        ];
    }

    /**
     * Fetch the knowledge base topic overview from the RAG server.
     *
     * Injects the topic map into the system prompt so the AI knows what the
     * knowledge base covers and can pick search directions accordingly.
     *
     * @param array $config
     * @return string Empty when RAG is not configured or unreachable
     */
    private static function fetchTopics(array $config): string
    {
        $mcp = $config['mcp'] ?? [];
        $endpoint = $mcp['rag'] ?? [];
        $url = $endpoint['url'] ?? '';

        if ($url === '') {
            return '';
        }

        try {
            $client = new MCPClient($url, is_array($endpoint['headers'] ?? null) ? $endpoint['headers'] : [], (int) ($endpoint['timeout'] ?? 10));
            $contents = $client->callTool('list_topics', []);
            return implode("\n\n", $contents);
        } catch (\Exception $e) {
            error_log("[LogAgent] 获取知识库主题失败: " . $e->getMessage());
            return '';
        }
    }

    private static function defaultSystemPrompt(?string $logId): string
    {
        $prompt = <<<PROMPT
你是一个专业的 Minecraft 服务器日志分析助手。你的任务是分析玩家提交的日志，定位问题并提供解决方案。

工作方式：
1. 首先分析日志内容，识别崩溃、错误、性能问题或异常。
2. 如需要更多信息，可以使用可用的工具（如搜索网络、检索知识库、查看日志文件）。
3. 若 rag_search 返回的内容被截断（出现省略号"…"或"已截断"标记），可用被截断处开头或末尾的关键词再次检索，以补全完整段落。
4. 给出结论时说明原因，并提供可行的解决步骤。

回答使用简体中文，结构清晰。全程禁止使用 emoji 或表情符号。
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

                case 'list_topics':
                    $endpoint = $mcp['rag'] ?? [];
                    return self::callMcpTool('list_topics', [], $endpoint);

                case 'list_log_files':
                    return self::listLogFiles($logId);

                case 'read_log_file':
                    return self::readLogFile($logId, $arguments);

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

    /**
     * List the files bound to the current log session.
     *
     * @param string|null $logId
     * @return string
     */
    private static function listLogFiles(?string $logId): string
    {
        if ($logId === null) {
            return '当前会话未绑定日志文件';
        }

        $log = self::loadSessionLog($logId);
        if ($log === null) {
            return '日志不存在: ' . $logId;
        }

        $lines = [
            "日志 {$logId} 文件列表：",
            sprintf('- main（主文件，%d 字节，%d 行）', $log->getSize(), $log->getLineNumbers()),
        ];
        foreach ($log->getFiles() as $file) {
            $lines[] = sprintf('- %s（%d 字节，%d 行）', $file['name'], $file['size'], $log->getFileLineNumbers($file['name']));
        }

        return implode("\n", $lines);
    }

    /**
     * Read a line range from a file bound to the current log session.
     *
     * @param string|null $logId
     * @param array $arguments
     * @return string
     */
    private static function readLogFile(?string $logId, array $arguments): string
    {
        if ($logId === null) {
            return '当前会话未绑定日志文件';
        }

        $log = self::loadSessionLog($logId);
        if ($log === null) {
            return '日志不存在: ' . $logId;
        }

        $filename = $arguments['filename'] ?? '';
        if ($filename === 'main' || $filename === '') {
            $content = $log->getContent();
        } else {
            $content = $log->getFile($filename);
            if ($content === null) {
                return '文件不存在: ' . $filename;
            }
        }

        $agentConfig = \App\Config::Get('ai')['agent'] ?? [];
        $maxLines = (int) ($agentConfig['maxFileLines'] ?? 500);
        $maxBytes = (int) ($agentConfig['maxFileBytes'] ?? 16 * 1024);

        $allLines = explode("\n", $content);
        $total = count($allLines);

        $startLine = max(1, (int) ($arguments['start_line'] ?? 1));
        $endLine = (int) ($arguments['end_line'] ?? $total);
        if ($endLine <= 0 || $endLine > $total) {
            $endLine = $total;
        }
        if ($endLine - $startLine + 1 > $maxLines) {
            $endLine = $startLine + $maxLines - 1;
        }

        $slice = array_slice($allLines, $startLine - 1, $endLine - $startLine + 1);
        $text = implode("\n", $slice);

        $truncatedBytes = false;
        if (strlen($text) > $maxBytes) {
            $text = mb_substr($text, 0, $maxBytes);
            $truncatedBytes = true;
        }

        $tail = '';
        if ($truncatedBytes || $endLine < $total) {
            $tail = "\n[已截断，可继续使用 read_log_file 传入 start_line 读取后续行]";
        }

        return sprintf(
            "文件 %s（共 %d 行）\n第 %d-%d 行：\n%s%s",
            $filename === '' ? 'main' : $filename,
            $total,
            $startLine,
            $endLine,
            $text,
            $tail
        );
    }

    /**
     * @param string|null $logId
     * @return \App\Log|null
     */
    private static function loadSessionLog(?string $logId): ?\App\Log
    {
        $id = new \App\Id($logId);
        $log = new \App\Log($id);
        return $log->exists() ? $log : null;
    }

    private static function truncateForModel(string $text): string
    {
        if (strlen($text) <= self::MAX_TOOL_RESULT_BYTES) {
            return $text;
        }
        return mb_substr($text, 0, self::MAX_TOOL_RESULT_BYTES);
    }

    /* ─── SSE emission ─────────────────────────────────────── */

    private static function startSSE(?Response $response): void
    {
        Context::set(self::SSE_CONTEXT_KEY, null);

        if ($response !== null) {
            $connection = $response->getConnection();
            if ($connection !== null) {
                Context::set(self::SSE_CONTEXT_KEY, new EventStream($connection, $response));
                return;
            }
        }

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

    private static function write(string $data): void
    {
        $stream = Context::get(self::SSE_CONTEXT_KEY);
        if ($stream instanceof EventStream) {
            $stream->write($data);
        } else {
            echo $data;
            flush();
        }
    }

    private static function emitContent(string $delta): void
    {
        self::write("data: " . json_encode(['choices' => [['delta' => ['content' => $delta]]]], JSON_UNESCAPED_UNICODE) . "\n\n");
    }

    private static function emitThinking(string $reasoning): void
    {
        self::write("event: status\ndata: " . json_encode(['type' => 'thinking', 'delta' => $reasoning], JSON_UNESCAPED_UNICODE) . "\n\n");
    }

    private static function emitTool(string $name, array $arguments): void
    {
        self::write("event: status\ndata: " . json_encode(['type' => 'tool', 'name' => $name, 'arguments' => $arguments], JSON_UNESCAPED_UNICODE) . "\n\n");
    }

    private static function emitToolResult(string $name, string $result): void
    {
        $summary = mb_substr($result, 0, self::STATUS_SUMMARY_BYTES);
        self::write("event: status\ndata: " . json_encode([
            'type' => 'tool_result',
            'name' => $name,
            'summary' => $summary,
            'truncated' => strlen($result) > self::STATUS_SUMMARY_BYTES,
        ], JSON_UNESCAPED_UNICODE) . "\n\n");
    }

    private static function emitLimit(int $rounds): void
    {
        self::write("event: status\ndata: " . json_encode(['type' => 'limit', 'rounds' => $rounds], JSON_UNESCAPED_UNICODE) . "\n\n");
    }

    private static function emitDone(): void
    {
        self::write("event: done\ndata: {\"status\":\"completed\"}\n\n");
        self::end();
    }

    private static function emitError(string $message): void
    {
        self::write("event: error\ndata: " . json_encode(['error' => $message], JSON_UNESCAPED_UNICODE) . "\n\n");
        self::end();
    }

    private static function end(): void
    {
        $stream = Context::get(self::SSE_CONTEXT_KEY);
        if ($stream instanceof EventStream) {
            $stream->end();
            Context::set(self::SSE_CONTEXT_KEY, null);
        }
    }

    /* ─── Cache ────────────────────────────────────────────── */

    private static function checkCache(string $cacheKey): ?string
    {
        try {
            $cached = \App\Cache\RedisCache::Get($cacheKey);
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
            \App\Cache\RedisCache::Set($cacheKey, $content, $cacheTTL);
        } catch (\Exception $e) {
            error_log("[LogAgent Cache] 写入失败: " . $e->getMessage());
        }
    }
}