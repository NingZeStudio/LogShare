<?php

declare(strict_types=1);

namespace App\Agent\Tool;

use App\Agent\ToolSession;

/**
 * 工具基类：封装「组装 function-calling 定义」的样板与默认策略。
 *
 * 子类只需实现 name()/description()/parameterSchema()/run() 三个语义片段，
 * 定义结构（type=function 包裹）由本类统一生成，避免每个工具重复粘贴数组壳。
 * 网络类工具重写 retryStrategy() 返回 RetryStrategy::network()，
 * 需要降级链的工具重写 fallbackTools()。
 */
abstract class AbstractTool implements ToolInterface
{
    abstract protected function description(): string;

    /** 参数 JSON Schema（type=object 的 properties/required 部分） */
    abstract protected function parameterSchema(): array;

    abstract public function name(): string;

    abstract public function run(array $arguments, ToolSession $session): string;

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => $this->parameterSchema(),
            ],
        ];
    }

    public function retryStrategy(): RetryStrategy
    {
        // 默认不重试：本地读取与确定性调用适用，网络类工具显式重写
        return RetryStrategy::none();
    }

    public function fallbackTools(): array
    {
        return [];
    }

    /** 构造 object 型参数 schema；properties 传空数组时以 stdClass 保证 JSON 输出为 {} */
    final protected function objectSchema(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_values($required),
        ];
    }
}
