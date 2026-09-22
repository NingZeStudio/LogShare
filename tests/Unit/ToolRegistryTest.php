<?php

use App\Agent\ToolSession;
use App\Agent\ToolRegistry;
use App\Agent\Tool\AbstractTool;
use App\Agent\Tool\RetryStrategy;
use App\Agent\Tool\ToolExecutionException;
use App\Agent\Tool\ToolResult;

/**
 * 可控测试工具：run 行为、重试策略、降级链均由参数注入。
 * 用匿名类工厂避免类重声明，并记录调用次数供断言。
 */
function makeTool(
    string $name,
    callable $run,
    ?RetryStrategy $retry = null,
    array $fallbacks = [],
    string $description = 'd',
    array $params = [],
): AbstractTool {
    return new class($name, $run, $retry, $fallbacks, $description, $params) extends AbstractTool {
        public int $calls = 0;

        public function __construct(
            private string $n,
            private $runFn,
            private ?RetryStrategy $r,
            private array $fb,
            private string $desc,
            private array $ps,
        ) {
        }

        public function name(): string
        {
            return $this->n;
        }

        protected function description(): string
        {
            return $this->desc;
        }

        protected function parameterSchema(): array
        {
            return $this->objectSchema($this->ps);
        }

        public function run(array $arguments, ToolSession $session): string
        {
            $this->calls++;

            return ($this->runFn)($arguments, $session, $this);
        }

        public function retryStrategy(): RetryStrategy
        {
            return $this->r ?? RetryStrategy::none();
        }

        public function fallbackTools(): array
        {
            return $this->fb;
        }
    };
}

function newSession(): ToolSession
{
    return new ToolSession();
}

beforeEach(function () {
    // 把所有退避 sleep 变成 no-op，加速重试相关断言
    $this->registry = new ToolRegistry();
    $this->registry->overrideSleeper(static fn (int $us) => null);
});

test('registry returns empty tool list when nothing registered', function () {
    expect($this->registry->names())->toBe([]);
    expect($this->registry->schemas())->toBe([]);
    expect($this->registry->has('web_search_exa'))->toBeFalse();
});

test('register + schemas aggregates definitions in order', function () {
    $a = makeTool('tool_a', fn () => 'x', params: ['q' => ['type' => 'string']], description: 'desc-a');
    $b = makeTool('tool_b', fn () => 'y');
    $this->registry->register($a);
    $this->registry->register($b);

    expect($this->registry->names())->toBe(['tool_a', 'tool_b']);
    $schemas = $this->registry->schemas();
    expect($schemas)->toHaveCount(2);
    expect($schemas[0]['type'])->toBe('function');
    expect($schemas[0]['function']['name'])->toBe('tool_a');
    expect($schemas[0]['function']['description'])->toBe('desc-a');
    expect($schemas[0]['function']['parameters']['properties'])->toHaveKey('q');
});

test('schemas filter by given names, ignoring unknown', function () {
    $this->registry->register(makeTool('tool_a', fn () => 'x'));
    $this->registry->register(makeTool('tool_b', fn () => 'y'));

    $schemas = $this->registry->schemas(['tool_b', 'ghost']);
    expect($schemas)->toHaveCount(1);
    expect($schemas[0]['function']['name'])->toBe('tool_b');
});

test('execute dispatches to the right tool and returns success result', function () {
    $this->registry->register(makeTool('echo', fn ($args) => 'got:' . ($args['v'] ?? '?')));

    $result = $this->registry->execute('echo', ['v' => 'hi'], newSession());
    expect($result)->toBeInstanceOf(ToolResult::class);
    expect($result->ok)->toBeTrue();
    expect($result->content)->toBe('got:hi');
    expect($result->producedBy)->toBe('echo');
    expect($result->attempts)->toBe(1);
    expect($result->retried)->toBeFalse();
});

test('execute unknown tool yields failure without throwing', function () {
    $result = $this->registry->execute('nope', [], newSession());
    expect($result->ok)->toBeFalse();
    expect($result->content)->toContain('未知工具');
    expect($result->error)->toBe('not_registered');
});

test('transient failure retries up to strategy then succeeds', function () {
    $tool = makeTool(
        'flaky',
        function ($args, $session, $t) {
            if ($t->calls < 3) {
                throw new ToolExecutionException('boom');
            }
            return 'recovered';
        },
        retry: RetryStrategy::network(3, 10)
    );
    $this->registry->register($tool);

    $result = $this->registry->execute('flaky', [], newSession());
    expect($result->ok)->toBeTrue();
    expect($result->content)->toBe('recovered');
    expect($result->attempts)->toBe(3);
    expect($result->retried)->toBeTrue();
});

test('exhausted retries fall back to next tool when it succeeds', function () {
    $primary = makeTool(
        'rag_search',
        function () {
            throw new ToolExecutionException('no result');
        },
        retry: RetryStrategy::network(2, 5),
        fallbacks: ['web_search_exa']
    );
    $fallback = makeTool('web_search_exa', fn () => 'web answer');
    $this->registry->register($primary);
    $this->registry->register($fallback);

    $result = $this->registry->execute('rag_search', [], newSession());
    expect($result->ok)->toBeTrue();
    expect($result->content)->toBe('web answer');
    // producedBy 反映真正命中的降级工具
    expect($result->producedBy)->toBe('web_search_exa');
    expect($result->retried)->toBeTrue();
    expect($fallback->calls)->toBe(1);
});

test('all fallbacks failing returns original failure text', function () {
    $primary = makeTool(
        'a',
        fn () => throw new ToolExecutionException('a-down'),
        fallbacks: ['b']
    );
    $fb = makeTool('b', fn () => throw new ToolExecutionException('b-down'));
    $this->registry->register($primary);
    $this->registry->register($fb);

    $result = $this->registry->execute('a', [], newSession());
    expect($result->ok)->toBeFalse();
    expect($result->content)->toContain('工具调用失败');
    expect($result->content)->toContain('a-down');
});

test('fallback cycle is prevented', function () {
    // a -> b -> a；visited 去重后不得无限递归
    $a = makeTool('a', fn () => throw new ToolExecutionException('A'), fallbacks: ['b']);
    $b = makeTool('b', fn () => throw new ToolExecutionException('B'), fallbacks: ['a']);
    $this->registry->register($a);
    $this->registry->register($b);

    $result = $this->registry->execute('a', [], newSession());
    expect($result->ok)->toBeFalse();
    expect($result->content)->toContain('A');
    expect($b->calls)->toBe(1);
});

test('non-exception hard error is returned as text without retry', function () {
    // 硬性错误直接 return 文本（不抛异常）：attempts=1、ok=true（模型侧看到可读文本）
    $tool = makeTool('grep_log_file', fn () => '检索关键词 query 不能为空', retry: RetryStrategy::network(3, 5));
    $this->registry->register($tool);

    $result = $this->registry->execute('grep_log_file', [], newSession());
    expect($result->ok)->toBeTrue();
    expect($result->content)->toBe('检索关键词 query 不能为空');
    expect($result->attempts)->toBe(1);
    expect($tool->calls)->toBe(1);
});

test('unexpected throwable inside tool is treated as hard failure (no retry, no leak)', function () {
    $tool = makeTool(
        'crashy',
        function () {
            throw new \RuntimeException('secret stack detail');
        },
        retry: RetryStrategy::network(3, 5)
    );
    $this->registry->register($tool);

    $result = $this->registry->execute('crashy', [], newSession());
    expect($result->ok)->toBeFalse();
    expect($result->content)->toContain('工具调用失败');
    // 不重试：只调用一次
    expect($tool->calls)->toBe(1);
});

test('RetryStrategy exposes nextDelayMs backoff and stop', function () {
    $s = RetryStrategy::network(3, 200);
    expect($s->nextDelayMs(1))->toBe(200);
    expect($s->nextDelayMs(2))->toBe(400);
    // 已达最大尝试次数 -> 不再重试
    expect($s->nextDelayMs(3))->toBe(0);
    expect(RetryStrategy::none()->nextDelayMs(1))->toBe(0);
});
