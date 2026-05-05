<?php

namespace Client;

class AiClient
{
    /**
     * 调用 AI 分析日志内容，返回规范化 JSON
     *
     * @param string $content 日志内容
     * @return array 分析结果
     * @throws \Exception
     */
    public static function analyze(string $content): array
    {
        $config = \Config::Get('ai');
        $apiKey = $config['apiKey'] ?? '';
        $baseUrl = $config['baseUrl'] ?? 'https://opencode.ai/zen/v1/chat/completions';
        $model = $config['model'] ?? 'minimax-m2.5-free';
        $timeout = $config['timeout'] ?? 60;

        if (empty($apiKey)) {
            throw new \Exception('AI API key is not configured.');
        }

        $systemPrompt = '你是一个 Minecraft/Hytale 服务器日志分析专家。请分析用户提供的日志内容，识别错误、警告和异常，给出可能的原因和解决建议。输出必须是合法的 JSON 对象，包含以下字段：' . "\n"
            . '- summary: 问题摘要（字符串）' . "\n"
            . '- severity: 严重程度，只能是 low、medium、high、critical 之一' . "\n"
            . '- issues: 问题列表（数组），每个元素包含 type（类型）、description（描述）、suggestion（建议）' . "\n"
            . '- recommendations: 总体建议列表（字符串数组）' . "\n"
            . '不要输出任何 JSON 以外的内容。';

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "请分析以下日志内容：\n\n" . $content]
            ]
        ];

        $ch = curl_init($baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        // PHP 8.0+ 自动清理 cURL 句柄，不需要 curl_close()

        if ($response === false) {
            throw new \Exception('AI API request failed: ' . $curlError);
        }

        if ($httpCode !== 200) {
            throw new \Exception('AI API returned HTTP ' . $httpCode . ': ' . substr($response, 0, 500));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['choices'][0]['message']['content'])) {
            throw new \Exception('AI API returned invalid response format: ' . substr($response, 0, 500));
        }

        $aiContent = $decoded['choices'][0]['message']['content'];

        // 处理 markdown 代码块包裹的情况
        if (preg_match('/^```(?:json)?\s*\n?(.*?)\n?\s*```$/s', $aiContent, $matches)) {
            $aiContent = $matches[1];
        }

        $result = json_decode($aiContent, true);
        if (!is_array($result)) {
            throw new \Exception('AI response is not valid JSON. Raw content: ' . substr($aiContent, 0, 500));
        }

        return $result;
    }

    /**
     * 流式调用 AI 分析，实时输出 SSE 数据
     *
     * @param string $content 日志内容
     * @param string|null $cacheKey Redis 缓存键（可选）
     * @param int $cacheTTL 缓存 TTL（秒）
     * @return void 直接输出到客户端
     * @throws \Exception
     */
    public static function analyzeStream(string $content, ?string $cacheKey = null, int $cacheTTL = 300): void
    {
        $config = \Config::Get('ai');
        $apiKey = $config['apiKey'] ?? '';
        $baseUrl = $config['baseUrl'] ?? 'https://opencode.ai/zen/v1/chat/completions';
        $model = $config['model'] ?? 'minimax-m2.5-free';
        $timeout = $config['timeout'] ?? 60;

        if (empty($apiKey)) {
            throw new \Exception('AI API key is not configured.');
        }

        $systemPrompt = '你是一个 Minecraft/Hytale 服务器日志分析专家。请分析用户提供的日志内容，识别错误、警告和异常，给出可能的原因和解决建议。输出必须是合法的 JSON 对象，包含以下字段：' . "\n"
            . '- summary: 问题摘要（字符串）' . "\n"
            . '- severity: 严重程度，只能是 low、medium、high、critical 之一' . "\n"
            . '- issues: 问题列表（数组），每个元素包含 type（类型）、description（描述）、suggestion（建议）' . "\n"
            . '- recommendations: 总体建议列表（字符串数组）' . "\n"
            . '不要输出任何 JSON 以外的内容。';

        $payload = [
            'model' => $model,
            'stream' => true,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "请分析以下日志内容：\n\n" . $content]
            ]
        ];

        // 设置 SSE 响应头
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        if (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();

        $ch = curl_init($baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'Accept: text/event-stream'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        // 实时处理 SSE 数据块并累积完整内容用于缓存
        $buffer = '';
        $fullContent = '';

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$buffer, &$fullContent, $cacheKey, $cacheTTL) {
            $bytesReceived = strlen($data);
            $buffer .= $data;

            // 按行处理 SSE 数据
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if (str_starts_with($line, 'data: ')) {
                    $lineData = substr($line, 6);
                    $trimmedData = trim($lineData);

                    // [DONE] 标记表示流结束，写入缓存
                    if ($trimmedData === '[DONE]') {
                        if ($cacheKey !== null && !empty($fullContent)) {
                            try {
                                $decoded = json_decode($fullContent, true);
                                if (is_array($decoded)) {
                                    $cacheData = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                    if ($cacheData !== false) {
                                        \Cache\RedisCache::Set($cacheKey, $cacheData, $cacheTTL);
                                    }
                                }
                            } catch (\Exception $e) {
                                error_log("[AI Cache] 写入失败: " . $e->getMessage());
                            }
                        }

                        echo "event: done\ndata: {\"status\":\"completed\"}\n\n";
                        flush();
                        return $bytesReceived;
                    }

                    // 解析并累积 JSON 内容
                    $parsed = json_decode($lineData, true);
                    if (is_array($parsed) && isset($parsed['choices'][0]['delta']['content'])) {
                        $fullContent .= $parsed['choices'][0]['delta']['content'];
                    }

                    // 转发原始 SSE 数据给客户端
                    echo "data: {$lineData}\n\n";
                    flush();
                }
            }

            return $bytesReceived;
        });

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($response === false && !empty($curlError)) {
            echo "event: error\ndata: {\"error\":\"" . addslashes($curlError) . "\"}\n\n";
            flush();
        }

        if ($httpCode !== 200 && $httpCode !== 0) {
            echo "event: error\ndata: {\"error\":\"HTTP " . $httpCode . "\"}\n\n";
            flush();
        }
    }
}
