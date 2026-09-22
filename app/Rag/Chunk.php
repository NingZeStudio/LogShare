<?php

namespace App\Rag;

/**
 * 分块值对象：一个可独立索引、检索的语义单元。
 *
 * startOffset/endOffset 为字符（mb）偏移，用于 HYBRID 二次切分时回溯
 * parent 原文位置；parentId 指向同批次内父 chunk 的数组下标（null = 顶层）。
 * tokenCount 为估算 token 数（见 Chunker::estimateTokens），HYBRID 的
 * 二次切分触发阈值与 TOKEN_AWARE 的切分粒度都以它为准。
 */
final class Chunk
{
    public function __construct(
        public readonly string $source,
        public readonly string $title,
        public readonly string $body,
        public readonly int $startOffset = 0,
        public readonly int $endOffset = 0,
        public readonly ?int $parentId = null,
        public readonly int $tokenCount = 0,
    ) {
    }

    /**
     * 转为旧版 chunkMarkdown 的数组形态，保持既有消费方（buildIndex 之外的
     * 调用与单测断言）不受影响。
     *
     * @return array{title: string, body: string}
     */
    public function toLegacyArray(): array
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}
