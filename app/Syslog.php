<?php

declare(strict_types=1);

namespace App;

/**
 * 进程级错误日志统一入口。
 *
 * 常驻进程中所有诊断输出都应经过此处，保证 `[组件] 消息` 格式一致，
 * 并为未来切换到 Hyperf StdoutLoggerInterface 保留单一改动点。
 * （App\Log 已被 Codex 日志包装占用，故命名 Syslog。）
 */
final class Syslog
{
    public static function error(string $component, string $message): void
    {
        error_log(sprintf('[%s] %s', $component, $message));
    }
}
