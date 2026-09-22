<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Llm\LlmStreamHandler;
use App\Sse\AnalysisEmitter;

/**
 * 一轮流式调用的收集器 + SSE 透传。
 *
 * onContentDelta / onReasoningDelta 既累加（供 fullAnswer 聚合与轮间状态）又
 * 实时发射对应 SSE 帧，帧结构与旧 LogAgent::emitContent / emitThinking 逐字一致。
 * onFullContent 仅覆盖累加值、不再发射，对应非流式回退（网关整段返回）路径，
 * 与旧实现「正文只经增量发射一次」保持一致。
 */
final class RoundHandler implements LlmStreamHandler
{
    private const SSE_JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    private string $content = '';
    private string $reasoning = '';

    /** @var array<int, array<string, mixed>> */
    private array $toolCalls = [];

    public function __construct(
        private readonly AnalysisEmitter $emitter,
    ) {
    }

    public function onContentDelta(string $delta): void
    {
        $this->content .= $delta;
        $this->emitter->emit('', json_encode(
            ['choices' => [['delta' => ['content' => $delta]]]],
            self::SSE_JSON_FLAGS
        ));
    }

    public function onReasoningDelta(string $reasoning): void
    {
        $this->reasoning .= $reasoning;
        $this->emitter->emit('status', json_encode(
            ['type' => 'thinking', 'delta' => $reasoning],
            self::SSE_JSON_FLAGS
        ));
    }

    public function onToolCalls(array $toolCalls, string $reasoning): void
    {
        $this->toolCalls = $toolCalls;
        if ($reasoning !== '' && $this->reasoning === '') {
            $this->reasoning = $reasoning;
        }
    }

    public function onFullContent(string $content): void
    {
        $this->content = $content;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function reasoning(): string
    {
        return $this->reasoning;
    }

    /** @return array<int, array<string, mixed>> */
    public function toolCalls(): array
    {
        return $this->toolCalls;
    }
}
