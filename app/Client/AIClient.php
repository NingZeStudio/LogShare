<?php

namespace App\Client;

use Hyperf\Context\Context;
use Hyperf\Engine\Http\EventStream;
use Hyperf\HttpServer\Response;

class AIClient
{
    private const DEFAULT_BASE_URL = 'https://opencode.ai/zen/v1/chat/completions';
    private const DEFAULT_MODEL = 'minimax-m2.5-free';
    private const DEFAULT_TIMEOUT = 120;
    private const DEFAULT_CONNECT_TIMEOUT = 15;
    private const CACHE_TTL = 1800;

    private const SSE_CONTEXT_KEY = 'logshare_sse_stream';

    /**
     * Stream a chat completion with optional tools.
     *
     * The response is forwarded to the provided callbacks as SSE-style chunks arrive:
     *  - $onDelta(string):  text content delta
     *  - $onReasoning(string): reasoning_content delta (thinking trace)
     *  - $onToolCalls(array): complete tool_calls once the stream finishes
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
                $toolCalls = [];
                $responseBody = '';

                $ch = curl_init($config['baseUrl']);
                curl_setopt_array($ch, self::curlOptions($payload, $apiKey, $config['timeout']));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
                curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (
                    &$buffer,
                    &$fullContent,
                    &$toolCalls,
                    &$responseBody,
                    &$emitted,
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
                            $emitted = true;
                            $onReasoning($delta['reasoning_content']);
                        }

                        if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                            foreach ($delta['tool_calls'] as $toolCall) {
                                $index = $toolCall['index'] ?? 0;
                                if (!isset($toolCalls[$index])) {
                                    $toolCalls[$index] = [
                                        'id' => null,
                                        'type' => 'function',
                                        'name' => null,
                                        'arguments' => '',
                                    ];
                                }
                                if (isset($toolCall['id'])) {
                                    $toolCalls[$index]['id'] = $toolCall['id'];
                                }
                                if (isset($toolCall['function']['name'])) {
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
                    error_log("[AI Client] Stream rate limited, switching key");
                    continue;
                }
                if ($httpCode !== 200 && $httpCode !== 0) {
                    throw new \Exception('HTTP ' . $httpCode . ': ' . self::extractErrorDetail($responseBody));
                }

                $success = true;
                $toolCalls = array_values($toolCalls);
                $onToolCalls($toolCalls);
                $onDone($fullContent, !empty($toolCalls));
                break;

            } catch (\Exception $e) {
                $lastException = $e;
                $keyPrefix = substr($apiKey, 0, 16) . '...';
                error_log("[AI Client] Stream Key {$keyPrefix} 失败: " . $e->getMessage());

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
        self::startSSE($response);

        if ($cacheKey !== null) {
            $cached = self::checkCache($cacheKey);
            if ($cached !== null) {
                self::write("data: " . json_encode(['choices' => [['delta' => ['content' => $cached]]]], JSON_UNESCAPED_UNICODE) . "\n\n");
                self::write("event: done\ndata: {\"status\":\"completed\"}\n\n");
                self::end();
                return;
            }
        }

        try {
            self::streamChat(
                self::analysisMessages($content),
                [],
                function (string $delta) {
                    self::write("data: " . json_encode(['choices' => [['delta' => ['content' => $delta]]]], JSON_UNESCAPED_UNICODE) . "\n\n");
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
                    self::write("event: done\ndata: {\"status\":\"completed\"}\n\n");
                    self::end();
                }
            );
        } catch (\Exception $e) {
            self::write("event: error\ndata: " . json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n\n");
            self::end();
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
            ['role' => 'user', 'content' => "这怎么回事\n\n" . $content]
        ];
    }

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

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

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

    private static function end(): void
    {
        $stream = Context::get(self::SSE_CONTEXT_KEY);
        if ($stream instanceof EventStream) {
            $stream->end();
            Context::set(self::SSE_CONTEXT_KEY, null);
        }
    }

    private static function checkCache(string $cacheKey): ?string
    {
        try {
            $cached = \App\Cache\RedisCache::Get($cacheKey);
            if ($cached !== null && $cached !== '') {
                return $cached;
            }
        } catch (\Exception $e) {
            error_log("[AI Cache] 读取失败: " . $e->getMessage());
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
            error_log("[AI Cache] 写入失败: " . $e->getMessage());
        }
    }
}