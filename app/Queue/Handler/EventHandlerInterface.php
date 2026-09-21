<?php

declare(strict_types=1);

namespace App\Queue\Handler;

use App\Queue\QueueEvent;

/**
 * 队列事件处理器标准接口。
 */
interface EventHandlerInterface
{
    /**
     * 处理队列事件。
     *
     * @param QueueEvent $event 事件上下文对象
     * @return bool 处理是否成功。返回 false 将视策略进行重试或中断后续链路。
     */
    public function handle(QueueEvent $event): bool;

    /**
     * 获取处理器标识名称。
     */
    public function getName(): string;
}
