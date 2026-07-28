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
| `content` | string | 是 | 日志内容 |
| `metadata[]` | array | 否 | 元数据 |
| `source` | string | 否 | 来源标识（最长 64 字符） |

**响应：**

```json
{
    "success": true,
    "message": "Log submitted successfully",
    "id": "mAbCdE",
    "url": "https://logshare.cn/mAbCdE",
    "raw": "https://api.logshare.cn/1/raw/mAbCdE",
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
    "deleted": ["mAbCdE"],
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

返回 `Content-Type: text/plain; charset=utf-8`，直接输出日志原文。

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

### 基于已存储日志

```
GET /1/ai/{id}   （已弃用，保留兼容）
GET /v1/ai/{id}
```

SSE（Server-Sent Events）流式输出。连接建立后持续推送 `data:` 行，以 `event: done` 结束。

### 直接提交内容

```
POST /1/ai/analyse   （已弃用，保留兼容）
POST /v1/ai/analyse
```

不落盘，直接提交内容给 AI 分析。请求格式同 `POST /log`。SSE 流式输出，缓存基于内容哈希（30 分钟 TTL）。

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
    "success": true,
    "message": "OK",
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
    "message": "OK",
    "filters": [
        "\\Filter\\TrimFilter",
        "\\Filter\\LimitBytesFilter",
        "\\Filter\\LimitLinesFilter",
        "\\Filter\\IPv4Filter",
        "\\Filter\\IPv6Filter",
        "\\Filter\\IPv6ShortFilter",
        "\\Filter\\UuidFilter",
        "\\Filter\\XuidFilter",
        "\\Filter\\SessionTokenFilter",
        "\\Filter\\ClientIdFilter",
        "\\Filter\\CoordinateFilter",
        "\\Filter\\UsernameFilter",
        "\\Filter\\AccessTokenFilter"
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