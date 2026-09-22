<?php

declare(strict_types=1);

namespace App\Client;

/**
 * SSE 流式响应解析器（从 AIClient::streamChat 的 write callback 抽出，对齐 plan.md §3.9）。
 *
 * 纯字符串处理、无 Swoole/curl 依赖，可独立单测：喂入原始分片，内部维护行缓冲与
 * content / reasoning / tool_calls 累积，遇到流内 error 帧抛出交由上层换 key 重试。
 * 分片归属与「空 name / 缺省 arguments」归一逻辑与原实现逐字一致，保证 SSE 逐帧不变。
 */
final class SseParser
{
    private string $buffer = '';
    private string $fullContent = '';
    private string $fullReasoning = '';
    /** @var array<int, array<string, mixed>> */
    private array $toolCalls = [];
    private ?int $lastToolCallIndex = null;
    private bool $done = false;

    /** @var callable(string):void */
    private $onDelta;
    /** @var callable(string):void */
    private $onReasoning;

    /**
     * @param callable(string):void $onDelta 正文增量回调（同时用于置 emitted 标记由调用方处理）
     * @param callable(string):void $onReasoning 思维链增量回调
     */
    public function __construct(callable $onDelta, callable $onReasoning)
    {
        $this->onDelta = $onDelta;
        $this->onReasoning = $onReasoning;
    }

    /**
     * 喂入一个 curl 写分片：归一换行、按行拆 SSE、逐条解析 data: 帧。
     *
     * @return int 本次消费的原始字节数（供 CURLOPT_WRITEFUNCTION 返回）
     * @throws \Exception 上游以 HTTP 200 + 流内 error 帧报告失败时抛出，交由上层换 key
     */
    public function feed(string $data): int
    {
        $bytesReceived = strlen($data);
        $this->buffer .= str_replace(["\r\n", "\r"], "\n", $data);

        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $pos), "\r");
            $this->buffer = substr($this->buffer, $pos + 1);

            // 兼容「data: {json}」与「data:{json}」两种分隔风格
            if (str_starts_with($line, 'data: ')) {
                $lineData = substr($line, 6);
            } elseif (str_starts_with($line, 'data:')) {
                $lineData = substr($line, 5);
            } else {
                continue;
            }

            if (trim($lineData) === '[DONE]') {
                $this->done = true;
                return $bytesReceived;
            }

            $parsed = json_decode($lineData, true);
            if (!is_array($parsed)) {
                continue;
            }

            // 部分网关以 HTTP 200 + 流内 error 帧报告失败（上下文超限、模型路由失败等），
            // 必须显式失败换 key 重试，而非静默忽略导致「空流假成功」
            if (isset($parsed['error'])) {
                $message = is_array($parsed['error'])
                    ? ($parsed['error']['message'] ?? json_encode($parsed['error'], JSON_UNESCAPED_UNICODE))
                    : (string) $parsed['error'];
                throw new \Exception('上游 API 流式错误：' . $message);
            }

            $delta = $parsed['choices'][0]['delta'] ?? [];
            $this->consumeDelta($delta);
        }

        return $bytesReceived;
    }

    /**
     * @param array $delta choices[0].delta
     */
    private function consumeDelta(array $delta): void
    {
        if (isset($delta['content']) && is_string($delta['content'])) {
            $this->fullContent .= $delta['content'];
            ($this->onDelta)($delta['content']);
        }

        if (isset($delta['reasoning_content']) && is_string($delta['reasoning_content'])) {
            $this->fullReasoning .= $delta['reasoning_content'];
            ($this->onReasoning)($delta['reasoning_content']);
        }

        if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
            $this->consumeToolCalls($delta['tool_calls']);
        }
    }

    /**
     * 累积 tool_calls 分片：正规流按 index 归桶；省略 index 的网关以「新 id 或新 name」
     * 判定开新桶，纯 arguments 分片归属最近打开的桶，避免并行调用被串接。
     *
     * @param array<int, array<string, mixed>> $toolCallDeltas
     */
    private function consumeToolCalls(array $toolCallDeltas): void
    {
        foreach ($toolCallDeltas as $toolCall) {
            $index = isset($toolCall['index']) && is_numeric($toolCall['index'])
                ? (int) $toolCall['index']
                : null;
            if ($index === null) {
                if (!empty($toolCall['id']) || !empty($toolCall['function']['name'])) {
                    $index = count($this->toolCalls);
                } else {
                    $index = $this->lastToolCallIndex ?? 0;
                }
            }
            $this->lastToolCallIndex = $index;

            if (!isset($this->toolCalls[$index])) {
                $this->toolCalls[$index] = [
                    'id' => null,
                    'type' => 'function',
                    'name' => null,
                    'arguments' => '',
                ];
            }
            // 后续分片可能携带空字符串 id/name（isset('') 为 true），不能覆盖首片真实值
            if (!empty($toolCall['id'])) {
                $this->toolCalls[$index]['id'] = $toolCall['id'];
            }
            if (isset($toolCall['function']['name']) && $toolCall['function']['name'] !== '') {
                $this->toolCalls[$index]['name'] = $toolCall['function']['name'];
            }
            if (isset($toolCall['function']['arguments'])) {
                $this->toolCalls[$index]['arguments'] .= $toolCall['function']['arguments'];
            }
        }
    }

    public function fullContent(): string
    {
        return $this->fullContent;
    }

    public function fullReasoning(): string
    {
        return $this->fullReasoning;
    }

    public function hasContent(): bool
    {
        return trim($this->fullContent) !== '';
    }

    /** 是否收到过任何 tool_call 分片（未过滤 name，供空流检测用，与原实现一致） */
    public function hasAnyToolCallBuckets(): bool
    {
        return $this->toolCalls !== [];
    }

    /** 行缓冲是否残留未处理片段（用于末尾 flush 判定，与原实现一致） */
    public function hasPending(): bool
    {
        return $this->buffer !== '';
    }

    public function hasReasoning(): bool
    {
        return trim($this->fullReasoning) !== '';
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    /**
     * 归一后的 tool_calls：剔除空 name（回传上游会被 400 拒绝）、空 arguments 补 {}。
     *
     * @return array<int, array<string, mixed>>
     */
    public function toolCalls(): array
    {
        $calls = array_values(array_filter($this->toolCalls, fn($c) => !empty($c['name'])));
        foreach ($calls as &$call) {
            if ($call['arguments'] === '') {
                $call['arguments'] = '{}';
            }
        }
        unset($call);

        return $calls;
    }

    /** 非流式回退：用一次性结果覆盖累积量（reasoning/content/tool_calls）。 */
    public function absorb(string $reasoning, string $content, array $toolCalls): void
    {
        if ($reasoning !== '') {
            $this->fullReasoning = $reasoning;
        }
        if ($content !== '') {
            $this->fullContent = $content;
        }
        if ($toolCalls !== []) {
            $this->toolCalls = $toolCalls;
        }
    }
}
