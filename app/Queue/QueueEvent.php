<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * 统一队列事件上下文对象。
 *
 * 封装事件名称、唯一标识、结构化载荷、重试状态与流转中断控制。
 */
class QueueEvent
{
    private string $id;
    private string $name;
    /** @var array<string, mixed> */
    private array $payload;
    private int $attempts;
    private int $maxAttempts;
    private float $dispatchedAt;
    private bool $propagationStopped = false;
    private ?string $lastError = null;

    /**
     * @param string $name 事件名称
     * @param array<string, mixed> $payload 事件载荷数据
     * @param string|null $id 事件唯一 ID（未指定时自动生成）
     * @param int $attempts 当前已尝试次数
     * @param int $maxAttempts 最大允许尝试次数
     * @param float|null $dispatchedAt 派发时间戳
     */
    public function __construct(
        string $name,
        array $payload = [],
        ?string $id = null,
        int $attempts = 1,
        int $maxAttempts = 3,
        ?float $dispatchedAt = null
    ) {
        $this->name = $name;
        $this->payload = $payload;
        $this->id = $id ?? uniqid('evt_', true);
        $this->attempts = $attempts;
        $this->maxAttempts = $maxAttempts;
        $this->dispatchedAt = $dispatchedAt ?? microtime(true);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    public function set(string $key, mixed $value): self
    {
        $this->payload[$key] = $value;
        return $this;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function incrementAttempts(): void
    {
        $this->attempts++;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function canRetry(): bool
    {
        return $this->attempts < $this->maxAttempts;
    }

    public function getDispatchedAt(): float
    {
        return $this->dispatchedAt;
    }

    /**
     * 中断后续处理器继续执行。
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    public function setLastError(?string $error): void
    {
        $this->lastError = $error;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * 序列化为适合投递到 Redis Stream 的数组。
     *
     * @return array<string, string>
     */
    public function toStreamData(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->name,
            'payload' => json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            'attempts' => (string) $this->attempts,
            'maxAttempts' => (string) $this->maxAttempts,
            'dispatchedAt' => (string) $this->dispatchedAt,
        ];
    }

    /**
     * 从 Redis Stream 原始字段还原事件对象。
     *
     * @param array<string, mixed> $fields
     * @param string|null $streamId
     */
    public static function fromStreamData(array $fields, ?string $streamId = null): self
    {
        $name = (string) ($fields['event'] ?? 'unknown');
        $rawPayload = (string) ($fields['payload'] ?? '{}');
        $payload = json_decode($rawPayload, true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $id = isset($fields['id']) && $fields['id'] !== '' ? (string) $fields['id'] : ($streamId ?? uniqid('evt_', true));
        $attempts = isset($fields['attempts']) ? (int) $fields['attempts'] : 1;
        $maxAttempts = isset($fields['maxAttempts']) ? (int) $fields['maxAttempts'] : 3;
        $dispatchedAt = isset($fields['dispatchedAt']) ? (float) $fields['dispatchedAt'] : microtime(true);

        return new self($name, $payload, $id, $attempts, $maxAttempts, $dispatchedAt);
    }
}
