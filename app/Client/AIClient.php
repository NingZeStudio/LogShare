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

            try {
                $payload = self::buildPayload($messages, $config['model'], $tools);
                $buffer = '';
                $fullContent = '';
                $fullReasoning = '';
                $toolCalls = [];
                $lastToolCallIndex = null;
                $responseBody = '';

                $ch = curl_init($config['baseUrl']);
                curl_setopt_array($ch, self::curlOptions($payload, $apiKey, $config['timeout']));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
                curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (
                    &$buffer,
                    &$fullContent,
                    &$fullReasoning,
                    &$toolCalls,
                    &$responseBody,
                    &$emitted,
                    &$lastToolCallIndex,
                    $onDelta,
                    $onReasoning
                ) {
                    $bytesReceived = strlen($data);
                    $buffer .= $data;

                    if (strlen($responseBody) < 8192) {
                        $responseBody .= $data;
                    }

                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = rtrim(substr($buffer, 0, $pos), "\r");
                        $buffer = substr($buffer, $pos + 1);

                        if (!str_starts_with($line, 'data: ')) {
                            continue;
                        }

                        $lineData = substr($line, 6);
                        $trimmedData = trim($lineData);

                        if ($trimmedData === '[DONE]') {
                            return $bytesReceived;
                        }

                        $parsed = json_decode($lineData, true);
                        if (!is_array($parsed)) {
                            continue;
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
                });

                curl_exec($ch);
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

                // 空完成检测：既无正文也无工具调用也无思维链 = 上游异常（如超大
                // payload 被网关静默吞掉）。视为失败，换 key 重试或向上抛错，
                // 而不是给客户端一个空分析。
                if (trim($fullContent) === '' && $toolCalls === [] && trim($fullReasoning) === '') {
                    throw new \Exception('upstream returned an empty stream (HTTP ' . $httpCode . ', ' . strlen($responseBody) . ' bytes body)');
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
                continue;
            }
        }

        if (!$success) {
            throw new \Exception('所有 API Key 均尝试失败: ' . ($lastException ? $lastException->getMessage() : '未知错误'));
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
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'Accept: text/event-stream',
            ],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => self::DEFAULT_CONNECT_TIMEOUT,
        ];
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