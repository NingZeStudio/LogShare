<?php

declare(strict_types=1);

namespace App\Rag\Rerank;

/**
 * 空精排器：直接返回输入（RRF 顺序），rerank 开关关闭时的默认实现。
 */
final class NoopReranker implements RerankInterface
{
    public function isActive(): bool
    {
        return false;
    }

    public function rerank(string $query, array $candidates): array
    {
        return $candidates;
    }
}
