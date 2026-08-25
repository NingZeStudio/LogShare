# LogShare API

基础 URL：由配置 `urls.apiBaseUrl` 决定。

同时提供 `/1/`（已弃用）和 `/v1/` 端点。建议新集成使用 `/v1/`。旧版 `/1/` 在过渡期内保持兼容。

完整 OpenAPI 3.1 规范见 [`openapi.yaml`](openapi.yaml)。

---

## 日志管理

### 上传日志

```
POST /1/log   （已弃用，保留兼容）
POST /v1/log
```

**Content-Type：** `application/x-www-form-urlencoded` 或 `application/json`。  
**Content-Encoding：** 支持 `gzip`、`x-gzip`、`deflate`（可叠加，最多 5 层）。

**请求字段（JSON）：**

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `content` | string | 是* | 日志内容（多文件上传时可为空，见下） |
| `files` | array | 否 | 附加文件数组，每个元素 `{name, content}` |
| `metadata[]` | array | 否 | 元数据 |
| `source` | string | 否 | 来源标识（最长 64 字符） |

\* 当提供 `files` 时 `content` 可省略，主文件取 `files[0]`。

**多文件上传：**

每个 `files[].content` 可为纯文本，也可为 ZIP 压缩包（`name` 以 `.zip` 结尾时自动展开为多个文件，保留内部相对路径）：

```json
{
    "content": "主文件内容（可选）",
    "files": [
        { "name": "crash-reports/crash-01.txt", "content": "---- Minecraft Crash Report ----\n..." },
        { "name": "server-logs.zip", "content": "<zip 二进制内容>" }
    ]
}
```

- 展开后每个文件独立经过脱敏过滤链
- 上限：文件数 ≤ 200，解压后累计 ≤ 12MB（`storage.uploadFiles`）
- ZIP 条目名会做路径遍历防护（拒绝 `../`、绝对路径）

**响应：**

```json
{
    "success": true,
    "message": "Log submitted successfully",
    "id": "sAbCdEf",
    "url": "https://logshare.cn/sAbCdEf",
    "raw": "https://api.logshare.cn/1/raw/sAbCdEf",
    "token": "f3a2b1c4d5e6..."
}
```

> `/v1/log` 返回格式相同，`raw` URL 指向 `/v1/raw/`。

`token` 是删除该日志的唯一凭证，请自行保存。

---

### 删除日志

```
DELETE /1/log/{id}   （已弃用，保留兼容）
DELETE /v1/log/{id}
```

**鉴权：** `Authorization: Bearer <token>`（token 来自上传响应）。  
**多 ID：** 逗号分隔，如 `DELETE /v1/log/id1,id2`。

**响应：**

```json
{
    "success": true,
    "message": "Log deletion completed",
    "deleted": ["sAbCdEf"],
    "failed": [],
    "total": 1,
    "deletedCount": 1,
    "failedCount": 0
}
```

失败时 `failed` 数组包含每个失败的 ID、原因和 HTTP 状态码。

---

## 日志获取

### 获取原始日志

```
GET /1/raw/{id}   （已弃用，保留兼容）
GET /v1/raw/{id}
```

返回 `Content-Type: text/plain; charset=utf-8`，直接输出日志原文（多文件日志返回主文件）。

### 获取附加文件

```
GET /1/raw/{id}/{filename}   （已弃用，保留兼容）
GET /v1/raw/{id}/{filename}
```

`{filename}` 支持子路径，需 URL 编码：

```
GET /v1/raw/sAbCdEf/crash-reports/crash-01.txt
```

返回该文件原文（`text/plain`）。文件不存在返回 404。路径会做遍历防护（拒绝 `../`、绝对路径）。

### 获取日志元信息与文件列表

```
GET /1/log/{id}   （已弃用，保留兼容）
GET /v1/log/{id}
```

返回日志元信息及附加文件列表（不含内容）：

```json
{
    "success": true,
    "message": "Log metadata retrieved successfully",
    "id": "sAbCdEf",
    "size": 4096,
    "lines": 120,
    "created": 1755200000,
    "expires": 1755804800,
    "metadata": [],
    "source": "minecraft-server",
    "files": [
        { "name": "crash-reports/crash-01.txt", "size": 2048 }
    ],
    "raw": "https://api.logshare.cn/v1/raw/sAbCdEf"
}
```

---

### 获取分析结果

```
GET /1/insights/{id}   （已弃用，保留兼容）
GET /v1/insights/{id}
```

返回 Codex 解析引擎的结构化分析结果，包含服务端类型、版本、错误信息、堆栈跟踪等：

```json
{
    "id": "vanilla/server",
    "name": "Vanilla",
    "type": "Server Log",
    "version": "1.21.9",
    "title": "Vanilla 1.21.9 Server Log",
    "analysis": {
        "problems": [
            { "message": "...", "counter": 1, "solutions": ["..."] }
        ],
        "information": [
            { "message": "Minecraft version: 1.21.9", "counter": 1, "label": "Minecraft version", "value": "1.21.9" }
        ]
    }
}
```

> `analysis.problems` 为诊断出的问题（含解决方案建议），`analysis.information` 为提取的信息（版本、类型等，含 `label`/`value`）。响应不包含 `entries`（原始日志行），内容紧凑。

---

## 分析

### 直接分析日志内容

```
POST /1/analyse   （已弃用，保留兼容）
POST /v1/analyse
```

与 `GET /insights/{id}` 类似，但直接从请求体取内容而非读取已存储日志。请求格式同 `POST /log`。返回 Codex 结构化分析结果（结构同上「获取分析结果」）。

---

## AI 分析

> **禁用开关**：配置 `ai.enabled = false`（或环境变量 `AI_ENABLED=false`）时，所有 `/v1/ai/*` 接口统一返回 HTTP 404（`{"success":false,"error":"AI analysis is disabled.","code":404}`）。默认 `true` 启用。

当配置 `ai.agent.enabled` 为 `true` 时，AI 接口走 LogAgent（模型驱动工具循环）；否则保持旧版直连分析。SSE 事件协议见下。

### 基于已存储日志

```
GET /1/ai/{id}   （已弃用，保留兼容）
GET /v1/ai/{id}
```

SSE（Server-Sent Events）流式输出。LogAgent 模式下，Agent 可读取该日志 ID 下的所有文件（`list_log_files` / `read_log_file` 工具，作用域限定在当前 ID）。`GET /v1/ai/{id}` 会绑定该 ID；`POST /v1/ai/analyse` 只有请求 JSON 提供 `id` 时才会开放文件工具。

### 直接提交内容

```
POST /1/ai/analyse   （已弃用，保留兼容）
POST /v1/ai/analyse
```

不落盘，直接提交内容给 AI 分析。请求格式同 `POST /log`。SSE 流式输出，缓存基于内容哈希（30 分钟 TTL）。

**可选字段 `id`：** 传入已存在的日志 ID 时，Agent 获得该日志文件的访问权（会话作用域），可用于多文件对比；`content` 可省略（缺省读取该 ID 主文件）。缓存键基于该 ID。

```json
{
    "content": "可选，附加分析内容",
    "id": "sAbCdEf"
}
```

### SSE 事件协议

LogAgent 模式（`ai.agent.enabled`）会输出额外的 `event: status` 事件，旧的 `data:`（正文增量）与 `event: done` 保持不变，兼容只读旧协议的客户端。

| 事件 | 载荷 `data` | 说明 |
|------|-------------|------|
| `event: status` | `{"type":"thinking","delta":"..."}` | 模型思维链（reasoning_content）逐段推送，供前端展示 |
| `event: status` | `{"type":"tool","name":"web_search_exa","arguments":{...}}` | 即将调用某工具 |
| `event: status` | `{"type":"tool_result","name":"web_search_exa","summary":"...","truncated":true}` | 工具返回摘要（完整结果进 LLM 上下文） |
| `event: status` | `{"type":"limit","rounds":3}` | 达到工具循环上限 |
| `data:`（原有） | `{"choices":[{"delta":{"content":"..."}}]}` | 正文增量 |
| `event: done` | `{"status":"completed"}` | 流结束 |

**前端 SSE 解析注意事项：** 一个 SSE 事件以空行（`\n\n`）结束；`event: status` 后紧跟其 `data:` JSON，未声明 `event:` 的 `data:` 行是正文增量。正文应拼接 `data.choices[0].delta.content`，思考内容拼接 `data.delta`。除 `done` 外还需处理 `event: error`（`data.error`）和 `event: status` 的 `tool`、`tool_result`、`limit`。
**可注册的工具：** 工具会作为 OpenAI-compatible `tools` 字段发送给模型；只有满足注册条件时才会出现在该次会话中。工具调用过程本身不会作为客户端请求发送，前端只接收对应的 SSE 状态事件。

| 工具 | 注册条件 | 参数 | 返回给模型的内容 |
|------|----------|------|------------------|
| `web_search_exa` | `ai.mcp.webSearch.url` 非空 | `query: string`（必填，错误类名、报错关键词或 mod 名称） | Exa MCP 文本搜索结果，多个文本块以空行拼接 |
| `rag_search` | `ai.mcp.rag.url` 非空 | `query: string`（必填）；`k: number`（可选，默认 5，服务端限制 1–20） | SQLite FTS5/BM25 知识库结果，包含标题、来源、片段和分数 |
| `list_topics` | `ai.mcp.rag.url` 非空 | 无参数，`properties: {}` | 知识库主题目录、文档数量和示例文件名 |
| `list_log_files` | 当前会话绑定日志 ID | 无参数，`properties: {}` | 主文件 `main` 及附加文件的名称、字节数、行数 |
| `read_log_file` | 当前会话绑定日志 ID | `filename: string`（必填） | 返回该文件的**完整内容**（无行区间参数）；单次字节上限由 `ai.agent.maxFileBytes` 控制（默认 512 KiB），超出时截断并附提示；同一会话内重复读取同一文件会被拒绝并返回提示 |

### 工具定义示例

```json
[
  {"type":"function","function":{"name":"web_search_exa","description":"搜索互联网，查找 Minecraft 报错信息、mod 兼容性等解决方案。返回与查询相关的网页内容。","parameters":{"type":"object","properties":{"query":{"type":"string","description":"搜索关键词，使用错误类名或报错关键词"}},"required":["query"]}}},
  {"type":"function","function":{"name":"rag_search","description":"在内部知识库中检索相关文档片段。用于查找已知错误与解决方案。","parameters":{"type":"object","properties":{"query":{"type":"string","description":"检索关键词"},"k":{"type":"number","description":"返回片段数量，默认 5"}},"required":["query"]}}},
  {"type":"function","function":{"name":"list_topics","description":"列出内部知识库涵盖的主题与文档分布。在不知道检索方向、或搜索无结果时，先调用本工具了解知识库有什么，再针对性搜索。","parameters":{"type":"object","properties":{}}}},
  {"type":"function","function":{"name":"list_log_files","description":"列出当前日志 ID 下的所有文件（含主文件与附加文件）。","parameters":{"type":"object","properties":{}}}},
  {"type":"function","function":{"name":"read_log_file","description":"读取当前日志下指定文件的完整内容（不设行区间，直接返回全文）。为避免重复调用，仅对未读取过的文件调用；已读过的文件使用已内容进行分析，不要再次读取。主文件名为 main。","parameters":{"type":"object","properties":{"filename":{"type":"string","description":"文件名（主文件为 main，或使用 list_log_files 列出的名称）"}},"required":["filename"]}}}
]
```

工具执行失败不会终止整个 Agent 循环：错误文本会作为 `role: tool` 消息返回模型，由模型决定重试、换工具或直接给出结论。

**重复读取防护：** 同一分析会话内，模型对同一文件的第二次 `read_log_file` 调用不会返回文件内容，而是收到提示「文件 X 已读取（共 N 行，M 字节），其内容已在上文中提供，请直接基于已有内容进行分析，不要重复调用本工具」。`filename` 缺省与 `main` 视为同一文件。该机制在服务端强制执行，用于消除模型反复查看同一日志的循环行为。

**工具结果截断规则：**

- `read_log_file` 的全文结果**不受**通用 12KB 工具截断限制，完整进入模型上下文（仅受 `ai.agent.maxFileBytes` 字节上限约束，超限时有明确截断提示）。
- 其他工具（搜索/知识库检索）的单次结果超过 12KB 时会截断，且截断处附带可见标记 `[...工具结果过长，已截断至 N 字节...]`，模型可据此决定调整参数重新查询。
- 用户消息中的内联日志同样按 12KB 截断，并提示模型可用文件工具读取完整内容。

**工具调用兼容细节：**

- 服务端会过滤没有 `name` 的空工具调用，避免将无效 tool call 转发给模型网关。
- 无参数工具（`list_topics`、`list_log_files`）发送给上游时，`function.arguments` 统一为 JSON 字符串 `{}`，不是空字符串。
- `tool_call_id` 会原样用于后续 `role: tool` 消息；前端无需自行生成或修改该字段。
- 同一 Agent 请求内，相同 MCP endpoint 会复用已初始化的 MCP 会话；不同请求不会共享会话。
- `maxToolRounds` 是完整 Agent 轮次上限；达到上限时发送 `event: status`，其 `data` 为 `{"type":"limit","rounds":N}`，随后仍发送 `event: done`。
- `reasoning_content` 只有上游模型实际返回时才会产生 `thinking` 事件；模型不返回推理增量时不会人为生成思考内容。


> RAG 为内置服务（`rag/` 目录），SQLite FTS5 纯本地检索。构建索引 `php bin/hyperf.php rag:build`；RAG MCP server 整合进 Hyperf 主进程的 `/rag` 路径，默认 `ai.mcp.rag.url = http://127.0.0.1:9501/rag`，数据库路径由 `ai.mcp.rag.db` 指定。

---

## RAG MCP 服务（内置知识库检索）

内置于 Hyperf 主进程的 **Streamable HTTP MCP 服务**（JSON-RPC 2.0），提供纯本地 SQLite FTS5 知识库检索（零网络、零 embedding）。数据库路径由 `ai.mcp.rag.db` 指定（默认 `rag/index.db`），构建索引：`php bin/hyperf.php rag:build`。

### 端点

```
POST /rag   （同时接受 GET）
```

### JSON-RPC 方法

| 方法 | 说明 |
|------|------|
| `initialize` | MCP 握手，返回协议版本与服务信息 |
| `tools/list` | 列出可用工具（`rag_search` / `list_topics`） |
| `tools/call` | 调用工具 |
| `ping` | 健康探测（返回 `{}`） |

### initialize

请求：

```json
{ "jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {} }
```

响应：

```json
{
    "jsonrpc": "2.0",
    "id": 1,
    "result": {
        "protocolVersion": "2025-03-26",
        "capabilities": { "tools": { "listChanged": false } },
        "serverInfo": { "name": "logshare-rag", "version": "1.7.0" }
    }
}
```

### tools/list

请求：

```json
{ "jsonrpc": "2.0", "id": 2, "method": "tools/list" }
```

响应：

```json
{
    "jsonrpc": "2.0",
    "id": 2,
    "result": {
        "tools": [
            {
                "name": "rag_search",
                "description": "在内部知识库中检索相关文档片段。用于查找已知错误与解决方案。",
                "inputSchema": {
                    "type": "object",
                    "properties": {
                        "query": { "type": "string", "description": "检索关键词，使用错误类名或报错关键词" },
                        "k": { "type": "number", "description": "返回片段数量，默认 5" }
                    },
                    "required": ["query"]
                }
            },
            {
                "name": "list_topics",
                "description": "列出知识库涵盖的主题与文档分布，帮助你决定检索方向。搜索前可先调用本工具了解知识库有什么。",
                "inputSchema": { "type": "object", "properties": {} }
            }
        ]
    }
}
```

### tools/call —— rag_search

请求：

```json
{
    "jsonrpc": "2.0",
    "id": 3,
    "method": "tools/call",
    "params": { "name": "rag_search", "arguments": { "query": "OutOfMemoryError", "k": 5 } }
}
```

响应（检索结果以纯文本汇总在 `content[0].text`）：

```json
{
    "jsonrpc": "2.0",
    "id": 3,
    "result": {
        "content": [
            { "type": "text", "text": "在知识库中找到 2 条相关文档：\n\n[1] 内存溢出（来源: ...）\n    ...\n" }
        ]
    }
}
```

### tools/call —— list_topics

请求：

```json
{
    "jsonrpc": "2.0",
    "id": 4,
    "method": "tools/call",
    "params": { "name": "list_topics", "arguments": {} }
}
```

响应：

```json
{
    "jsonrpc": "2.0",
    "id": 4,
    "result": {
        "content": [
            { "type": "text", "text": "知识库共 N 个主题目录、M 个分块：\n\n■ ...\n" }
        ]
    }
}
```

### 错误码

| 错误码 | 含义 |
|--------|------|
| `-32700` | 请求不是合法 JSON-RPC |
| `-32602` | 工具不存在 / 参数错误 |
| `-32603` | 数据库不可用 |

---

## 信息查询

### 根端点

```
GET /
```

返回全部可用端点的列表：

```json
{
    "success": true,
    "message": "LogShare API",
    "endpoints": ["POST /v1/log", "GET /v1/raw/{id}", "..."]
}
```

### 速率限制

```
GET /1/limits   （已弃用，保留兼容）
GET /v1/limits
```

**响应：**

```json
{
    "maxLength": 10485760,
    "maxLines": 50000,
    "storageTime": 604800
}
```

### 过滤器列表

```
GET /1/filters   （已弃用，保留兼容）
GET /v1/filters
```

过滤器清单由 `filter.pre` 配置动态生成，始终与实际上链路一致；以下为默认配置的完整输出。

**响应：**

```json
{
    "success": true,
    "filters": [
        { "type": "trim", "data": null },
        { "type": "limit-bytes", "data": { "limit": 10485760 } },
        { "type": "limit-lines", "data": { "limit": 50000 } },
        {
            "type": "regex",
            "data": {
                "patterns": [
                    { "pattern": "IPv4", "replacement": "**.**.**.**" },
                    { "pattern": "IPv6", "replacement": "****:****:****:****:****:****:****:****" },
                    { "pattern": "IPv6Short", "replacement": "****:****:****:****:****:****:****:****" },
                    { "pattern": "Uuid", "replacement": "********-****-****-****-************" },
                    { "pattern": "Xuid", "replacement": "xuid:\"****************\"" },
                    { "pattern": "SessionToken", "replacement": "accessToken:\"********\"" },
                    { "pattern": "ClientId", "replacement": "clientId:\"********\"" },
                    { "pattern": "Coordinate", "replacement": "BlockPos(*****, *****, *****)" },
                    { "pattern": "Username", "replacement": "C:\\Users\\********\\" },
                    { "pattern": "AccessToken", "replacement": "accessToken:\"********\"" }
                ]
            }
        }
    ]
}
```

### 速率错误测试

```
GET /1/errors/rate   （已弃用，保留兼容）
GET /v1/errors/rate
```

始终返回 HTTP 429，用于测试限速错误处理。

---

## 通用响应格式

**成功：**

```json
{
    "success": true,
    "message": "OK",
    ...
}
```

**错误：**

```json
{
    "success": false,
    "error": "错误描述",
    "code": 400
}
```

## 状态码

| 状态码 | 说明 |
|--------|------|
| 200 | 请求成功 |
| 400 | 请求参数错误 |
| 401 | 缺少或无效的认证信息 |
| 403 | 权限不足（Token 不匹配） |
| 404 | 日志不存在 |
| 405 | 请求方法不允许 |
| 413 | 请求体过大 |
| 415 | 不支持的 Content-Type 或 Content-Encoding |
| 429 | 速率限制触发 |
| 500 | 服务器内部错误 |