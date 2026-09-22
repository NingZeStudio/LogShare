# LogAgent 完整化改造 — 技术报告与开发计划

> 基于 LogShare v1.7.8 代码审查，对 AI 分析核心模块（LogAgent）进行结构化重构与能力增强。

---

## 一、现状诊断

### 1.1 代码分布

| 文件 | 行数 | 职责 |
|---|---|---|
| `app/Agent/LogAgent.php` | 1316 | 工具定义、系统提示词、多轮 tool loop、缓存锁、SSE 发射、日志窗口、grep/read、截断 |
| `app/Agent/ToolSession.php` | 43 | 会话状态（仅 4 个计数器 + 已读文件标记） |
| `app/Client/AIClient.php` | 580 | LLM 流式调用、SSE 解析、多 key 轮询、非流式回退 |
| `app/Client/MCPClient.php` | 237 | MCP 协议 HTTP 转发 |
| `app/Ai/AnalysisQueue.php` | 364 | 队列入队/出队/中继 |
| `app/Process/AiQueueConsumer.php` | 327 | 队列消费者进程 |
| **合计** | **~2,867** | |

### 1.2 核心问题

**（1）God Class：LogAgent.php 1316 行什么都干**

`buildTools()` 手写了 300+ 行 JSON Schema 字符串（工具定义）；`buildMessages()` 内嵌 200+ 行系统提示词 heredoc；多轮 tool loop、缓存锁、SSE 发射、日志窗口定位、grep/read 文件操作、结果截断——全部塞在一个静态类里。新增工具需要改这个方法，改提示词也在这个文件里，两个不相关的改动容易冲突。

**（2）ToolSession 是空壳**

43 行代码只记了 `readFiles`（防重复读取）、`ragSearchCalls` / `webSearchCalls` / `githubSearchCalls` / `githubDetailCalls` 四个计数器。没有对话历史摘要、没有工具调用链记录、没有置信度追踪、没有 token 预算管理。

**（3）提示词不可维护**

系统提示词是 `buildMessages()` 方法内的 heredoc 字符串，硬编码拼接。无法版本化、无法 A/B 测试、无法按分析模式切换（快速/深度/启动器排障）。每次调整提示词都要翻 1,316 行文件找位置。

**（4）没有 Tool Registry**

工具定义直接在 `buildTools()` 里手写数组。没有统一注册机制、没有参数校验层、没有调用分发抽象。新增一个工具要复制粘贴一大段数组结构。

**（5）没有结果验证层**

模型输出结论后直接 SSE 发回客户端。没有结构化校验：结论是否有工具调用支撑？未核实声明是否被标注？引用来源是否可追溯？

**（6）没有置信度/质量评分**

分析完成就完了。低质量分析（如工具调用循环、结论空洞、证据不足）和高质量分析在输出层面没有区别。

**（7）日志窗口一次性预扫描**

`buildInitialLogWindow()` 只在分析开始时做一次预扫描。多轮 tool call 过程中模型 grep/read 到的新锚点不会被纳入窗口调整，后期上下文可能脱离实际崩溃区域。

**（8）异常恢复粗放**

单轮工具调用失败只返回错误字符串。没有重试策略、没有 fallback 工具链（rag_search 无结果 → web_search → 标记未核实）、失败的工具不阻塞后续轮次。

**（9）静态方法堆砌**

LogAgent、AIClient、GitHubClient 几乎全是 `public static function`，没有依赖注入，没有接口契约。单元测试需要静态 mocking 或全局状态重置，测试成本高。

### 1.3 做得好的部分

- **AIClient 的 SSE 解析**处理了各种边界情况：空流检测、非流式回退、多网关兼容（index 缺失时的分片归属）、流内 error 帧识别
- **GitHubClient** 的多 Token 轮询 + 速率感知 + Redis 缓存 + 水帖过滤功能完整
- **Log 模型的缓存墓碑**和 **LRU 分析缓存**（32 条/32MB 预算）考虑到了 Swoole 常驻进程内存特性
- **RagSearch 的双路检索**（FTS5 BM25 + CJK bigram LIKE fallback + 可选向量语义）实现扎实

---

## 二、改造目标

### 2.1 架构目标

```
LogAgent.php          →  精简入口（~100 行），只做编排
AgentRuntime.php      →  多轮循环 + 停止条件 + 会话生命周期
PromptBuilder.php     →  提示词模板组装（可版本化、可切换模式）
ToolRegistry.php      →  工具注册、发现、参数校验、调用分发
Tool/                 →  每个工具一个类，实现 ToolInterface
ToolSession.php       →  富会话状态（调用链、置信度、token 预算）
LogWindowManager.php  →  动态日志窗口（锚点扩散）
ResultValidator.php   →  结论结构化验证
AnalysisScorer.php    →  分析质量评分
AnalysisTracer.php    →  完整链路追踪（可观测性）
```

### 2.2 能力目标

1. **可维护性**：最大文件 ≤ 350 行，新增工具无需修改 Agent 主体
2. **可观测性**：完整分析链路可追溯、可调试、可度量
3. **可靠性**：工具调用重试 + fallback 链，单点失败不阻塞整体分析
4. **质量**：结论结构化输出，含置信度评分和证据链
5. **动态性**：提示词可配置切换、可 A/B 测试、可热更新

---

## 三、详细设计

### 3.1 PromptBuilder — 提示词模板管理层

**问题**：当前系统提示词是 heredoc 硬编码在 `buildMessages()` 中，与业务逻辑耦合。

**设计**：

```php
final class PromptBuilder
{
    // 模板片段（可独立维护、可配置注入）
    private const FRAGMENTS = [
        'core'          => '核心身份与输出规范',
        'retrieval'     => '检索策略与预算规则',
        'github'        => 'GitHub 排障指引',
        'modloader'     => 'ModLoader 开发文档比对思维链',
        'mobile'        => '移动端启动器常识',
        'stop'          => '停止规则',
        'citation'      => '引用规范',
    ];

    // 分析模式
    public const MODE_QUICK    = 'quick';     // 快速分析：1-2 轮，主要靠 Codex
    public const MODE_DEEP     = 'deep';      // 深度分析：启用全部工具
    public const MODE_LAUNCHER = 'launcher';  // 启动器排障：优先 GitHub 工具链

    // 组装提示词
    public function build(string $mode, array $context): string;
    
    // 动态注入 topic 地图
    public function withTopics(string $topicsMap): self;
    
    // 版本化（支持 A/B 测试）
    public function withVersion(string $version): self;
}
```

**模式差异**：

| 模式 | 工具集 | 轮次上限 | 检索预算 |
|---|---|---|---|
| quick | 无（仅 Codex） | 1 | 0 |
| deep | 全部 | 50 | web≤5, total≤6 |
| launcher | GitHub + RAG | 20 | web≤3, github≤5, total≤6 |

### 3.2 ToolRegistry + ToolInterface — 工具统一注册与分发

**问题**：当前工具定义在 `buildTools()` 中手写数组，新增工具需要复制粘贴大段结构。

**设计**：

```php
interface ToolInterface
{
    public function name(): string;
    public function schema(): array;           // OpenAI function calling schema
    public function execute(array $args, ToolSession $session): string;
    public function retryStrategy(): RetryStrategy;
    public function fallbackTools(): array;    // 失败时 fallback 到哪些工具
}

abstract class AbstractTool implements ToolInterface
{
    // 默认重试：网络类 3 次（指数退避），本地类 1 次
    public function retryStrategy(): RetryStrategy { ... }
    public function fallbackTools(): array { return []; }
}

final class ToolRegistry
{
    // 注册工具
    public function register(ToolInterface $tool): void;
    
    // 按名称查找
    public function get(string $name): ?ToolInterface;
    
    // 获取全部 schema（传给 LLM）
    public function getSchemas(array $names = null): array;
    
    // 执行工具（含重试 + fallback）
    public function execute(string $name, array $args, ToolSession $session): ToolResult;
}
```

**8 个工具拆分**：

| 工具类 | 来源 | 行数预估 |
|---|---|---|
| `WebSearchTool` | 从 LogAgent::executeTool 拆分 | ~60 |
| `RagSearchTool` | 从 LogAgent + RagSearch 拆分 | ~80 |
| `ListTopicsTool` | 从 LogAgent::executeTool 拆分 | ~40 |
| `ReadLogTool` | 从 LogAgent::readLogFile 拆分 | ~80 |
| `GrepLogTool` | 从 LogAgent::grepLogFile 拆分 | ~80 |
| `ListLogFilesTool` | 从 LogAgent 拆分 | ~40 |
| `GithubSearchTool` | 从 GitHubClient 拆分 | ~100 |
| `GithubDetailTool` | 从 GitHubClient 拆分 | ~100 |

### 3.3 AgentRuntime — 多轮循环引擎

**问题**：`LogAgent::analyze()` 的 for 循环里混了 SSE 发射、工具执行、消息组装、缓存写入，循环控制和副作用交织。

**设计**：

```php
final class AgentRuntime
{
    public function run(
        AgentContext $ctx,      // content, cacheKey, logId, emitter
        ToolRegistry $tools,     // 工具集
        PromptBuilder $prompt,   // 提示词
        AnalysisTracer $tracer,  // 链路追踪
    ): AnalysisResult;
}

final class AgentContext
{
    public function __construct(
        public string $content,
        public ?string $cacheKey,
        public ?string $logId,
        public AnalysisEmitter $emitter,
    ) {}
}

final class AnalysisResult
{
    public function __construct(
        public string $fullAnswer,
        public bool $success,
        public int $rounds,
        public array $toolCallChain,    // 完整工具调用链
        public ?string $cacheKey,
        public array $metrics,          // 耗时、token、工具调用统计
    ) {}
}
```

**循环控制逻辑**：

```
for round = 0 .. maxRounds:
    1. 调用 LLM（传入 messages + tools schema）
    2. 流式发射 content/reasoning 到 SSE
    3. 收集 tool_calls
    4. 若无 tool_calls → 完成（success = true），break
    5. 若 tool_calls 中 name 为空 → 完成，break
    6. 逐个执行 tool_call：
       a. 从 ToolRegistry 获取工具
       b. 带重试策略执行
       c. 失败 → 尝试 fallback 工具
       d. 截断结果 → 写入 messages
       e. 记录到 ToolSession + AnalysisTracer
    7. 检查停止条件：
       - 已达 maxRounds → emitLimit, break
       - token 预算耗尽 → emitLimit, break
       - 检索预算耗尽 → 注入收敛提示，继续
```

**停止条件（分层）**：

| 条件 | 行为 |
|---|---|
| 模型无 tool_calls | 正常完成 |
| 轮次达到 maxRounds | 终止，标记超限 |
| web_search 调用 ≥ MAX_WEB_SEARCH_CALLS | 注入收敛提示，继续但不允许更多 web_search |
| 总检索调用 ≥ MAX_TOTAL_RETRIEVAL_CALLS | 注入收敛提示，继续但不允许更多检索 |
| 模型输出"分析完成"/"根因已确定"等停止信号 | 正常完成 |
| 上下文窗口即将耗尽 | 注入窗口压缩提示 |

### 3.4 ToolSession 增强 — 富会话状态

**当前**：43 行，4 个计数器 + readFiles 数组。

**增强后**：

```php
final class ToolSession
{
    // 已有
    public array $mcpClients = [];
    public array $readFiles = [];
    public int $ragSearchCalls = 0;
    public int $webSearchCalls = 0;
    public int $githubSearchCalls = 0;
    public int $githubDetailCalls = 0;

    // 新增
    public array $toolCallChain = [];        // 工具调用链 [{round, name, args, result, duration, verified}]
    public array $verifiedFacts = [];        // 已核实的事实集合
    public array $anchoredLines = [];        // 日志中已锚定的行号区间
    public int $estimatedTokens = 0;         // 估算已用 token 数
    public array $logWindowSummary = '';     // 当前日志窗口摘要（动态更新）
    public ?float $confidence = null;        // 整体置信度（分析完成后填充）
}
```

### 3.5 LogWindowManager — 动态日志窗口

**问题**：当前 `buildInitialLogWindow()` 只在开始时做一次预扫描。模型后续 grep/read 到的新锚点不会反馈到窗口。

**设计**：

```php
final class LogWindowManager
{
    // 初始窗口：基于异常信号预扫描（已有逻辑保留）
    public function buildInitialWindow(string $content): LogWindow;
    
    // 动态扩展：每轮 tool call 后，如果模型 grep/read 到了新锚点，
    // 向上下游扩展形成完整因果链视图
    public function expandWithAnchors(LogWindow $window, array $newAnchors): LogWindow;
    
    // 窗口压缩：接近 token 预算时，收缩到已锚定的高价值区域
    public function compress(LogWindow $window, int $targetTokens): LogWindow;
    
    // 生成窗口摘要（注入到下一轮 system prompt）
    public function summary(LogWindow $window): string;
}
```

**锚点扩散算法**：
- 输入：模型 grep 到的异常行号
- 扩展：向上游找堆栈入口（`at x.x.x` / `Caused by`），向下游找崩溃点（`[Server thread/ERROR]` / `SIGSEGV` / `exit code`）
- 形成连续行区间，标记为"已检查区域"
- 下一轮 prompt 中注入："以下区域已检查：行 120-180 无异常；行 240-310 发现 Mixin 注入失败"

### 3.6 ResultValidator — 结论结构化验证

```php
final class ResultValidator
{
    public function validate(string $answer, ToolSession $session): ValidationResult;
}

final class ValidationResult
{
    public function __construct(
        public bool $hasToolEvidence,       // 结论是否有工具调用支撑
        public array $unverifiedClaims,      // 未核实的声明列表
        public array $sourceTraceability,    // 引用来源可追溯性
        public array $structuredOutput,      // {rootCause, confidence, evidence[], steps[]}
    ) {}
}
```

**验证规则**：
1. 如果模型下了"根因是 X"的结论，检查是否有对应的 tool_call 支撑
2. 如果结论包含"可能是 X"，检查是否被标记为"未核实"
3. 如果引用了 GitHub Issue/PR 编号，检查是否有对应的 `github_get_content` 调用
4. 输出结构化 JSON，前端可以按"根因 / 证据 / 修复步骤"分栏展示

### 3.7 AnalysisScorer — 质量评分

```php
final class AnalysisScorer
{
    public function score(AnalysisContext $ctx): Score;
}

final class Score
{
    public function __construct(
        public int $toolEfficiency,      // 0-100：工具调用是否高效（无冗余循环）
        public int $evidenceSufficiency, // 0-100：证据是否充分
        public int $conclusionClarity,   // 0-100：结论是否明确
        public int $overall,             // 加权总分
        public array $issues,            // 扣分项说明
    ) {}
}
```

**评分触发**：
- 分析完成后自动评分
- 低分（< 60）触发 `AnalysisTracer` 记录，供管理员 review
- 极低分（< 40）可选择自动重分析（更换 prompt 版本或调整 temperature）

### 3.8 AnalysisTracer — 链路可观测性

```php
final class AnalysisTracer
{
    public function recordRound(int $round, array $messages, array $toolCalls, array $results, float $durationMs): void;
    public function recordToolCall(string $name, array $args, string $result, float $durationMs, bool $retried): void;
    public function recordError(string $stage, string $error): void;
    public function export(): array;  // 完整 trace JSON
}
```

**Trace 结构**：
```json
{
  "cacheKey": "ai:analysis:hash:xxx",
  "logId": "abc123",
  "model": "minimax-m2.5-free",
  "promptVersion": "v2",
  "mode": "deep",
  "startedAt": 1234567890,
  "finishedAt": 1234567950,
  "durationMs": 6000,
  "rounds": 4,
  "success": true,
  "rounds_detail": [
    {
      "round": 0,
      "tools_called": ["rag_search"],
      "tool_results": ["..."],
      "durationMs": 2300
    }
  ],
  "finalScore": { "overall": 78, ... },
  "validation": { "hasToolEvidence": true, "unverifiedClaims": [] }
}
```

### 3.9 AIClient 重构

当前 580 行，主要工作在 `streamChat()` 的 curl write callback 中。重构为：

```php
final class AIClient
{
    // 精简到 ~200 行
    public function stream(ChatRequest $req, StreamHandler $handler): void;
    public function chat(ChatRequest $req): ChatResponse;
}

final class ChatRequest { ... }    // 不可变请求值对象
final class ChatResponse { ... }   // 不可变响应值对象
final class SseParser { ... }      // SSE 解析器（从 write callback 中提取）
```

SSE 解析逻辑独立为 `SseParser`，可单独测试。

---

## 四、改造后文件结构

```
app/Agent/
├── LogAgent.php              # 入口编排（~100 行）
├── AgentRuntime.php          # 多轮循环 + 停止条件（~300 行）
├── PromptBuilder.php         # 提示词模板组装（~350 行）
├── ToolRegistry.php          # 工具注册与分发（~150 行）
├── Tool/
│   ├── ToolInterface.php
│   ├── AbstractTool.php
│   ├── WebSearchTool.php
│   ├── RagSearchTool.php
│   ├── ListTopicsTool.php
│   ├── ReadLogTool.php
│   ├── GrepLogTool.php
│   ├── ListLogFilesTool.php
│   └── GithubTool.php
├── ToolSession.php           # 富会话状态（~150 行）
├── LogWindowManager.php      # 动态日志窗口（~200 行）
├── ResultValidator.php       # 结论验证（~200 行）
├── AnalysisScorer.php        # 质量评分（~150 行）
└── AnalysisTracer.php        # 链路追踪（~200 行）

app/Client/
├── AIClient.php              # 精简 LLM 调用（~200 行，原 580）
├── SseParser.php             # 独立 SSE 解析器（新）
├── GitHubClient.php          # 不变（~953 行）
├── MCPClient.php             # 不变（~237 行）
└── ...

app/Ai/
├── AnalysisQueue.php         # 不变（~364 行）
└── ...

app/Rag/
├── RagManager.php            # 不变（~543 行）
├── RagSearch.php             # 不变（~932 行）
└── SemanticClient.php        # 不变（~203 行）
```

---

## 五、工作量估算

| 阶段 | 内容 | 预估行数 | 工作量 |
|---|---|---|---|
| 阶段一 | PromptBuilder + ToolRegistry + 工具拆分 | ~1,300 | 3-4h |
| 阶段二 | AgentRuntime + ToolSession 增强 + LogWindowManager | ~650 | 2h |
| 阶段三 | ResultValidator + AnalysisScorer | ~350 | 2h |
| 阶段四 | 工具重试/fallback + 降级链路 + AIClient 重构 | ~400 | 1-2h |
| 阶段五 | AnalysisTracer + 可观测性 | ~200 | 1h |
| **总计** | | **~2,900** | **9-11h** |

---

## 六、实施步骤

### Step 1：基础设施（先写，不碰现有逻辑）

1. 新建 `ToolInterface`、`AbstractTool`、`ToolResult`、`RetryStrategy`
2. 新建 `ToolRegistry`
3. 新建 `PromptBuilder`（从现有 heredoc 搬运内容，不修改措辞）
4. 新建 `SseParser`（从 AIClient 提取）
5. 写单测验证 ToolRegistry 注册/分发/参数校验

### Step 2：拆分工具（逐个迁移）

按依赖从低到高：`ListTopicsTool` → `ListLogFilesTool` → `GrepLogTool` → `ReadLogTool` → `RagSearchTool` → `WebSearchTool` → `GithubSearchTool` → `GithubDetailTool`

每拆一个，在 `LogAgent::buildTools()` 中保留原有逻辑但改为从 `ToolRegistry` 读取，确保行为完全一致。

### Step 3：提取 AgentRuntime

1. 新建 `AgentRuntime`，把 `analyze()` 中的 for 循环移进去
2. 把 SSE 发射逻辑封装为 `AnalysisEmitter` 接口（已有 `SseEmitter` / `StreamEmitter` / `AnalysisEmitter`，统一接入）
3. 集成 `PromptBuilder` 和 `ToolRegistry`
4. 写集成测试：模拟 3 轮 tool call，验证 SSE 输出与原来一致

### Step 4：增强会话与窗口

1. 扩展 `ToolSession`
2. 新建 `LogWindowManager`，接入多轮循环
3. 每轮 tool call 后更新锚点和窗口

### Step 5：结果验证与评分

1. 新建 `ResultValidator`，分析完成后自动验证
2. 新建 `AnalysisScorer`，自动评分
3. 结构化输出：`{ rootCause, confidence, evidence, steps }`

### Step 6：可观测性

1. 新建 `AnalysisTracer`，记录完整链路
2. Admin 后台新增"分析记录"页面（可选）
3. 指标埋点：各工具调用次数、各阶段耗时、缓存命中率

### Step 7：重构 AIClient

1. 提取 `SseParser`
2. 引入 `ChatRequest` / `ChatResponse` 值对象
3. 简化 `streamChat()` 入口

---

## 七、回滚与兼容策略

1. **渐进式替换**：每个阶段完成后 `LogAgent::analyze()` 的行为与之前完全一致（通过集成测试保证），新逻辑通过开关控制
2. **配置开关**：`ai.agent.newRuntime`（默认 false），验证无误后切 true
3. **回滚**：关开关即可回退到原有逻辑，不涉及数据库迁移或文件删除
4. **提示词版本**：`PromptBuilder` 支持多版本并存，A/B 测试或回滚只需改配置

---

## 八、验收标准

- [ ] 所有现有单测通过（`php vendor/bin/pest`）
- [ ] PHPStan Level 5 无新错误
- [ ] 集成测试：模拟 3 轮 tool call，SSE 输出与改造前逐帧一致
- [ ] 最大单个文件 ≤ 350 行
- [ ] 新增工具只需新增一个 Tool 类 + 注册一行代码，无需修改 AgentRuntime
- [ ] 提示词可在不修改代码的情况下通过配置切换模式
- [ ] 分析结果输出结构化 JSON（rootCause / confidence / evidence / steps）
- [ ] 完整分析 trace 可导出为 JSON
- [ ] 工具调用失败时自动 fallback，不阻塞整体分析
