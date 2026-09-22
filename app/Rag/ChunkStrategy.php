<?php

namespace App\Rag;

/**
 * 分块策略枚举，对应 ai.rag.chunker 配置值。
 *
 * HEADING_ONLY 是当前（也是默认）行为——索引产物与旧版逐字节一致；
 * 其余策略仅在显式配置后生效，切换后需重跑 rag:build。
 */
enum ChunkStrategy: string
{
    /** 仅按 H2 Heading 切分（当前行为，默认） */
    case HEADING_ONLY = 'heading';

    /** 纯滑动窗口切分 */
    case SLIDING_WINDOW = 'sliding';

    /** 按估算 token 数切分 */
    case TOKEN_AWARE = 'token';

    /** Heading 为主，超长 chunk 二次滑动窗口切分（parent-child 层级，推荐） */
    case HYBRID = 'hybrid';

    /**
     * 从配置值解析策略；非法/缺失值回退 HEADING_ONLY（行为保持优先于报错）。
     */
    public static function fromConfig(mixed $value): self
    {
        if (!is_string($value)) {
            return self::HEADING_ONLY;
        }
        return self::tryFrom(strtolower(trim($value))) ?? self::HEADING_ONLY;
    }
}
