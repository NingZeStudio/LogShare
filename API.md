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

返回 Codex 解析引擎的结构化分析结果，包含服务端类型、版本、错误信息、堆栈跟踪等。

---

## 分析

### 直接分析日志内容

```
POST /1/analyse   （已弃用，保留兼容）
POST /v1/analyse
```

与 `GET /insights/{id}` 类似，但直接从请求体取内容而非读取已存储日志。请求格式同 `POST /log`。返回 Codex 结构化分析结果。

---

## AI 分析

当配置 `ai.agent.enabled` 为 `true` 时，AI 接口走 LogAgent（模型驱动工具循环）；否则保持旧版直连分析。SSE 事件协议见下。

### 基于已存储日志

```
GET /1/ai/{id}   （已弃用，保留兼容）
GET /v1/ai/{id}
```

SSE（Server-Sent Events）流式输出。LogAgent 模式下，Agent 可读取该日志 ID 下的所有文件（`list_log_files` / `read_log_file` 工具，作用域限定在当前 ID）。

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

**可注册的工具：**

| 工具 | 说明 | 注册条件 |
|------|------|----------|
| `web_search_exa` | Exa 网络搜索 | 配置 `ai.mcp.webSearch.url` |
| `rag_search` | 内置 RAG 知识库检索（SQLite FTS5） | 配置 `ai.mcp.rag.url` |
| `list_log_files` | 列出当前会话日志的文件 | 请求绑定日志 ID |
| `read_log_file` | 读取文件指定行区间 | 请求绑定日志 ID |

> RAG 为内置服务（`rag/` 目录），SQLite FTS5 纯本地检索。构建索引 `php bin/hyperf.php rag:build`；RAG MCP server 整合进 Hyperf 主进程的 `/rag` 路径，默认 `ai.mcp.rag.url = http://127.0.0.1:9501/rag`，数据库路径由 `ai.mcp.rag.db` 指定。

---

## 信息查询

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
                    { "pattern": "Username", "replacement": "********" },
                    { "pattern": "AccessToken", "replacement": "********" }
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