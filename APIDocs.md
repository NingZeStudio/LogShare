# LogShare-v1 API 文档

## 基础信息

- **Base URL**: `https://api.logshare.cn`（生产环境）
- **Content-Type**: `application/json` 或 `application/x-www-form-urlencoded`
- **Accept-Encoding**: 支持 `deflate`、`gzip`、`x-gzip`

所有响应均为 JSON 格式，除非明确返回 `text/plain`。

---

## 通用响应格式

### 成功响应

```json
{
    "success": true,
    "message": "...",
    // 其他数据字段
}
```

### 错误响应

```json
{
    "success": false,
    "error": "错误描述",
    "code": 400
}
```

---

## 端点列表

### 1. 获取 API 信息

- **Method**: `GET`
- **Path**: `/`
- **描述**: 返回可用端点列表及基本说明
- **响应示例**:

```json
{
    "message": "Welcome to the API...",
    "endpoints": { ... }
}
```

---

### 2. 提交日志

- **Method**: `POST`
- **Path**: `/1/log`
- **描述**: 提交日志内容，系统会自动应用隐私过滤器并存储
- **请求体**:

```json
{
    "content": "[Server thread/INFO]: Server started...",
    "metadata": [
        {
            "key": "version",
            "value": "1.20.1",
            "label": "Minecraft Version",
            "visible": true
        }
    ],
    "source": "client-name"
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `content` | string | 是 | 日志原始内容 |
| `metadata` | array | 否 | 元数据数组 |
| `source` | string | 否 | 日志来源标识（最大 64 字符） |

- **响应示例**:

```json
{
    "success": true,
    "message": "Log submitted successfully",
    "id": "aBc123",
    "url": "https://logshare.cn/aBc123",
    "raw": "https://api.logshare.cn/1/raw/aBc123",
    "token": "xxxxxxxxxxxxxxxx"
}
```

- **注意**: `token` 用于后续删除日志，请妥善保存。

---

### 3. 分析日志（不落盘）

- **Method**: `POST`
- **Path**: `/1/analyse`
- **描述**: 直接提交日志内容进行 Aternos Codex 分析，不存储到数据库
- **请求体**:

```json
{
    "content": "[Server thread/INFO]: Starting minecraft server version 1.20.1"
}
```

- **响应**: 返回 Codex 分析结果（JSON），包含检测到的日志类型、问题、版本信息等。

---

### 4. 获取限制信息

- **Method**: `GET`
- **Path**: `/1/limits`
- **描述**: 获取日志存储的限制参数
- **响应示例**:

```json
{
    "storageTime": 604800,
    "maxLength": 10485760,
    "maxLines": 50000
}
```

---

### 5. 获取过滤器信息

- **Method**: `GET`
- **Path**: `/1/filters`
- **描述**: 获取当前启用的前置过滤器列表
- **响应示例**:

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

---

### 6. 获取错误率限制信息

- **Method**: `GET`
- **Path**: `/1/errors/rate`
- **描述**: 返回速率限制说明（实际速率限制由 Cloudflare 控制）
- **响应**: HTTP 429

```json
{
    "success": false,
    "error": "Unfortunately you have exceeded the rate limit for the current time period. Please try again later."
}
```

---

### 7. 获取原始日志

- **Method**: `GET`
- **Path**: `/1/raw/{id}`
- **描述**: 根据日志 ID 获取原始日志内容
- **参数**:
  - `id` (path): 日志 ID，支持多个 ID 用逗号分隔，如 `/1/raw/id1,id2,id3`
- **响应**: `text/plain`，日志原始内容

---

### 8. 获取日志洞察

- **Method**: `GET`
- **Path**: `/1/insights/{id}`
- **描述**: 获取日志的 Codex 分析洞察结果
- **参数**:
  - `id` (path): 日志 ID
- **响应**: JSON 格式，包含日志类型、问题列表、版本信息等分析结果

---

### 9. AI 分析日志（已存储）

- **Method**: `GET`
- **Path**: `/1/ai/{id}`
- **描述**: 读取已存储的日志，使用 AI 进行智能分析
- **参数**:
  - `id` (path): 日志 ID
- **响应示例**:

```json
{
    "success": true,
    "message": "AI analysis completed.",
    "analysis": {
        "summary": "服务器启动失败，原因是端口被占用",
        "severity": "high",
        "issues": [
            {
                "type": "端口冲突",
                "description": "25565 端口已被其他进程占用",
                "suggestion": "关闭占用该端口的程序或修改 server.properties 中的端口配置"
            }
        ],
        "recommendations": ["检查系统端口占用情况", "修改服务器端口"]
    }
}
```

- **注意**: 需要在 `core/config/ai.php` 中配置有效的 OpenCode Zen API Key。

---

### 10. AI 分析日志（不落盘）

- **Method**: `POST`
- **Path**: `/1/ai/analyse`
- **描述**: 直接提交日志内容，使用 AI 分析，不存储到数据库
- **请求体**:

```json
{
    "content": "[Server thread/ERROR]: Could not bind to port 25565..."
}
```

- **响应格式**: 同 `GET /1/ai/{id}`

---

### 11. 删除日志

- **Method**: `DELETE`
- **Path**: `/1/delete/{id}`
- **描述**: 根据日志 ID 删除日志，支持批量删除（逗号分隔）
- **参数**:
  - `id` (path): 日志 ID，支持多个，如 `/1/delete/id1,id2,id3`
- **请求头**:
  - `Authorization: Bearer {token}` — 提交日志时返回的 token
- **响应示例**（成功）:

```json
{
    "success": true,
    "deleted": ["aBc123"],
    "failed": [],
    "total": 1,
    "deletedCount": 1,
    "failedCount": 0
}
```

- **响应示例**（部分失败）:

```json
{
    "success": true,
    "deleted": ["aBc123"],
    "failed": [
        {
            "id": "xYz789",
            "message": "Invalid token for log: xYz789",
            "code": 403
        }
    ],
    "total": 2,
    "deletedCount": 1,
    "failedCount": 1
}
```

---

## 状态码说明

| 状态码 | 说明 |
|--------|------|
| 200 | 请求成功 |
| 400 | 请求参数错误 |
| 404 | 日志不存在 |
| 405 | 请求方法不允许 |
| 413 | 请求体过大 |
| 415 | 不支持的 Content-Encoding |
| 429 | 请求过于频繁 |
| 500 | 服务器内部错误（如 AI 分析失败） |

---

## 隐私保护

日志在存储前会自动经过以下过滤器处理：

1. **TrimFilter**: 去除首尾空白
2. **LimitBytesFilter**: 限制最大字节数（默认 10MB）
3. **LimitLinesFilter**: 限制最大行数（默认 50000 行）
4. **IPv4Filter**: 替换 IPv4 地址为 `**.**.**.**`
5. **IPv6Filter**: 替换 IPv6 地址为 `****:****:****:****:****:****:****:****`
6. **UsernameFilter**: 替换用户名为 `********`
7. **AccessTokenFilter**: 替换访问令牌为 `********`
