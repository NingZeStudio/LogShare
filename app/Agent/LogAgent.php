<?php

namespace App\Agent;

use App\Client\AIClient;
use App\Client\MCPClient;
use App\Sse\AnalysisEmitter;
use App\Sse\SseEmitter;
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
    public const DEFAULT_MAX_TOOL_ROUNDS = 50;
    private const MAX_TOOL_RESULT_BYTES = 12000;
    private const MAX_RETRIEVAL_RESULT_BYTES = 32000;
    private const STATUS_SUMMARY_BYTES = 400;

    /**
     * 检索预算的代码兜底（与提示词「检索策略」段的数字保持一致）：
     * web_search_exa 是外部网络调用成本最高，超限硬拦截；
     * rag_search 本地 FTS 成本低，合计达阈值只注入收敛提示（软）。
     */
    private const MAX_WEB_SEARCH_CALLS = 5;
    private const MAX_TOTAL_RETRIEVAL_CALLS = 6;

    /** SSE 帧统一 JSON 编码 flags：非法 UTF-8 时替换为 U+FFFD，避免 json_encode 返回 false 产生空帧 */
    private const SSE_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /** fetchTopics 的进程级缓存：topics 仅随知识库重建变化，短 TTL 内直接复用 */
    private static ?string $topicsCache = null;
    private static int $topicsCacheExpiresAt = 0;

    /** 当前协程的事件发射端（SSE 直写或 Redis Stream），随协程上下文销毁 */
    private const EMITTER_CTX = 'logshare_analysis_emitter';

    /**
     * Run the agent loop and stream the result as SSE.
     *
     * @param string $content The log content to analyse
     * @param array $options Supported keys:
     *                       - cacheKey: string|null
     *                       - cacheTTL: int
     *                       - logId: string|null (bound log id enabling file tools)
     *                       - emitter: AnalysisEmitter|null (default: SseEmitter, direct SSE write)
     * @return void
     */
    public static function analyze(string $content, array $options = [], ?Response $response = null): void
    {
        $cacheKey = $options['cacheKey'] ?? null;
        $cacheTTL = $options['cacheTTL'] ?? 1800;
        $logId = $options['logId'] ?? null;

        // 发射端绑定在协程级 Context（常驻进程下静态会被并发请求串扰，与 SseWriter 同模式）
        $emitter = $options['emitter'] ?? new SseEmitter($response);
        \Hyperf\Context\Context::set(self::EMITTER_CTX, $emitter);
        $emitter->begin();

        // try 边界紧贴 begin()：SSE 开始输出后任何异常都必须以流内 error 收尾，
        // 不能逃逸到全局 JSON handler 造成坏帧。
        // $heldLock 记录本协程持有的缓存锁 key，finally 中无条件释放（幂等），
        // 覆盖异常与提前 return 路径，避免锁只能等 TTL 过期、互斥失效。
        $heldLock = null;
        try {
            if ($cacheKey !== null) {
                $cached = self::checkCache($cacheKey);
                if ($cached !== null) {
                    self::emitContent($cached);
                    self::emitDone();
                    return;
                }
                if (self::acquireCacheLock($cacheKey)) {
                    $heldLock = $cacheKey;
                } else {
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
            }

            self::emitDone();
        } catch (\App\Exception\ClientDisconnectedException $e) {
            // 客户端已断开：SseWriter 无法再写入任何帧，仅记日志并中止，
            // 不再尝试 emitError（同样会写失败）
            \App\Syslog::error('LogAgent', '客户端已断开，分析中止: ' . $e->getMessage());
        } catch (\Throwable $e) {
            \App\Syslog::error('LogAgent', '分析失败: ' . $e->getMessage());
            self::emitError($e->getMessage());
        } finally {
            if ($heldLock !== null) {
                self::releaseCacheLock($heldLock);
            }
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
                    'description' => '搜索互联网，查找知识库未覆盖的公开问题：新版本 mod/服务端兼容性、小众报错、官方公告等。'
                        . '知识库检索无果后再使用；查询词与 rag_search 相同，使用错误类名或报错关键词原文。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => '搜索关键词，使用错误类名或报错关键词原文'],
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
                    'description' => '在内置知识库中检索已验证的实战资料。知识库覆盖：常见崩溃与故障模式（mixin 注入失败、内存不足、Java 版本错误等）、'
                        . '移动端启动器生态实战案例蒸馏（FCL/Zalith/Amethyst/PGW/MobileGlues，含排障决策树）、三大日志文件格式解读、'
                        . 'Fabric/Forge/NeoForge 与 PaperMC/Purpur/Geyser 等开发文档。日志中出现异常类名、崩溃特征或启动器相关问题时优先使用；'
                        . '纯常识问题不必使用。返回带来源路径的文档片段，多数条目按「签名-含义-解决方案」组织。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string', 'description' => '检索词。直接使用日志中的原文信号：英文异常类名或错误串（如 MixinApplyError、SIGSEGV、OutOfMemoryError），或中文症状关键词（如 内存不足、启动闪退）。不要翻译或改写异常类名。'],
                            'topic' => ['type' => 'string', 'description' => '可选。限定在某个主题目录内检索（目录名来自 list_topics 的主题地图），如 "patterns"、"日志分析"。省略则全库检索。'],
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
                    'description' => '列出内置知识库的主题地图（目录、说明与内容样本）。不确定检索方向、或 rag_search 连续无结果时调用；'
                        . '看完地图后应带着明确目标词去 rag_search（可配合 topic 参数定向），不要看完地图就停止分析。',
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

        $system .= "\n\n检索策略（证据驱动）：\n"
            . "- 先通读日志，提取具体信号：异常类名、模组名、启动器名、版本号、错误串原文。\n"
            . "- 发起 rag_search 前先对照主题地图判断信号归属：明确报错条目 → 日志分析；通用崩溃模式（内存/Java 版本/mixin 等）→ patterns；启动器与渲染器问题 → 对应启动器 issue 蒸馏库（fcl-issues/zl2-issues/amc-issues/pgw-issues/mg-issues）与 renderers；mod 开发类 API 报错 → 对应 modloader 文档目录（fabric_develop/forge/neoforge 等）。地图上没有对应目录时全库检索，不要硬套目录。\n"
            . "- 检索词直接用信号原词（英文异常类名/错误串原样保留，中文症状直接用中文），可配合 topic 参数限定目录。\n"
            . "- 检索结果必须与日志中的异常真正对应才可采用；无关结果不进入分析。\n"
            . "- 无结果时换词重试最多一次，按以下方向改写（选一，不要叠加）：① 长类名去包路径取简短类名（org.spongepowered...MixinApplyError → MixinApplyError）；② 英文异常类名与中文症状词互译（OutOfMemoryError → 内存不足）；③ 叠加限定词（模组名/加载器名/启动器名）；④ 改用其他目录或放大全库。仍无结果说明知识库未覆盖，改用 web_search_exa。\n"
            . "- 预算按信息缺口计数：每个独立待核实的信号或问题，检索类调用不超过 2 次（信号原词 1 次 + 改写重试 1 次）；全对话 rag_search 与 web_search_exa 合计约 6 次时代码会注入收敛提示，此后应立即基于已有证据输出并标注未核实项。web_search_exa 全对话最多 5 次，超出将被工具直接拒绝。\n"
            . "- 引用来源：知识库结论标注条目来源路径（检索结果自带）；网络结论标注返回内容中的 URL 或站点名。\n"
            . "- 知识库结论与日志证据矛盾时，以日志为准；结论中注明哪些方面未能核实，不要臆测。\n"
            . "- 附件中存在 crash-reports 类文件时，优先用 read_log_file 读取它：崩溃报告含完整堆栈、系统状态与 mod 列表，信息密度高于 latest.log 尾部；主日志仅用于补充崩溃报告未覆盖的时间线。\n"
            . "- read_log_file 返回的主日志和附加日志均已经过与上传主日志相同的脱敏过滤；不得声称附加日志未脱敏，也不得要求用户重新提供其中的敏感信息。\n\n"
            . "示例（正确的检索路径）：\n"
            . "日志片段「Caused by: org.spongepowered.asm.mixin.transformer.MixinApplyError: ...」\n"
            . "→ 调用 rag_search(query: \"MixinApplyError\") → 命中 patterns/mixin-apply-failed.md，条目含签名与修复步骤\n"
            . "→ 直接基于日志与该条目给出结论，不再追加检索。";

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

        // topics 仅随知识库重建变化：进程级短 TTL 缓存，避免每次分析都做一次
        // 完整 MCP 握手 + list_topics 调用（推高首 token 延迟）。失败不缓存，
        // 下次分析仍会重试。
        if (self::$topicsCache !== null && self::$topicsCacheExpiresAt > time()) {
            return self::$topicsCache;
        }

        try {
            $client = self::mcpClient($endpoint, $session);
            $contents = $client->callTool('list_topics', []);
            $topics = implode("\n\n", $contents);
            self::$topicsCache = $topics;
            self::$topicsCacheExpiresAt = time() + 300;
            return $topics;
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
你是一个专业的 Minecraft 服务器日志分析助手。你的任务是分析玩家提交的日志，定位问题并提供解决方案。用户正在实时等待分析结果，追求速度、适可而止：日志内容本身通常已包含定位问题所需的全部证据，通读后若足以形成结论，直接开始分析并输出，不要为了求稳而追加工具调用。每次调用工具前先自问：这个结果会改变结论吗？不会就不要调用。

工作方式：
1. 如需查看日志文件，先用 `list_log_files` 查看有哪些文件，然后调用 `read_log_file`。默认不传范围参数以读取完整文件；需要聚焦局部内容时，由你传入 `line_start` 和 `line_end` 指定行区间。超大内容需要续读时，使用返回的 `next_offset`。
2. 若知识库检索结果被截断（出现"…"或"已截断"标记），基于被截断处再次检索补全，不需要重复读取文件。

重要停止规则：
- 不要在已经有完整日志内容的情况下再次调用 `read_log_file`，重复调用会被拒绝并浪费预算。
- 严禁使用相同的 `read_log_file` 参数调用两次；已读取内容可直接用于分析。
- 当某一个工具调用能覆盖全部问题时，不要再发起新的工具调用；应直接给出结论。
- 整个分析一般 2 至 4 轮工具调用即可完成（指工具循环轮次；检索类调用的次数预算见检索策略，两者是不同维度）；接近这个量级时优先收敛，基于已有证据给出结论，检索与文件读取都是服务于结论的手段，不是必须走完的流程。
- 文件工具失败或超时时，最多重试一次；仍不行就跳过它，基于现有证据继续分析，并在结论中说明哪些方面未能核实。不要反复重试，更不要因个别工具不可用而搁置结论。

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
                    // 硬拦截：达上限不再发起 MCP 调用；拦截分支不累加计数
                    // （避免超限后计数器无限增长）
                    if ($session->webSearchCalls >= self::MAX_WEB_SEARCH_CALLS) {
                        return '网络搜索次数已达本次分析上限。请基于已有证据完成分析，未能核实的信息在结论中明确标注。';
                    }
                    $session->webSearchCalls++;
                    $endpoint = $mcp['webSearch'] ?? [];
                    return self::callMcpTool('web_search_exa', $arguments, $endpoint, $session);

                case 'rag_search':
                    $session->ragSearchCalls++;
                    $endpoint = $mcp['rag'] ?? [];
                    $result = self::callMcpTool('rag_search', $arguments, $endpoint, $session);
                    // 软提醒：检索本身仍执行（本地 FTS 成本低，且最后一次结果可能正是所需），
                    // 仅在结果末尾追加收敛提示
                    if ($session->ragSearchCalls + $session->webSearchCalls >= self::MAX_TOTAL_RETRIEVAL_CALLS) {
                        $budget = self::MAX_TOTAL_RETRIEVAL_CALLS;
                        $result .= "\n\n[检索预算提示] 本次分析的知识库与网络检索合计已达约 {$budget} 次，请基于已有证据收敛并输出结论，未能核实的信息明确标注。";
                    }
                    return $result;

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

        // crash-reports 类附件置顶并标注 [优先]：崩溃报告信息密度高于普通日志尾部，
        // 与提示词「检索策略」的优先读取规则呼应；其余文件保持原有顺序
        $files = $log->getFiles();
        usort($files, fn($a, $b) => (int) self::isCrashReportName((string) $b['name']) <=> (int) self::isCrashReportName((string) $a['name']));
        foreach ($files as $file) {
            $mark = self::isCrashReportName((string) $file['name']) ? '[优先] ' : '';
            $lines[] = sprintf('- %s%s（%d 字节，%d 行）', $mark, $file['name'], $file['size'], $log->getFileLineNumbers($file['name']));
        }

        return implode("\n", $lines);
    }

    /**
     * 文件名（含 zip 展开后的相对路径）是否为 crash-report 类崩溃报告。
     */
    private static function isCrashReportName(string $name): bool
    {
        return stripos($name, 'crash-report') !== false;
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

        // 防重复读取循环：仅当文件已「完整」读取过时才拦截。部分读取
        // （行区间 / offset 续读）不置标记，模型按 next_offset 续读不会被
        // 误判为重复调用。
        if (($session->readFiles[$sessionKey] ?? false) === true) {
            return self::duplicateReadNotice($sessionKey, $content);
        }

        $length = strlen($content);
        $total = substr_count($content, "\n") + 1;
        $hasLineRange = isset($arguments['line_start']) || isset($arguments['line_end']);

        if ($hasLineRange) {
            $lineStart = max(1, (int) ($arguments['line_start'] ?? 1));
            $lineEnd = isset($arguments['line_end']) ? max($lineStart, (int) $arguments['line_end']) : $total;
            $lines = explode("\n", $content);
            $text = implode("\n", array_slice($lines, $lineStart - 1, $lineEnd - $lineStart + 1));
            if ($lineStart === 1 && $lineEnd >= $total) {
                $session->readFiles[$sessionKey] = true;
            }
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
        if ($offset === 0 && $nextOffset >= $length) {
            $session->readFiles[$sessionKey] = true;
        }
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

    private static function emitter(): AnalysisEmitter
    {
        $emitter = \Hyperf\Context\Context::get(self::EMITTER_CTX);
        if (!$emitter instanceof AnalysisEmitter) {
            throw new \RuntimeException('analysis emitter not bound');
        }
        return $emitter;
    }

    private static function emitContent(string $delta): void
    {
        self::emitter()->emit('', json_encode(['choices' => [['delta' => ['content' => $delta]]]], self::SSE_JSON_FLAGS));
    }

    private static function emitThinking(string $reasoning): void
    {
        self::emitter()->emit('status', json_encode(['type' => 'thinking', 'delta' => $reasoning], self::SSE_JSON_FLAGS));
    }

    private static function emitTool(string $name, array $arguments): void
    {
        self::emitter()->emit('status', json_encode(['type' => 'tool', 'name' => $name, 'arguments' => $arguments], self::SSE_JSON_FLAGS));
    }

    private static function emitToolResult(string $name, string $result): void
    {
        $summary = match ($name) {
            'read_log_file', 'list_log_files', 'list_topics' => self::buildCompactSummary($name, $result),
            'rag_search' => self::buildHitListSummary($result),
            default => mb_strcut($result, 0, self::STATUS_SUMMARY_BYTES),
        };

        self::emitter()->emit('status', json_encode([
            'type' => 'tool_result',
            'name' => $name,
            'summary' => $summary,
            'truncated' => strlen($result) > strlen($summary),
        ], self::SSE_JSON_FLAGS));
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
        self::emitter()->emit('status', json_encode(['type' => 'limit', 'rounds' => $rounds], self::SSE_JSON_FLAGS));
    }

    private static function emitDone(): void
    {
        self::emitter()->finish('done', '{"status":"completed"}');
    }

    private static function emitError(string $message): void
    {
        self::emitter()->finish('error', json_encode(['error' => $message], self::SSE_JSON_FLAGS));
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

    private static function acquireCacheLock(string $cacheKey): bool
    {
        try {
            return \App\Cache\RedisCache::Acquire($cacheKey . ':lock', 120);
        } catch (\Throwable $e) {
            return false;
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
