<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Agent\Support\McpClientFactory;
use App\Agent\ToolSession;

final class RagSearchTool extends AbstractConfiguredTool
{
    public function name(): string
    {
        return 'rag_search';
    }

    protected function description(): string
    {
        return '在内置知识库中检索已验证的实战资料。知识库覆盖：常见崩溃与故障模式（mixin 注入失败、内存不足、Java 版本错误等）、'
            . '移动端启动器生态实战案例蒸馏（FCL/Zalith/Amethyst/PGW/MobileGlues，含排障决策树）、三大日志文件格式解读、'
            . 'Fabric/Forge/NeoForge 与 PaperMC/Purpur/Geyser 等开发文档。日志中出现异常类名、崩溃特征或启动器相关问题时优先使用；'
            . '纯常识问题不必使用。返回带来源路径的文档片段，多数条目按「签名-含义-解决方案」组织。';
    }

    protected function parameterSchema(): array
    {
        return $this->objectSchema([
            'query' => ['type' => 'string', 'description' => '检索词。直接使用日志中的原文信号：英文异常类名或错误串（如 MixinApplyError、SIGSEGV、OutOfMemoryError），或中文症状关键词（如 内存不足、启动闪退）。不要翻译或改写异常类名。'],
            'topic' => ['type' => 'string', 'description' => '可选。限定在某个主题目录内检索（目录名来自 list_topics 的主题地图），如 "patterns"、"日志分析"。省略则全库检索。'],
            'k' => ['type' => 'number', 'description' => '返回片段数量，默认 5'],
        ], ['query']);
    }

    public function run(array $arguments, ToolSession $session): string
    {
        $session->ragSearchCalls++;

        $endpoint = $this->config['mcp']['rag'] ?? [];
        try {
            $result = McpClientFactory::call('rag_search', $arguments, $endpoint, $session);
        } catch (\Throwable $e) {
            $result = '工具调用失败: ' . $e->getMessage();
        }

        if ($session->ragSearchCalls + $session->webSearchCalls >= 6) {
            $result .= "\n\n[检索预算提示] 本次分析的知识库与网络检索合计已达约 6 次，请基于已有证据收敛并输出结论，未能核实的信息明确标注。";
        }

        return $result;
    }

    public function retryStrategy(): RetryStrategy
    {
        return RetryStrategy::network();
    }

    public function fallbackTools(): array
    {
        return ['web_search_exa'];
    }
}
