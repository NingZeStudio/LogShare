<?php

declare(strict_types=1);

namespace App\Queue\Handler;

use App\Id;
use App\Log;
use App\Queue\QueueEvent;

/**
 * 异步反混淆事件处理器。
 */
class DeobfuscateHandler implements EventHandlerInterface
{
    public function getName(): string
    {
        return 'deobfuscate';
    }

    /**
     * 实例调用处理。
     */
    public function handle(QueueEvent $event): bool
    {
        $payload = $event->getPayload();
        $logId = $payload['logId'] ?? null;
        if (!is_string($logId) || !preg_match('/^[a-zA-Z0-9_-]+$/', $logId)) {
            $event->setLastError("Invalid logId for deobfuscation: " . json_encode($logId));
            return false;
        }

        try {
            $log = new Log(new Id($logId));
            if (!$log->exists()) {
                // 日志不存在（可能已被审核删除），安全退出
                return true;
            }

            // 执行反混淆并更新存储；若日志本身无需反混淆或未加载映射也视为正常处理通过
            $log->deobfuscateAndPersist();
            return true;
        } catch (\Throwable $e) {
            $event->setLastError("Deobfuscate exception: " . $e->getMessage());
            \App\Syslog::error('DeobfuscateHandler', "Failed to deobfuscate log {$logId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 静态兼容方法，支持传入 array payload 或 QueueEvent 对象。
     *
     * @param array<string, mixed>|QueueEvent $event
     * @return bool 是否成功处理
     */
    public static function process(array|QueueEvent $event): bool
    {
        $queueEvent = $event instanceof QueueEvent
            ? $event
            : new QueueEvent('log.deobfuscate', $event);

        return (new self())->handle($queueEvent);
    }
}
