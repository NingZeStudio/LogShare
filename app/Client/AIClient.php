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
            $retries = 0;

            // 同 key 空流重试用内层 while 实现（取代 goto retry）：
            // continue = 同 key 重试；break = 放弃本 key，外层 foreach 换下一 key；
            // break 2 = 成功或已向客户端 emit 过内容，彻底退出重试。
            while (true) {
                $ch = null;
                try {
                    $payload = self::buildPayload($messages, $config['model'], $tools);
                    $responseBody = '';
                    $rawBody = '';

                    $parser = new SseParser(
                        function (string $delta) use (&$emitted, $onDelta): void {
                            $emitted = true;
                            $onDelta($delta);
                        },
                        function (string $reasoning) use (&$emitted, $onReasoning): void {
                            $emitted = true;
                            $onReasoning($reasoning);
                        }
                    );

                    $ch = curl_init($config['baseUrl']);
                    curl_setopt_array($ch, self::curlOptions($payload, $apiKey, $config['timeout'], $config['headers'] ?? []));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
                    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$responseBody, &$rawBody, $parser): int {
                        if (strlen($responseBody) < 8192) {
                            $responseBody .= $data;
                        }
                        if (strlen($rawBody) < self::MAX_RAW_BODY_BYTES) {
                            $rawBody .= $data;
                        }

                        return $parser->feed($data);
                    });
                    curl_exec($ch);
                    if ($parser->hasPending()) {
                        $parser->feed("\n");
                    }
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);

                if (!empty($curlError)) {
                    throw new \Exception('连接 AI API 失败：' . $curlError);
                }
                if ($httpCode === 429) {
                    $lastException = new \Exception('HTTP 429: ' . self::extractErrorDetail($responseBody));
                    \App\Syslog::error('AI Client', 'Stream rate limited, switching key');
                    if ($emitted) {
                        // 已向客户端 emit 了部分内容，换 key 重试会造成重复输出，直接失败
                        break 2;
                    }
                    break;
                }
                // httpCode=0 绝不是成功：连接中断/上游静默断流时 curlError 可能为空，
                // 旧逻辑把 0 放行导致「空流 + 静默 done」的假成功
                if ($httpCode !== 200) {
                    throw new \Exception('AI API 返回 HTTP ' . $httpCode . '：' . self::extractErrorDetail($responseBody));
                }

                // 空完成检测：既无正文也无工具调用也无思维链 = 上游异常。视为
                // 失败，换 key 重试或向上抛错，而不是给客户端一个空分析。
                if (!$parser->hasContent() && !$parser->hasAnyToolCallBuckets() && !$parser->hasReasoning()) {
                    // 非流式回退：部分网关在大上下文/工具循环下会忽略 stream=true，
                    // 以 HTTP 200 返回一次性 JSON；按行 SSE 解析拿不到任何 data: 行。
                    $fallback = self::extractNonStreamingResult($rawBody);
                    if ($fallback !== null) {
                        [$fbReasoning, $fbContent, $fbToolCalls] = $fallback;
                        if ($fbReasoning !== '') {
                            $emitted = true;
                            $onReasoning($fbReasoning);
                        }
                        if ($fbContent !== '') {
                            $emitted = true;
                            $onDelta($fbContent);
                        }
                        $parser->absorb($fbReasoning, $fbContent, $fbToolCalls);
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
                        throw new \Exception('AI API 返回空响应流（HTTP ' . $httpCode . '，响应体 ' . strlen($responseBody) . ' 字节）');
                    }
                }

                $success = true;
                // 归并收尾：SseParser 已产出文本/思维链，tool_calls 已完成空 name 过滤与
                // arguments 归一（回传上游 400 防御），统一收敛为 ChatResponse 值对象
                $response = new ChatResponse($parser->fullContent(), $parser->fullReasoning(), $parser->toolCalls());
                $onToolCalls($response->toolCalls, $response->reasoning);
                $onDone($response->content, $response->hasToolCalls());
                break 2;

            } catch (\App\Exception\ClientDisconnectedException $e) {
                // 断连必须原样传播：SseWriter 已无法写入，LogAgent 需要据此直接
                // 中止循环，而不是被包装成「密钥失败」后继续换 key / emitError
                throw $e;
            } catch (\Exception $e) {
                $lastException = $e;
                // 只记录 key 指纹，避免可识别前缀进入日志
                $keyPrefix = substr(md5($apiKey), 0, 8);
                \App\Syslog::error('AI Client', "Stream Key {$keyPrefix} 失败: " . $e->getMessage());

                if ($emitted) {
                    // 已向客户端 emit 了部分内容，换 key 重试会造成重复输出，直接失败
                    break 2;
                }
                // 空流/HTTP 0 等偶发故障：原 key 重试一次，避免首次连接预热问题
                if ($retries === 0 && str_contains($e->getMessage(), 'empty stream')) {
                    $retries++;
                    \App\Syslog::error('AI Client', "Key {$keyPrefix} 空流，重试一次");
                    continue;
                }
                break;
            } finally {
                // 释放 curl 句柄：写回调以字面量登记、未捕获 $ch，句柄销毁即回收回调
                $ch = null;
            }
            }
        }

        if (!$success) {
            if ($lastException !== null) {
                throw new \Exception('所有 AI API 密钥均尝试失败：' . $lastException->getMessage(), 0, $lastException);
            }
            throw new \Exception('AI API 暂时不可用。');
        }
    }

    /**
     * Stream an AI analysis of a log content as SSE, with caching.
     *
     * @param string $content
     * @param string|null $cacheKey
     * @param int $cacheTTL
     * @param \App\Sse\AnalysisEmitter|null $emitter 缺省 SseEmitter（直写请求连接）；
     *                                                队列模式传 StreamEmitter（帧入 Redis Stream）
     * @return void
     */
    public static function analyzeStream(string $content, ?string $cacheKey = null, int $cacheTTL = self::CACHE_TTL, ?Response $response = null, ?\App\Sse\AnalysisEmitter $emitter = null): void
    {
        $emitter = $emitter ?? new \App\Sse\SseEmitter($response);
        $emitter->begin();

        if ($cacheKey !== null) {
            $cacheKey = 'analysis-v2:' . $cacheKey;
            $cached = self::checkCache($cacheKey);
            if ($cached !== null) {
                $emitter->emit('', json_encode(['choices' => [['delta' => ['content' => $cached]]]], JSON_UNESCAPED_UNICODE));
                $emitter->finish('done', '{"status":"completed"}');
                return;
            }
        }

        try {
            self::streamChat(
                self::analysisMessages($content),
                [],
                function (string $delta) use ($emitter) {
                    $emitter->emit('', json_encode(['choices' => [['delta' => ['content' => $delta]]]], JSON_UNESCAPED_UNICODE));
                },
                function (string $reasoning) {
                    // Legacy plain analysis does not forward the thinking trace
                },
                function (array $toolCalls) {
                    // No tools are registered for legacy analysis
                },
                function (string $fullContent, bool $hasToolCalls) use ($emitter, $cacheKey, $cacheTTL) {
                    if ($cacheKey !== null && $fullContent !== '') {
                        self::writeCache($cacheKey, $fullContent, $cacheTTL);
                    }
                    $emitter->finish('done', '{"status":"completed"}');
                }
            );
        } catch (\App\Exception\ClientDisconnectedException $e) {
            // 客户端已断开：无法再写任何 SSE 帧，记日志后静默中止
            \App\Syslog::error('AI Client', '客户端已断开，分析中止: ' . $e->getMessage());
        } catch (\Exception $e) {
            $emitter->finish('error', json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
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
        $config = \App\Config::Get('ai');

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
            'headers' => is_array($config['headers'] ?? null) ? $config['headers'] : [],
        ];
    }

    private static function buildPayload(array $messages, string $model, array $tools): array
    {
        return (new ChatRequest($model, $messages, $tools))->toPayload();
    }

    private static function curlOptions(array $payload, string $apiKey, int $timeout, array $customHeaders = []): array
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'Accept: text/event-stream',
            'Accept-Encoding: identity',
        ];

        foreach ($customHeaders as $key => $value) {
            if (is_string($key) && $key !== '') {
                $headerLine = $key . ': ' . (string) $value;
                $keyLower = strtolower($key);
                $overridden = false;
                foreach ($headers as $idx => $existing) {
                    if (str_starts_with(strtolower($existing), $keyLower . ':')) {
                        $headers[$idx] = $headerLine;
                        $overridden = true;
                        break;
                    }
                }
                if (!$overridden) {
                    $headers[] = $headerLine;
                }
            } elseif (is_string($value) && trim($value) !== '') {
                $headers[] = trim($value);
            }
        }

        return [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => self::DEFAULT_CONNECT_TIMEOUT,
            CURLOPT_ACCEPT_ENCODING => 'identity',
            CURLOPT_FORBID_REUSE => true,
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
            throw new \Exception('上游 AI API 返回错误：' . $message);
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