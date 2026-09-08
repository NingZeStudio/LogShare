<?php

declare(strict_types=1);

namespace App\Sse;

use App\Client\RedisStreams;

/**
 * 队列模式发射端：帧以 {event, data} 字段 XADD 进 job 的事件流，
 * 由请求协程（AnalysisQueue::relay）原样转发给客户端。
 *
 * 客户端断连不影响本实现：任务继续执行、事件继续入流、结果照常写缓存。
 */
final class StreamEmitter implements AnalysisEmitter
{
    /** 事件流近似裁剪上限：正常分析帧数千级，超限说明异常，保留最新即可 */
    private const EVENTS_MAXLEN = 20000;

    public function __construct(
        private string $jobId,
        private int $eventsTtl = 600
    ) {
    }

    public static function eventsKey(string $jobId): string
    {
        return 'ai:job:' . $jobId . ':events';
    }

    public function begin(): void
    {
        // 事件流由首个 XADD 隐式创建，无需 begin
    }

    public function emit(string $event, string $data): void
    {
        RedisStreams::xAdd(self::eventsKey($this->jobId), ['event' => $event, 'data' => $data], self::EVENTS_MAXLEN);
    }

    public function finish(string $event, string $data): void
    {
        $this->emit($event, $data);
        RedisStreams::expire(self::eventsKey($this->jobId), $this->eventsTtl);
    }
}
