<?php

namespace App\Client;

use Hyperf\HttpServer\Response;

class AIClient
{
    private const DEFAULT_BASE_URL = 'https://opencode.ai/zen/v1/chat/completions';
    private const DEFAULT_MODEL = 'minimax-m2.5-free';
    private const DEFAULT_TIMEOUT = 120;
    private const DEFAULT_CONNECT_TIMEOUT = 15;
    private const CACHE_TTL = 1800;
    // 非流式回退解析用的原始响应累积上限，超过后不再累积（正常回复远小于此值）
    private const MAX_RAW_BODY_BYTES = 2097152;

    /**
     * Stream a chat completion with optional tools.
     *
     * The response is forwarded to the provided callbacks as SSE-style chunks arrive:
     *  - $onDelta(string):  text content delta
     *  - $onReasoning(string): reasoning_content delta (thinking trace)
     *  - $onToolCalls(array, string): complete tool_calls + this round's full
     *    reasoning_content once the stream finishes (reasoning models require it
     *    to be passed back with the assistant message on the next round)
     *  - $onDone(string, bool): full text content + whether tool calls were present
     *
     * Multiple API keys are tried in order; a 429 or failed request switches to the next key.
     *
     * @param array $messages Full chat message list
     * @param array $tools Tool definitions for function calling (empty to disable)
     * @param callable $onDelta
     * @param callable $onReasoning
     * @param callable $onToolCalls
     * @param callable $onDone
     * @return void
     * @throws \Exception When all API keys fail
     */
    public static function streamChat(
        array $messages,
        array $tools,
        callable $onDelta,
        callable $onReasoning,
        callable $onToolCalls,
        callable $onDone
    ): void {
        $config = self::getConfig();
        $keys = $config['apiKeys'];

        $lastException = null;
        $success = false;

        foreach ($keys as $apiKey) {
            $emitted = false;
            $retried = false;

            retry:
            try {
                $payload = self::buildPayload($messages, $config['model'], $tools);
                $buffer = '';
                $fullContent = '';
                $fullReasoning = '';
                $toolCalls = [];
                $lastToolCallIndex = null;
                $responseBody = '';
                $rawBody = '';

                $ch = curl_init($config['baseUrl']);
                curl_setopt_array($ch, self::curlOptions($payload, $apiKey, $config['timeout']));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
                $writeCallback = function ($ch, $data) use (
                    &$buffer,
                    &$fullContent,
                    &$fullReasoning,
                    &$toolCalls,
                    &$responseBody,
                    &$rawBody,
                    &$emitted,
                    &$lastToolCallIndex,
                    $onDelta,
                    $onReasoning
                ) {
                    $bytesReceived = strlen($data);
                    $buffer .= str_replace(["\r\n", "\r"], "\n", $data);

                    if (strlen($responseBody) < 8192) {
                        $responseBody .= $data;
                    }
                    if (strlen($rawBody) < self::MAX_RAW_BODY_BYTES) {
                        $rawBody .= $data;
                    }

                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = rtrim(substr($buffer, 0, $pos), "\r");
                        $buffer = substr($buffer, $pos + 1);

                        // 兼容「data: {json}」与「data:{json}」两种分隔风格
                        if (str_starts_with($line, 'data: ')) {
                            $lineData = substr($line, 6);
                        } elseif (str_starts_with($line, 'data:')) {
                            $lineData = substr($line, 5);
                        } else {
                            continue;
                        }

                        $trimmedData = trim($lineData);

                        if ($trimmedData === '[DONE]') {
                            return $bytesReceived;
                        }

                        $parsed = json_decode($lineData, true);
                        if (!is_array($parsed)) {
                            continue;
                        }

                        // 部分网关以 HTTP 200 + 流内 error 帧报告失败（如上下文
                        // 超限、模型路由失败），必须显式失败换 key 重试，而不是
                        // 静默忽略导致「空流假成功」
                        if (isset($parsed['error'])) {
                            $message = is_array($parsed['error'])
                                ? ($parsed['error']['message'] ?? json_encode($parsed['error'], JSON_UNESCAPED_UNICODE))
                                : (string) $parsed['error'];
                            throw new \Exception('upstream stream error frame: ' . $message);
                        }

                        $delta = $parsed['choices'][0]['delta'] ?? [];

                        if (isset($delta['content']) && is_string($delta['content'])) {
                            $fullContent .= $delta['content'];
                            $emitted = true;
                            $onDelta($delta['content']);
                        }

                        if (isset($delta['reasoning_content']) && is_string($delta['reasoning_content'])) {
                            $fullReasoning .= $delta['reasoning_content'];
                            $emitted = true;
                            $onReasoning($delta['reasoning_content']);
                        }

                        if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                            foreach ($delta['tool_calls'] as $toolCall) {
                                // index 归属：正规流携带 index 直接用；部分网关省略 index，
                                // 此时以「新 id 或新 name」判定开启新桶，纯 arguments 分片
                                // 归属最近打开的桶，避免多个并行调用被串接到同一桶。
                                $index = isset($toolCall['index']) && is_numeric($toolCall['index'])
                                    ? (int) $toolCall['index']
                                    : null;
                                if ($index === null) {
                                    if (!empty($toolCall['id']) || !empty($toolCall['function']['name'])) {
                                        $index = count($toolCalls);
                                    } else {
                                        $index = $lastToolCallIndex ?? 0;
                                    }
                                }
                                $lastToolCallIndex = $index;

                                if (!isset($toolCalls[$index])) {
                                    $toolCalls[$index] = [
                                        'id' => null,
                                        'type' => 'function',
                                        'name' => null,
                                        'arguments' => '',
                                    ];
                                }
                                // 注意：部分网关的后续分片会携带空字符串的 id/name，
                                // isset('') 为 true，不能直接覆盖首片的真实值
                                if (!empty($toolCall['id'])) {
                                    $toolCalls[$index]['id'] = $toolCall['id'];
                                }
                                if (
                                    isset($toolCall['function']['name'])
                                    && $toolCall['function']['name'] !== ''
                                ) {
                                    $toolCalls[$index]['name'] = $toolCall['function']['name'];
                                }
                                if (isset($toolCall['function']['arguments'])) {
                                    $toolCalls[$index]['arguments'] .= $toolCall['function']['arguments'];
                                }
                            }
                        }
                    }

                    return strlen($data);
                };

                curl_setopt($ch, CURLOPT_WRITEFUNCTION, $writeCallback);
                curl_exec($ch);
                if ($buffer !== '') {
                    $writeCallback($ch, "\n");
                }
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);

                if (!empty($curlError)) {
                    throw new \Exception('CURL error: ' . $curlError);
                }
                if ($httpCode === 429) {
                    \App\Syslog::error('AI Client', 'Stream rate limited, switching key');
                    if ($emitted) {
                        // 已向客户端 emit 了部分内容，换 key 重试会造成重复输出，直接失败
                        break;
                    }
                    continue;
                }
                // httpCode=0 绝不是成功：连接中断/上游静默断流时 curlError 可能为空，
                // 旧逻辑把 0 放行导致「空流 + 静默 done」的假成功
                if ($httpCode !== 200) {
                    throw new \Exception('HTTP ' . $httpCode . ': ' . self::extractErrorDetail($responseBody));
                }

                // 空完成检测：既无正文也无工具调用也无思维链 = 上游异常。视为
                // 失败，换 key 重试或向上抛错，而不是给客户端一个空分析。
                if (trim($fullContent) === '' && $toolCalls === [] && trim($fullReasoning) === '') {
                    // 非流式回退：部分网关在大上下文/工具循环下会忽略 stream=true，
                    // 以 HTTP 200 返回一次性 JSON；按行 SSE 解析拿不到任何 data: 行。
                    $fallback = self::extractNonStreamingResult($rawBody);
                    if ($fallback !== null) {
                        [$fbReasoning, $fbContent, $fbToolCalls] = $fallback;
                        if ($fbReasoning !== '') {
                            $emitted = true;
                            $onReasoning($fbReasoning);
                            $fullReasoning = $fbReasoning;
                        }
                        if ($fbContent !== '') {
                            $emitted = true;
                            $onDelta($fbContent);
                            $fullContent = $fbContent;
                        }
                        if ($fbToolCalls !== []) {
                            $emitted = true;
                            $toolCalls = $fbToolCalls;
                        }
                    } else {
                        // 留存响应体头部片段，便于定位上游到底回了什么
                        $bodyHead = substr($responseBody, 0, 512);
                        $bodyText = mb_check_encoding($bodyHead, 'UTF-8')
                            ? preg_replace('/\s+/', ' ', trim($bodyHead))
                            : '[non-UTF8]';
                        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'unknown';
                        \App\Syslog::error('AI Client', 'Empty stream diagnostics: HTTP ' . $httpCode
                            . ', content-type=' . $contentType
                            . ', body-bytes=' . strlen($responseBody)
                            . ', body-text=' . mb_substr((string) $bodyText, 0, 300)
                            . ', body-hex=' . bin2hex($bodyHead));
                        throw new \Exception('upstream returned an empty stream (HTTP ' . $httpCode . ', ' . strlen($responseBody) . ' bytes body)');
                    }
                }

                $success = true;
                // 防御：部分网关的分片中 name 可能为空、arguments 可能缺省，
                // 空 name 的 tool_call 回传给上游会被 400 拒绝（name cannot be empty）
                $toolCalls = array_values(array_filter($toolCalls, fn($c) => !empty($c['name'])));
                foreach ($toolCalls as &$call) {
                    if ($call['arguments'] === '') {
                        $call['arguments'] = '{}';
                    }
                }
                unset($call);
                $onToolCalls($toolCalls, $fullReasoning);
                $onDone($fullContent, !empty($toolCalls));
                break;

            } catch (\Exception $e) {
                $lastException = $e;
                // 只记录 key 指纹，避免可识别前缀进入日志
                $keyPrefix = substr(md5($apiKey), 0, 8);
                \App\Syslog::error('AI Client', "Stream Key {$keyPrefix} 失败: " . $e->getMessage());

                if ($emitted) {
                    // 已向客户端 emit 了部分内容，换 key 重试会造成重复输出，直接失败
                    break;
                }
                // 空流/HTTP 0 等偶发故障：原 key 重试一次，避免首次连接预热问题
                if (!$retried && str_contains($e->getMessage(), 'empty stream')) {
                    $retried = true;
                    \App\Syslog::error('AI Client', "Key {$keyPrefix} 空流，重试一次");
                    goto retry;
                }
                continue;
            }
        }

        if (!$success) {
            throw new \Exception('AI service temporarily unavailable.');
        }
    }

    /**
     * Stream an AI analysis of a log content as SSE, with caching.
     *
     * @param string $content
     * @param string|null $cacheKey
     * @param int $cacheTTL
     * @return void
     */
    public static function analyzeStream(string $content, ?string $cacheKey = null, int $cacheTTL = self::CACHE_TTL, ?Response $response = null): void
    {
        \App\Sse\SseWriter::begin($response);

        if ($cacheKey !== null) {
            $cacheKey = 'analysis-v2:' . $cacheKey;
            $cached = self::checkCache($cacheKey);
            if ($cached !== null) {
                \App\Sse\SseWriter::write("data: " . json_encode(['choices' => [['delta' => ['content' => $cached]]]], JSON_UNESCAPED_UNICODE) . "\n\n");
                \App\Sse\SseWriter::write("event: done\ndata: {\"status\":\"completed\"}\n\n");
                \App\Sse\SseWriter::end();
                return;
            }
        }

        try {
            self::streamChat(
                self::analysisMessages($content),
                [],
                function (string $delta) {
                    \App\Sse\SseWriter::write("data: " . json_encode(['choices' => [['delta' => ['content' => $delta]]]], JSON_UNESCAPED_UNICODE) . "\n\n");
                },
                function (string $reasoning) {
                    // Legacy plain analysis does not forward the thinking trace
                },
                function (array $toolCalls) {
                    // No tools are registered for legacy analysis
                },
                function (string $fullContent, bool $hasToolCalls) use ($cacheKey, $cacheTTL) {
                    if ($cacheKey !== null && $fullContent !== '') {
                        self::writeCache($cacheKey, $fullContent, $cacheTTL);
                    }
                    \App\Sse\SseWriter::write("event: done\ndata: {\"status\":\"completed\"}\n\n");
                    \App\Sse\SseWriter::end();
                }
            );
        } catch (\Exception $e) {
            \App\Sse\SseWriter::write("event: error\ndata: " . json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n\n");
            \App\Sse\SseWriter::end();
        }
    }

    /**
     * Build the default analysis message list for legacy analysis.
     *
     * @param string $content
     * @return array
     */
    public static function analysisMessages(string $content): array
    {
        return [
            ['role' => 'system', 'content' => '你是一个专业的 Minecraft 服务器日志分析助手。基于用户提供的日志定位问题原因，给出结论并附可行的解决步骤。回答使用简体中文，结构清晰，全程禁止使用 emoji 或表情符号。'],
            ['role' => 'user', 'content' => "请分析以下日志：\n\n" . $content],
        ];
    }

    private static function checkCache(string $cacheKey): ?string
    {
        try {
            $cached = \App\Cache\RedisCache::Get($cacheKey);
            if ($cached !== null && $cached !== '') {
                return $cached;
            }
        } catch (\Exception $e) {
            \App\Syslog::error('AI Cache', '读取失败: ' . $e->getMessage());
        }
        return null;
    }

    private static function getConfig(): array
    {
        $config = \App\Config::Get('ai') ?? [];

        $keys = [];
        if (!empty($config['apiKeys']) && is_array($config['apiKeys'])) {
            $keys = $config['apiKeys'];
        } elseif (!empty($config['apiKey'])) {
            $keys = [$config['apiKey']];
        }

        if (empty($keys)) {
            throw new \Exception('AI API key is not configured. 请在配置中设置 ai.apiKeys 或 ai.apiKey');
        }

        return [
            'apiKeys' => $keys,
            'baseUrl' => !empty($config['baseUrl']) ? $config['baseUrl'] : self::DEFAULT_BASE_URL,
            'model' => !empty($config['model']) ? $config['model'] : self::DEFAULT_MODEL,
            'timeout' => $config['timeout'] ?? self::DEFAULT_TIMEOUT,
        ];
    }

    private static function buildPayload(array $messages, string $model, array $tools): array
    {
        $payload = [
            'model' => $model,
            'stream' => true,
            'messages' => $messages,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        return $payload;
    }

    private static function curlOptions(array $payload, string $apiKey, int $timeout): array
    {
        return [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'Accept: text/event-stream',
                'Accept-Encoding: identity',
            ],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => self::DEFAULT_CONNECT_TIMEOUT,
            CURLOPT_ACCEPT_ENCODING => 'identity',
        ];
    }

    /**
     * Parse a whole response body as a non-streaming chat completion.
     *
     * Some gateways ignore stream=true under large contexts / tool loops and
     * answer with a single JSON object over HTTP 200; line-based SSE parsing
     * then sees nothing and the stream looks empty.
     *
     * @param string $body Raw response body
     * @return array{0:string,1:string,2:array}|null [reasoning, content, toolCalls] or null when not consumable
     */
    private static function extractNonStreamingResult(string $body): ?array
    {
        $trimmed = trim($body);
        if ($trimmed === '' || !str_contains($trimmed, '{')) {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (!is_array($decoded)) {
            // 容忍前后夹杂的非 JSON 噪声字符
            $start = strpos($trimmed, '{');
            $end = strrpos($trimmed, '}');
            if ($start === false || $end === false || $end <= $start) {
                return null;
            }
            $decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);
            if (!is_array($decoded)) {
                return null;
            }
        }

        if (isset($decoded['error'])) {
            $error = $decoded['error'];
            $message = is_array($error)
                ? ($error['message'] ?? json_encode($error, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE))
                : (string) $error;
            throw new \Exception('upstream response error: ' . $message);
        }

        $choice = $decoded['choices'][0] ?? null;
        if (!is_array($choice)) {
            return null;
        }
        $message = $choice['message'] ?? $choice['delta'] ?? null;
        if (!is_array($message)) {
            return null;
        }

        $content = isset($message['content']) && is_string($message['content']) ? $message['content'] : '';
        $reasoning = isset($message['reasoning_content']) && is_string($message['reasoning_content'])
            ? $message['reasoning_content']
            : '';

        $toolCalls = [];
        if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $i => $call) {
                $name = $call['function']['name'] ?? '';
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $arguments = $call['function']['arguments'] ?? '{}';
                $toolCalls[] = [
                    'id' => isset($call['id']) && is_string($call['id']) && $call['id'] !== ''
                        ? $call['id']
                        : 'call_' . $i,
                    'type' => 'function',
                    'name' => $name,
                    'arguments' => is_string($arguments) && $arguments !== '' ? $arguments : '{}',
                ];
            }
        }

        if ($content === '' && $reasoning === '' && $toolCalls === []) {
            return null;
        }

        return [$reasoning, $content, $toolCalls];
    }

    /**
     * Extract a human-readable detail from a non-2xx response body.
     *
     * @param string $body Raw response body (bounded copy)
     * @return string
     */
    private static function extractErrorDetail(string $body): string
    {
        if (trim($body) === '') {
            return 'empty response body';
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $error = $decoded['error'] ?? null;
            if (is_array($error) && isset($error['message'])) {
                return $error['message'];
            }
            if (is_string($error)) {
                return $error;
            }
        }

        return mb_substr(preg_replace('/\s+/', ' ', trim($body)), 0, 300);
    }

    private static function writeCache(?string $cacheKey, string $fullContent, int $cacheTTL): void
    {
        if ($cacheKey === null || $fullContent === '') {
            return;
        }

        try {
            \App\Cache\RedisCache::Set($cacheKey, $fullContent, $cacheTTL);
        } catch (\Exception $e) {
            \App\Syslog::error('AI Cache', '写入失败: ' . $e->getMessage());
        }
    }
}