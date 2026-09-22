<?php

declare(strict_types=1);

namespace App\Agent\Tool;

/**
 * 工具调用的重试策略值对象（不可变）。
 *
 * maxAttempts 为总尝试次数（含首次），1 表示不重试；网络类工具用 network()
 * 获得多次 + 指数退避，本地/确定性工具用 none()。延迟由 nextDelayMs() 按「已失败
 * 的这一次尝试序号（1 起）」计算，注册器据此决定是否继续重试与退避时长。
 */
final class RetryStrategy
{
    /** 单次退避延迟上限（毫秒），防止指数退避失控放大尾延迟 */
    private const MAX_DELAY_MS = 4000;

    public function __construct(
        public readonly int $maxAttempts = 1,
        public readonly int $baseDelayMs = 0,
        public readonly bool $exponentialBackoff = false,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be >= 1');
        }
    }

    /** 不重试：本地读取、参数错误等确定性调用适用 */
    public static function none(): self
    {
        return new self(1, 0, false);
    }

    /** 网络类默认策略：3 次尝试、200ms 起指数退避（→ 200ms、400ms） */
    public static function network(int $maxAttempts = 3, int $baseDelayMs = 200): self
    {
        return new self($maxAttempts, $baseDelayMs, true);
    }

    /**
     * 给定「刚失败的尝试序号」（1 起），返回重试前应等待的毫秒数；
     * 返回 0 表示不应再重试（已达 maxAttempts）。
     */
    public function nextDelayMs(int $failedAttempt): int
    {
        if ($failedAttempt >= $this->maxAttempts) {
            return 0;
        }

        if (!$this->exponentialBackoff) {
            return $this->baseDelayMs;
        }

        $delay = $this->baseDelayMs * (2 ** ($failedAttempt - 1));

        return min($delay, self::MAX_DELAY_MS);
    }
}
