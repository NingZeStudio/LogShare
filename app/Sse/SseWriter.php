<?php

declare(strict_types=1);

namespace App\Sse;

use Hyperf\Context\Context;
use Hyperf\Engine\Http\EventStream;
use Hyperf\HttpServer\Response;

/**
 * SSE 输出统一入口（AIClient / LogAgent 共用）。
 *
 * Swoole 下流句柄存于协程级 Hyperf\Context\Context，随请求协程销毁；
 * CLI/测试环境回退进程级静态（无协程并发）或直写 stdout。
 */
final class SseWriter
{
    private const CONTEXT_KEY = 'logshare_sse_stream';

    /** CLI fallback storage (no Swoole coroutine, so a static is safe). */
    private static ?EventStream $cliFallback = null;

    private function __construct()
    {
    }

    /**
     * Begin an SSE response: bind the coroutine-scoped stream when a Swoole
     * connection is available, otherwise fall back to plain output.
     */
    public static function begin(?Response $response = null): void
    {
        self::store(null);

        if ($response !== null) {
            // 流式响应经 EventStream 直写连接，不会经过 CorsMiddleware 对返回值的
            // 加头流程；必须在首帧下发前手动携带 CORS 头，否则浏览器跨域拦截 SSE。
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Headers', '*')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->withHeader('Cache-Control', 'no-cache')
                ->withHeader('X-Accel-Buffering', 'no');

            $connection = $response->getConnection();
            if ($connection !== null) {
                self::store(new EventStream($connection, $response));
                return;
            }
        }

        if (PHP_SAPI !== 'cli') {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
        }

        // 仅 web 场景清空全部应用缓冲保证 SSE 首帧即时下发；
        // CLI/测试环境不清宿主（PHPUnit/Pest）缓冲，避免破坏其输出捕获
        if (PHP_SAPI !== 'cli') {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
        }
        flush();
    }

    public static function write(string $data): void
    {
        $stream = self::current();
        if ($stream instanceof EventStream) {
            $stream->write($data);
        } else {
            echo $data;
            flush();
        }
    }

    public static function end(): void
    {
        $stream = self::current();
        if ($stream instanceof EventStream) {
            $stream->end();
        }
        self::store(null);
    }

    private static function current(): ?EventStream
    {
        if (extension_loaded('swoole')) {
            $stream = Context::get(self::CONTEXT_KEY);
            return $stream instanceof EventStream ? $stream : null;
        }
        return self::$cliFallback;
    }

    private static function store(?EventStream $stream): void
    {
        if (extension_loaded('swoole')) {
            Context::set(self::CONTEXT_KEY, $stream);
        } else {
            self::$cliFallback = $stream;
        }
    }
}
