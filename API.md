# LogShare API 文档

**Base URL**: `https://api.logshare.cn`
**版本**: v1

---

## 通用说明

- 所有响应默认返回 JSON 格式
- 成功响应结构: `{ "success": true, "message": "...", "data": { ... } }`
- 错误响应结构: `{ "success": false, "error": "错误描述" }`
- 支持 CORS（`Access-Control-Allow-Origin: *`）

---

## 接口列表

| 方法 | 端点 | 说明 |
|------|------|------|
| POST | `/1/log` | 提交日志 |
| POST | `/1/analyse` | 分析日志（本地 Codex） |
| GET | `/1/raw/{id}` | 获取原始日志 |
| GET | `/1/insights/{id}` | 获取日志分析结果 |
| DELETE | `/1/delete/{id}` | 删除日志 |
| GET | `/1/limits` | 获取限制信息 |
| GET | `/1/filters` | 获取过滤器信息 |
| GET | `/1/ai/{id}` | AI 分析已存储日志 🔵 |
| POST | `/1/ai/analyse` | AI 分析日志内容 🔵 |

🔵 表示该接口使用 SSE 流式输出

---

## 1. 提交日志

**POST** `/1/log`

### 请求

- Content-Type: `text/plain` 或 `application/json`
- 支持 gzip/deflate/br 压缩上传

#### 纯文本请求体
```
日志内容...
```

#### JSON 请求体（支持元数据）
```json
{
  "content": "日志内容...",
  "metadata": { "key": "value" },
  "source": "自定义来源标识"
}
```

### 响应
```json
{
  "success": true,
  "message": "Log submitted successfully",
  "data": {
    "id": "abc123",
    "url": "https://logshare.cn/abc123",
    "raw": "https://api.logshare.cn/1/raw/abc123",
    "token": "token_xxxxx"
  }
}
```

> `token` 用于后续删除操作，请妥善保管。

---

## 2. 分析日志（本地）

**POST** `/1/analyse`

### 请求
- Content-Type: `text/plain` 或 `application/json`
- 请求体为日志内容

### 响应
```json
{
  "success": true,
  "data": {
    "id": "log-id",
    "name": "Minecraft",
    "type": "ServerLog",
    "entries": [...],
    "insights": [...]
  }
}
```

---

## 3. 获取原始日志

**GET** `/1/raw/{id}`

支持多个 ID，用逗号分隔：`/1/raw/id1,id2,id3`

### 响应
- Content-Type: `text/plain`
- 日志原始内容

### 错误
- `404` - 日志不存在

---

## 4. 获取日志分析结果

**GET** `/1/insights/{id}`

### 响应
```json
{
  "success": true,
  "data": {
    "id": "log-id",
    "name": "Minecraft",
    "type": "ServerLog",
    "insights": [
      {
        "message": "错误描述",
        "level": "ERROR",
        "counter": 1,
        "tags": ["error", "crash"],
        "entry": {...}
      }
    ]
  }
}
```

---

## 5. 删除日志

**DELETE** `/1/delete/{id}`

支持多个 ID，用逗号分隔：`/1/delete/id1,id2,id3`

### 请求头
```
Authorization: Bearer {token}
```

### 响应
```json
{
  "success": true,
  "deleted": ["id1"],
  "failed": [
    { "id": "id2", "message": "Invalid token", "code": 403 }
  ],
  "total": 2,
  "deletedCount": 1,
  "failedCount": 1
}
```

---

## 6. 获取限制信息

**GET** `/1/limits`

### 响应
```json
{
  "success": true,
  "data": {
    "storageTime": 2592000,
    "maxLength": 1048576,
    "maxLines": 50000
  }
}
```

---

## 7. 获取过滤器信息

**GET** `/1/filters`

### 响应
```json
{
  "success": true,
  "data": {
    "filters": [
      { "type": "trim", "data": null },
      { "type": "limit-bytes", "data": { "limit": 1048576 } },
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
}
```

---

## 8. AI 分析已存储日志 🔵🟢

**GET** `/1/ai/{id}`

> 🟢 **SSE 流式接口** - 使用 Server-Sent Events 实时返回 AI 分析结果
> 首次请求会流式输出，后续 5 分钟内相同 ID 请求直接返回缓存结果

### SSE 事件流

客户端使用 `EventSource` 或 `fetch` 接收流式数据：

```javascript
// 方式一：EventSource（不支持 POST）
const evtSource = new EventSource('https://api.logshare.cn/1/ai/{id}');

evtSource.onmessage = (event) => {
  const chunk = JSON.parse(event.data);
  // 处理每个 chunk
  const content = chunk.choices?.[0]?.delta?.content;
  if (content) {
    console.log(content);
  }
};

evtSource.addEventListener('done', () => {
  console.log('分析完成');
  evtSource.close();
});

evtSource.addEventListener('error', (event) => {
  const error = JSON.parse(event.data);
  console.error('错误:', error.error);
  evtSource.close();
});
```

```javascript
// 方式二：fetch（推荐，支持完整 SSE 解析）
const response = await fetch('https://api.logshare.cn/1/ai/{id}');
const reader = response.body.getReader();
const decoder = new TextDecoder();
let fullJson = '';

while (true) {
  const { done, value } = await reader.read();
  if (done) break;

  const text = decoder.decode(value);
  const lines = text.split('\n');

  for (const line of lines) {
    if (line.startsWith('data: ')) {
      const data = line.slice(6);
      if (data.trim() === '[DONE]') continue;

      const chunk = JSON.parse(data);
      const content = chunk.choices?.[0]?.delta?.content;
      if (content) fullJson += content;
    }
  }
}

// 流结束后解析完整 JSON
const analysis = JSON.parse(fullJson);
console.log(analysis);
```

### SSE 事件类型

| 事件 | 说明 |
|------|------|
| `message` (无 event 字段) | 标准 SSE 数据块，包含 OpenAI 格式的 chunk |
| `done` | 流结束，AI 分析完成 |
| `error` | 发生错误 |

### 缓存命中响应（5 分钟内）
```json
{
  "success": true,
  "message": "AI analysis completed (from cache).",
  "data": {
    "analysis": {
      "summary": "问题摘要",
      "severity": "high",
      "issues": [
        {
          "type": "问题类型",
          "description": "问题描述",
          "suggestion": "解决建议"
        }
      ],
      "recommendations": ["总体建议1", "总体建议2"]
    },
    "cached": true
  }
}
```

### 错误
- `404` - 日志不存在
- `500` - AI 分析失败

---

## 9. AI 分析日志内容 🔵🟢

**POST** `/1/ai/analyse`

> 🟢 **SSE 流式接口** - 基于内容哈希缓存，相同内容 5 分钟内直接返回缓存

### 请求
- Content-Type: `text/plain` 或 `application/json`
- 请求体为日志内容

### SSE 事件流
与 `/1/ai/{id}` 完全一致，请参考上方说明。

### 缓存命中响应
```json
{
  "success": true,
  "message": "AI analysis completed (from cache).",
  "data": {
    "analysis": { ... },
    "cached": true
  }
}
```

---

## AI 分析结果格式

无论是否走 SSE，最终分析结果的 JSON 结构一致：

```json
{
  "summary": "Minecraft崩溃，日志显示着色器编译失败",
  "severity": "high",
  "issues": [
    {
      "type": "Shader Compilation Error",
      "description": "着色器初始化失败",
      "suggestion": "禁用相关模组"
    }
  ],
  "recommendations": [
    "临时解决方案: 禁用不兼容的模组",
    "等待模组开发者发布更新"
  ]
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `summary` | string | 问题摘要 |
| `severity` | string | 严重程度: `low`、`medium`、`high`、`critical` |
| `issues` | array | 问题列表 |
| `issues[].type` | string | 问题类型 |
| `issues[].description` | string | 问题描述 |
| `issues[].suggestion` | string | 解决建议 |
| `recommendations` | string[] | 总体建议列表 |
