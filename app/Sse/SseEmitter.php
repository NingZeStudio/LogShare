<?php

declare(strict_types=1);

namespace App\Sse;

use Hyperf\HttpServer\Response;

/**
 * 现状行为：帧直写请求连接的 SSE 流（inline 路径与队列关闭/fail-open 时使用）。
 */
final class SseEmitter implements AnalysisEmitter
{
    public function __construct(private ?Response $response = null)
    {
    }

    public function begin(): void
    {
        SseWriter::begin($this->response);
    }

    public function emit(string $event, string $data): void
    {
        SseWriter::write(($event === '' ? 'data: ' : "event: {$event}\ndata: ") . $data . "\n\n");
    }

    public function finish(string $event, string $data): void
    {
        $this->emit($event, $data);
        SseWriter::end();
    }
}
