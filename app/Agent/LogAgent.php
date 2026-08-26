<?php

namespace App\Agent;

use App\Client\AIClient;
use App\Client\MCPClient;
use App\Sse\SseWriter;
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
    private const MAX_RETRIEVAL_RESULT_BYTES = 32000;
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
    public static function analyze(string $content, array $options = [], ?Response $response = null): void
    {
        $cacheKey = $options['cacheKey'] ?? null;
        $cacheTTL = $options['cacheTTL'] ?? 1800;
        $logId = $options['logId'] ?? null;

        SseWriter::begin($response);

        // try 边界紧贴 begin()：SSE 开始输出后任何异常都必须以流内 error 收尾，
        // 不能逃逸到全局 JSON handler 造成坏帧。
        try {
            $cacheLock = null;
            if ($cacheKey !== null) {
                $cached = self::checkCache($cacheKey);
                if ($cached !== null) {
                    self::emitContent($cached);
                    self::emitDone();
                    return;
                }
                $cacheLock = self::acquireCacheLock($cacheKey);
                if ($cacheLock === null) {
                    $cached = self::waitForCache($cacheKey);
                    if ($cached !== null) {
                        self::emitContent($cached);
                        self::emitDone();
                        return;
                    }
                }
            }

            $config = \App\Config::Get('ai');
            $agentConfig = $config['agent'] ?? [];
            $maxRounds = (int) ($agentConfig['maxToolRounds'] ?? self::DEFAULT_MAX_TOOL_ROUNDS);

            // 会话级可变状态：MCP 客户端复用 + 已读文件记录（防重复读取循环）
            $session = new ToolSession();
            $tools = self::buildTools($config, $logId);
            $messages = self::buildMessages($content, $logId, $config, self::fetchTopics($config, $session));

            $fullAnswer = '';
            $success = false;

            for ($round = 0; $round < $maxRounds; $round++) {
                $roundToolCalls = [];
                $roundReasoning = '';
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
                    function (array $toolCalls, string $reasoning) use (&$roundToolCalls, &$roundReasoning) {
                        $roundToolCalls = $toolCalls;
                        $roundReasoning = $reasoning;
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

                // 双保险：空 name 的调用回传上游会被 400 拒绝；全部无效则视为本轮完成
                $roundToolCalls = array_values(
                    array_filter($roundToolCalls, fn($call) => !empty($call['name']))
                );
                if (empty($roundToolCalls)) {
                    $success = true;
                    break;
                }

                $messages[] = self::assistantMessageWithToolCalls($roundToolCalls, $roundReasoning);

                foreach ($roundToolCalls as $call) {
                    $name = $call['name'] ?? '';
                    $arguments = json_decode($call['arguments'] ?? '', true);
                    if (!is_array($arguments)) {
                        $arguments = [];
                    }

                    self::emitTool($name, $arguments);

                    $result = self::executeTool($name, $arguments, $config, $logId, $session);
                    self::emitToolResult($name, $result);

                    // read_log_file 的全文结果已在 readLogFile 内按 maxFileBytes 截断并附提示，
                    // 不再套用通用工具的 12KB 截断（否则「一次读全」会被无声砍成残篇）；
                    // 检索类工具（rag_search/web_search_exa）是分析的核心证据，放宽到 32KB；
                    // 其余工具保留 12000 字节上限，超限时附带可见标记。
                    $toolContent = match ($name) {
                        'read_log_file' => $result,
                        'rag_search', 'web_search_exa' => self::truncateForModel($result, self::MAX_RETRIEVAL_RESULT_BYTES),
                        default => self::truncateForModel($result),
                    };

                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $call['id'] ?? '',
                        'content' => $toolContent,
                    ];
                }
            }

            if (!$success) {
                self::emitLimit($maxRounds);
            }

            if ($cacheKey !== null && $fullAnswer !== '') {
                self::writeCache($cacheKey, $fullAnswer, $cacheTTL);
                self::releaseCacheLock($cacheKey);
            }

            self::emitDone();
        } catch (\Throwable $e) {
            \App\Syslog::error('LogAgent', '分析失败: ' . $e->getMessage());
            self::emitError('AI service temporarily unavailable.');
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
                    'description' => '读取当前日志下指定文件的内容。默认返回完整文件；需要控制范围时可使用 line_start/line_end 指定行区间，或使用 offset/max_bytes 指定字节区间。主文件名为 main。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'filename' => ['type' => 'string', 'description' => '文件名（主文件为 main，或使用 list_log_files 列出的名称）'],
                            'line_start' => ['type' => 'integer', 'description' => '起始行号，从 1 开始；省略则从第 1 行开始'],
                            'line_end' => ['type' => 'integer', 'description' => '结束行号，包含该行；省略则读取到文件末尾'],
                            'offset' => ['type' => 'integer', 'description' => '字节起始位置；使用行区间时不要设置'],
                            'max_bytes' => ['type' => 'integer', 'description' => '字节读取模式下的最大字节数；使用行区间时不要设置'],
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

        $system .= "\n\n强制执行规则（优先级高于其他分析习惯）：\n"
            . "- 任何日志分析都必须先调用一次 rag_search；不得仅凭日志内容直接给出最终结论。\n"
            . "- rag_search 返回无关或无结果时，必须换用错误关键词、异常类名、模组名或版本号再次调用。\n"
            . "- 内部知识库仍无法确认时，必须调用 web_search_exa；只有检索完成后才能输出最终分析。\n"
            . "- list_topics 仅用于了解知识库范围，不能替代 rag_search。\n"
            . "- read_log_file 返回的主日志和附加日志均已经过与上传主日志相同的脱敏过滤；不得声称附加日志未脱敏，也不得要求用户重新提供其中的敏感信息。";

        if ($topicsText !== '') {
            $system .= "\n\n以下是你可检索的内部知识库所涵盖的主题（帮助判断检索方向）：\n" . $topicsText;
        }

        // 用户消息截断使用专属提示（不用工具结果的标记文案）
        $userContent = "需要分析的日志内容：\n\n" . $content;
        if (strlen($content) > self::MAX_TOOL_RESULT_BYTES) {
            $userContent = "需要分析的日志内容：\n\n"
                . mb_strcut($content, 0, self::MAX_TOOL_RESULT_BYTES)
                . "\n\n[日志内容过长已截断，如需要可用文件工具读取完整内容]";
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
     * @param ToolSession $session
     * @return string Empty when RAG is not configured or unreachable
     */
    private static function fetchTopics(array $config, ToolSession $session): string
    {
        $mcp = $config['mcp'] ?? [];
        $endpoint = $mcp['rag'] ?? [];
        $url = $endpoint['url'] ?? '';

        if ($url === '') {
            return '';
        }

        try {
            $client = self::mcpClient($endpoint, $session);
            $contents = $client->callTool('list_topics', []);
            return implode("\n\n", $contents);
        } catch (\Exception $e) {
            \App\Syslog::error('LogAgent', '获取知识库主题失败: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Return a shared, already-initialized MCPClient for the endpoint.
     *
     * Clients are cached per url within a single analyze() call, so repeated
     * tool invocations skip the initialize handshake round-trip. The cache is
     * request-scoped (held on the ToolSession), never shared across requests.
     *
     * @param array $endpoint
     * @param ToolSession $session
     */
    private static function mcpClient(array $endpoint, ToolSession $session): MCPClient
    {
        $url = (string) ($endpoint['url'] ?? '');
        if (!isset($session->mcpClients[$url])) {
            $headers = is_array($endpoint['headers'] ?? null) ? $endpoint['headers'] : [];
            // 内置 RAG 配置了 authToken 时随请求传递，保证自调用通过 /rag 的鉴权
            if (($endpoint['authToken'] ?? '') !== '') {
                $headers[] = 'Authorization: Bearer ' . $endpoint['authToken'];
            }
            $timeout = (int) ($endpoint['timeout'] ?? 30);
            $session->mcpClients[$url] = new MCPClient($url, $headers, $timeout);
        }
        return $session->mcpClients[$url];
    }

    private static function defaultSystemPrompt(?string $logId): string
    {
        $prompt = <<<PROMPT
你是一个专业的 Minecraft 服务器日志分析助手。你的任务是分析玩家提交的日志，定位问题并提供解决方案。

工作方式：
1. 如需查看日志文件，先用 `list_log_files` 查看有哪些文件，然后调用 `read_log_file`。默认不传范围参数以读取完整文件；需要聚焦局部内容时，由你传入 `line_start` 和 `line_end` 指定行区间。超大内容需要续读时，使用返回的 `next_offset`。
2. 调用 `rag_search` 之前，必须先调用 `list_topics` 了解知识库涵盖的主题与文档分布，据此选择贴合知识库的关键词检索；首次检索无结果时也应回看主题列表换词重试。知识库查不到的公开问题，再用 `web_search_exa` 搜索网络。
3. 若知识库检索结果被截断（出现"…"或"已截断"标记），基于被截断处再次检索补全，不需要重复读取文件。

重要停止规则：
- 不要在已经有完整日志内容的情况下再次调用 `read_log_file`，重复调用会被拒绝并浪费预算。
- 严禁使用相同的 `read_log_file` 参数调用两次；已读取内容可直接用于分析。
- 当某一个工具调用能覆盖全部问题时，不要再发起新的工具调用；应直接给出结论。

回答使用简体中文，结构清晰。全程禁止使用 emoji 或表情符号。
PROMPT;

        if ($logId !== null) {
            $prompt .= "\n\n你正在分析的日志 ID 是 {$logId}。你可以使用 list_log_files 查看该日志下的文件列表，使用 read_log_file 读取文件内容进行对比分析。";
        }

        return $prompt;
    }

    /**
     * Build the assistant message carrying this round's tool calls.
     *
     * Reasoning models (DeepSeek thinking mode 等) require the round's
     * reasoning_content to be passed back verbatim with the assistant message;
     * omitting it makes the upstream reject the follow-up request with 400.
     *
     * @param array $toolCalls
     * @param string $reasoningContent
     * @return array
     */
    private static function assistantMessageWithToolCalls(array $toolCalls, string $reasoningContent = ''): array
    {
        $formatted = [];
        foreach ($toolCalls as $call) {
            $formatted[] = [
                'id' => $call['id'] ?? '',
                'type' => 'function',
                // 空 arguments 部分网关会 400 拒绝，无参调用归一为 {}
                'function' => [
                    'name' => $call['name'] ?? '',
                    'arguments' => ($call['arguments'] ?? '') !== '' ? $call['arguments'] : '{}',
                ],
            ];
        }

        $message = [
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => $formatted,
        ];
        if ($reasoningContent !== '') {
            $message['reasoning_content'] = $reasoningContent;
        }

        return $message;
    }

    /**
     * @param string $name
     * @param array $arguments
     * @param array $config
     * @param string|null $logId
     * @param ToolSession $session
     * @return string
     */
    private static function executeTool(string $name, array $arguments, array $config, ?string $logId, ToolSession $session): string
    {
        $mcp = $config['mcp'] ?? [];

        try {
            switch ($name) {
                case 'web_search_exa':
                    $endpoint = $mcp['webSearch'] ?? [];
                    return self::callMcpTool('web_search_exa', $arguments, $endpoint, $session);

                case 'rag_search':
                    $endpoint = $mcp['rag'] ?? [];
                    return self::callMcpTool('rag_search', $arguments, $endpoint, $session);

                case 'list_topics':
                    $endpoint = $mcp['rag'] ?? [];
                    return self::callMcpTool('list_topics', [], $endpoint, $session);

                case 'list_log_files':
                    return self::listLogFiles($logId);

                case 'read_log_file':
                    return self::readLogFile($logId, $arguments, $session);

                default:
                    return '未知工具: ' . $name;
            }
        } catch (\Exception $e) {
            return '工具调用失败: ' . $e->getMessage();
        }
    }

    private static function callMcpTool(string $name, array $arguments, array $endpoint, ToolSession $session): string
    {
        $url = $endpoint['url'] ?? '';

        if ($url === '') {
            return '该工具未配置，无法调用';
        }

        $client = self::mcpClient($endpoint, $session);
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
     * Read the full content of a file bound to the current log session.
     *
     * Deliberately returns the whole file (no line-range parameters): fence-off
     * reads are the main source of repeated tool calls. Duplicate reads of the
     * same file within a single analyze() session are blocked via the session.
     *
     * @param string|null $logId
     * @param array $arguments
     * @param ToolSession $session
     * @return string
     */
    private static function readLogFile(?string $logId, array $arguments, ToolSession $session): string
    {
        if ($logId === null) {
            return '当前会话未绑定日志文件';
        }

        $log = self::loadSessionLog($logId);
        if ($log === null) {
            return '日志不存在: ' . $logId;
        }

        $filename = $arguments['filename'] ?? '';
        // 会话去重键归一化：'' 与 'main' 指向同一主文件，必须视为同键，
        // 否则省略 filename 的重复调用会绕过防重复拦截
        $sessionKey = ($filename === '' || $filename === 'main') ? 'main' : $filename;

        if ($sessionKey === 'main') {
            $content = $log->getContent();
        } else {
            $content = $log->getFile($filename);
            if ($content === null) {
                return '文件不存在: ' . $filename;
            }
        }

        $length = strlen($content);
        $total = substr_count($content, "\n") + 1;
        $hasLineRange = isset($arguments['line_start']) || isset($arguments['line_end']);

        if ($hasLineRange) {
            $lineStart = max(1, (int) ($arguments['line_start'] ?? 1));
            $lineEnd = isset($arguments['line_end']) ? max($lineStart, (int) $arguments['line_end']) : $total;
            $lines = explode("\n", $content);
            $text = implode("\n", array_slice($lines, $lineStart - 1, $lineEnd - $lineStart + 1));
            $session->readFiles[$sessionKey] = true;
            return sprintf(
                "文件 %s（共 %d 行，%d 字节；本次行区间=%d-%d）\n内容：\n%s",
                $sessionKey,
                $total,
                $length,
                $lineStart,
                min($lineEnd, $total),
                $text
            );
        }

        $offset = max(0, (int) ($arguments['offset'] ?? 0));
        if ($offset >= $length) {
            return sprintf('文件 %s 已读取完毕（文件总大小 %d 字节，next_offset=%d）。', $sessionKey, $length, $length);
        }

        $maxBytes = isset($arguments['max_bytes']) ? max(1024, (int) $arguments['max_bytes']) : $length - $offset;
        $text = mb_strcut(substr($content, $offset), 0, $maxBytes);
        $nextOffset = $offset + strlen($text);
        $session->readFiles[$sessionKey] = true;
        $tail = $nextOffset < $length
            ? "\n[内容已截断；请使用 offset={$nextOffset} 继续读取，next_offset={$nextOffset}]"
            : "\n[文件已读取完毕，next_offset={$nextOffset}]";

        return sprintf(
            "文件 %s（共 %d 行，%d 字节；本次 offset=%d）\n内容：\n%s%s",
            $sessionKey, $total, $length, $offset, $text, $tail
        );
    }

    /**
     * Response for duplicate read attempts within a single session.
     */
    private static function duplicateReadNotice(string $filename, string $content): string
    {
        $total = substr_count($content, "\n") + 1;
        return sprintf(
            '文件 %s 已读取（共 %d 行，%d 字节），其内容已在上文中提供，请直接基于已有内容进行分析，不要重复调用本工具。',
            $filename,
            $total,
            strlen($content)
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

    /**
     * Byte-bounded truncation that never splits a multi-byte character
     * (mb_strcut counts bytes but cuts on character boundaries).
     *
     * Truncation always appends a visible marker so the model knows the result
     * is incomplete and can decide to re-query instead of reasoning over a
     * silently truncated payload.
     */
    private static function truncateForModel(string $text, int $maxBytes = self::MAX_TOOL_RESULT_BYTES): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }
        return mb_strcut($text, 0, $maxBytes)
            . "\n\n[...工具结果过长，已截断至 {$maxBytes} 字节；如需更多细节，请调整参数后重新调用]";
    }

    /* ─── SSE emission ─────────────────────────────────────── */

    private static function emitContent(string $delta): void
    {
        SseWriter::write("data: " . json_encode(['choices' => [['delta' => ['content' => $delta]]]], JSON_UNESCAPED_UNICODE) . "\n\n");
    }

    private static function emitThinking(string $reasoning): void
    {
        SseWriter::write("event: status\ndata: " . json_encode(['type' => 'thinking', 'delta' => $reasoning], JSON_UNESCAPED_UNICODE) . "\n\n");
    }

    private static function emitTool(string $name, array $arguments): void
    {
        SseWriter::write("event: status\ndata: " . json_encode(['type' => 'tool', 'name' => $name, 'arguments' => $arguments], JSON_UNESCAPED_UNICODE) . "\n\n");
    }

    private static function emitToolResult(string $name, string $result): void
    {
        $summary = match ($name) {
            'read_log_file', 'list_log_files', 'list_topics' => self::buildCompactSummary($name, $result),
            'rag_search' => self::buildHitListSummary($result),
            default => mb_strcut($result, 0, self::STATUS_SUMMARY_BYTES),
        };

        SseWriter::write("event: status\ndata: " . json_encode([
            'type' => 'tool_result',
            'name' => $name,
            'summary' => $summary,
            'truncated' => strlen($result) > strlen($summary),
        ], JSON_UNESCAPED_UNICODE) . "\n\n");
    }

    /**
     * Compact summaries for tools whose full output is meaningless to the user:
     * read_log_file 的原文是给模型的，用户只需知道「读了哪个文件、多少行」；
     * list_topics 只需知道知识库覆盖哪些主题目录。
     */
    private static function buildCompactSummary(string $tool, string $result): string
    {
        $lines = explode("\n", $result);

        if ($tool === 'read_log_file') {
            // 首行即概要：「文件 main（共 N 行，M 字节）」或「文件 X 已读取…」
            $summary = trim($lines[0]);
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), '[文件过大已截断')) {
                    $summary .= "\n" . trim($line);
                }
            }
            return $summary;
        }

        if ($tool === 'list_topics') {
            // 首行统计 + 各主题目录行（跳过每目录下的文件示例明细）
            $head = trim($lines[0]);
            foreach ($lines as $line) {
                $trim = trim($line);
                if (str_starts_with($trim, '■')) {
                    $head .= "\n" . $trim;
                }
            }
            return mb_strcut($head !== '' ? $head : $result, 0, 1200);
        }

        // list_log_files 本身已是紧凑的文件清单
        return mb_strcut($result, 0, self::STATUS_SUMMARY_BYTES);
    }

    /**
     * Hit-list summary for multi-document results (rag_search 等) so the UI
     * shows every matched document instead of the first one's body prefix —
     * a plain 400-char cut made it look like only one document came back.
     */
    private static function buildHitListSummary(string $result): string
    {
        if (preg_match('/^在知识库中找到\s*(\d+)\s*条相关文档/u', $result, $countMatch)) {
            // 标题段 (.+?) 允许包含全角括号等字符，靠行尾锚定与「（来源: …）」收尾定位，
            // 否则标题自带括号的条目会被整条丢弃，出现「命中 5 条只列出 2 条」
            preg_match_all('/^\[(\d+)\]\s*(.+?)（来源:\s*([^）]+)）\s*$/mu', $result, $hits, PREG_SET_ORDER);
            if ($hits !== []) {
                $lines = ['共命中 ' . $countMatch[1] . ' 条：'];
                foreach ($hits as $hit) {
                    $lines[] = sprintf('[%s] %s（%s）', $hit[1], trim($hit[2]), trim($hit[3]));
                }
                // 清单需要容纳全部命中条目，放宽到 3000 字符
                return mb_strcut(implode("\n", $lines), 0, 3000);
            }
        }

        return mb_strcut($result, 0, self::STATUS_SUMMARY_BYTES);
    }

    private static function emitLimit(int $rounds): void
    {
        SseWriter::write("event: status\ndata: " . json_encode(['type' => 'limit', 'rounds' => $rounds], JSON_UNESCAPED_UNICODE) . "\n\n");
    }

    private static function emitDone(): void
    {
        SseWriter::write("event: done\ndata: {\"status\":\"completed\"}\n\n");
        SseWriter::end();
    }

    private static function emitError(string $message): void
    {
        SseWriter::write("event: error\ndata: " . json_encode(['error' => $message], JSON_UNESCAPED_UNICODE) . "\n\n");
        SseWriter::end();
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
            \App\Syslog::error('LogAgent Cache', '读取失败: ' . $e->getMessage());
        }
        return null;
    }

    private static function acquireCacheLock(string $cacheKey): ?string
    {
        try {
            $lock = $cacheKey . ':lock:' . bin2hex(random_bytes(6));
            return \App\Cache\RedisCache::Acquire($cacheKey . ':lock', 120) ? $lock : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function releaseCacheLock(string $cacheKey): void
    {
        try {
            \App\Cache\RedisCache::Delete($cacheKey . ':lock');
        } catch (\Throwable $e) {
        }
    }

    private static function waitForCache(string $cacheKey): ?string
    {
        for ($i = 0; $i < 10; $i++) {
            usleep(100000);
            $cached = self::checkCache($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }
        return null;
    }

    private static function writeCache(string $cacheKey, string $content, int $cacheTTL): void
    {
        try {
            \App\Cache\RedisCache::Set($cacheKey, $content, $cacheTTL);
        } catch (\Exception $e) {
            \App\Syslog::error('LogAgent Cache', '写入失败: ' . $e->getMessage());
        }
    }
}