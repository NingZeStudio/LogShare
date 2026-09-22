<?php

declare(strict_types=1);

namespace App\Client;

/**
 * 一次 chat completion 请求的不可变描述（对齐 plan.md §3.9）。
 *
 * 把原先散在 buildPayload() 里的 model / messages / tools / stream 组装收敛为值对象，
 * 序列化格式与原实现逐字一致（tools 为空则不下发 tools 键），供 curlOptions 直接编码。
 */
final class ChatRequest
{
    /**
     * @param array $messages 完整对话消息列表
     * @param array $tools function-calling 工具定义（空数组表示不启用工具）
     */
    public function __construct(
        public readonly string $model,
        public readonly array $messages,
        public readonly array $tools = [],
    ) {
    }

    /** 转为 OpenAI 兼容请求体（stream=true）。 */
    public function toPayload(): array
    {
        $payload = [
            'model' => $this->model,
            'stream' => true,
            'messages' => $this->messages,
        ];

        if (!empty($this->tools)) {
            $payload['tools'] = $this->tools;
        }

        return $payload;
    }
}
