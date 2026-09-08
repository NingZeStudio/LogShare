<?php

declare(strict_types=1);

namespace App\Sse;

/**
 * 分析流事件的发射端抽象。
 *
 * 帧载荷（event 名 + data JSON 串）由 LogAgent/AIClient 生成，本接口只负责
 * 投递：SseEmitter 直写客户端连接，StreamEmitter 写入 Redis Stream 供中继端
 * 原样转发——两种实现产出逐字节相同的 SSE 帧，中继端零翻译。
 */
interface AnalysisEmitter
{
    public function begin(): void;

    /**
     * @param string $event '' 表示纯 data 帧（OpenAI 风格增量），'status'/'done'/'error' 等为具名帧
     * @param string $data  已序列化的 JSON 载荷
     */
    public function emit(string $event, string $data): void;

    /** 终帧（done/error）：发出后关闭流。 */
    public function finish(string $event, string $data): void;
}
