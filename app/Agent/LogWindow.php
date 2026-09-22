<?php

declare(strict_types=1);

namespace App\Agent;

/**
 * 初始日志聚焦窗口的结构化描述。
 *
 * body !== null 表示已定位到错误锚点并截取了聚焦区间（含前/后省略提示头尾）；
 * body === null 表示长日志未匹配到显式错误，交由上层生成概况 + 常用 grep 引导。
 * 抽出为值对象供 LogWindowManager 产出、PromptBuilder 消费，并为后续
 * 「锚点扩散 / 压缩」扩展出结构化行号信息预留位置。
 */
final class LogWindow
{
    public function __construct(
        public readonly ?string $body,
        public readonly ?int $anchorLine = null,
        public readonly int $startLine = 0,
        public readonly int $endLine = 0,
        public readonly int $totalLines = 0,
    ) {
    }

    public static function full(string $content): self
    {
        return new self($content);
    }

    public static function none(): self
    {
        return new self(null);
    }

    public function hasWindow(): bool
    {
        return $this->body !== null;
    }
}
