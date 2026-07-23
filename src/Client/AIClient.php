<?php

namespace Client;

class AIClient
{
    private const DEFAULT_BASE_URL = 'https://opencode.ai/zen/v1/chat/completions';
    private const DEFAULT_MODEL = 'minimax-m2.5-free';
    private const DEFAULT_TIMEOUT = 120;
    private const DEFAULT_CONNECT_TIMEOUT = 15;
    private const CACHE_TTL = 1800;

    public static function analyzeStream(string $content, ?string $cacheKey = null, int $cacheTTL = self::CACHE_TTL): void
    {
        if ($cacheKey !== null) {
            $cached = self::checkCache($cacheKey);
            if ($cached !== null) {
                self::startSSE();
                echo "data: " . json_encode(['choices' => [['delta' => ['content' => $cached]]]], JSON_UNESCAPED_UNICODE) . "\n\n";
                echo "event: done\ndata: {\"status\":\"completed\"}\n\n";
                flush();
                return;
            }
        }

        $config = self::getConfig();
        $keys = $config['apiKeys'];

        self::startSSE();

        $lastException = null;
        $success = false;

        foreach ($keys as $apiKey) {
            try {
                $payload = self::buildPayload($content, $config['model']);
                $buffer = '';
                $fullContent = '';

                $ch = curl_init($config['baseUrl']);
                curl_setopt_array($ch, self::curlOptions($payload, $apiKey, $config['timeout']));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
                curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$buffer, &$fullContent, $cacheKey, $cacheTTL) {
                    $bytesReceived = strlen($data);
                    $buffer .= $data;

                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $pos);
                        $buffer = substr($buffer, $pos + 1);

                        if (str_starts_with($line, 'data: ')) {
                            $lineData = substr($line, 6);
                            $trimmedData = trim($lineData);

                            if ($trimmedData === '[DONE]') {
                                self::writeCache($cacheKey, $fullContent, $cacheTTL);
                                echo "event: done\ndata: {\"status\":\"completed\"}\n\n";
                                flush();
                                return $bytesReceived;
                            }

                            $parsed = json_decode($lineData, true);
                            if (is_array($parsed) && isset($parsed['choices'][0]['delta']['content'])) {
                                $fullContent .= $parsed['choices'][0]['delta']['content'];
                            }

                            echo "data: {$lineData}\n\n";
                            flush();
                        }
                    }

                    return $bytesReceived;
                });

                curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if (!empty($curlError)) {
                    throw new \Exception('CURL error: ' . $curlError);
                }
                if ($httpCode === 429) {
                    error_log("[AI Client] Stream rate limited, switching key");
                    continue;
                }
                if ($httpCode !== 200 && $httpCode !== 0) {
                    throw new \Exception('HTTP ' . $httpCode);
                }

                $success = true;
                break;

            } catch (\Exception $e) {
                $lastException = $e;
                $keyPrefix = substr($apiKey, 0, 16) . '...';
                error_log("[AI Client] Stream Key {$keyPrefix} 失败: " . $e->getMessage());
                continue;
            }
        }

        if (!$success) {
            echo "event: error\ndata: " . json_encode(['error' => '所有 API Key 均尝试失败: ' . ($lastException ? $lastException->getMessage() : '未知错误')], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        }
    }

    private static function startSSE(): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        if (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }

    private static function checkCache(string $cacheKey): ?string
    {
        try {
            $cached = \Cache\RedisCache::Get($cacheKey);
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
        $config = \Config::Get('ai') ?? [];

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

    private static function buildPayload(string $content, string $model): array
    {
        return [
            'model' => $model,
            'stream' => true,
            'messages' => [
                ['role' => 'user', 'content' => "这怎么回事\n\n" . $content]
            ]
        ];
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

    private static function writeCache(?string $cacheKey, string $fullContent, int $cacheTTL): void
    {
        if ($cacheKey === null || $fullContent === '') {
            return;
        }

        try {
            \Cache\RedisCache::Set($cacheKey, $fullContent, $cacheTTL);
        } catch (\Exception $e) {
            error_log("[AI Cache] 写入失败: " . $e->getMessage());
        }
    }
}
