<?php

declare(strict_types=1);

namespace App\Agent\Tool;

/**
 * 携带 ai 配置与（可选）绑定日志 ID 的工具基类。
 *
 * 统一构造签名 (array $config, ?string $logId = null) 让 ToolFactory 可以
 * 位置化调用每个具体工具，而不管其是否真的用到两项参数——工厂保持简洁。
 * 基类的两个访问器在需要时读取属性，避免具体子类因「只写未读」被静态分析
 * 判定为死代码，同时为跨工具的通用配置/日志访问提供单一入口。
 */
abstract class AbstractConfiguredTool extends AbstractTool
{
    public function __construct(
        protected readonly array $config,
        protected readonly ?string $logId = null,
    ) {
    }

    /** 读取 ai 配置整体（子类若不需要可自行不调用） */
    final protected function rawConfig(): array
    {
        return $this->config;
    }

    /** 读取绑定的日志 ID（未绑定时为 null） */
    final protected function boundLogId(): ?string
    {
        return $this->logId;
    }
}
