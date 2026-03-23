# LogShare-v1 API 文档

## 概述

LogShare-v1 是一个现代化的 Minecraft 服务器日志分析 API 平台，支持日志分享、智能分析和隐私保护。

**API 基础地址**: `http://localhost:9300` (或您配置的域名)

**API 版本**: v1

---

## 快速开始

### 提交日志

```bash
curl -X POST http://localhost:9300/1/log \
  -H "Content-Type: application/json" \
  -d '{"content":"[Server thread/INFO]: Starting minecraft server version 1.20.1"}'
```

**响应示例**:
```json
{
  "success": true,
  "message": "Log submitted successfully",
  "id": "abc1234",
  "url": "http://localhost:9300/abc1234",
  "raw": "http://localhost:9300/1/raw/abc1234",
  "token": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0"
}
```

> ⚠️ **重要**: 请保存返回的 `token`，删除日志时需要使用。

---

## API 端点

### 1. POST /1/log - 提交日志

提交新的日志数据进行分析。

**请求头**:
```
Content-Type: application/json
```

**请求体**:
```json
{
  "content": "日志内容字符串",
  "metadata": [
    {
      "key": "server_version",
      "value": "1.20.1",
      "label": "服务器版本",
      "visible": true
    }
  ],
  "source": "web-upload"
}
```

**参数说明**:

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `content` | string | ✅ | 日志内容（必需） |
| `metadata` | array | ❌ | 元数据数组 |
| `metadata[].key` | string | ❌ | 元数据键名 |
| `metadata[].value` | mixed | ❌ | 元数据值 |
| `metadata[].label` | string | ❌ | 显示标签 |
| `metadata[].visible` | boolean | ❌ | 是否公开可见 |
| `source` | string | ❌ | 来源标识（最长 64 字符） |

**响应示例**:
```json
{
  "success": true,
  "message": "Log submitted successfully",
  "id": "abc1234",
  "url": "http://localhost:9300/abc1234",
  "raw": "http://localhost:9300/1/raw/abc1234",
  "token": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0"
}
```

**字段说明**:

| 字段 | 类型 | 说明 |
|------|------|------|
| `success` | boolean | 操作是否成功 |
| `message` | string | 响应消息 |
| `id` | string | 日志唯一标识符 |
| `url` | string | 日志查看页面 URL |
| `raw` | string | 原始日志内容 API URL |
| `token` | string | 删除令牌（请妥善保存） |

---

### 2. GET /1/raw/{id} - 获取原始日志

获取指定日志的原始内容。

**路径参数**:
- `id` - 日志 ID（支持多个 ID，用逗号分隔）

**请求示例**:
```bash
# 获取单个日志
curl http://localhost:9300/1/raw/abc1234

# 获取多个日志
curl http://localhost:9300/1/raw/abc1234,def5678,ghi9012
```

**响应**:
```
Content-Type: text/plain

[Server thread/INFO]: Starting minecraft server version 1.20.1
[Server thread/INFO]: Loading properties
[Server thread/INFO]: Default game type: SURVIVAL
...
```

**错误响应**:
```json
{
  "success": false,
  "error": "Log not found."
}
```

---

### 3. POST /1/analyse - 分析日志

分析日志内容并返回结构化数据。

**请求头**:
```
Content-Type: application/json
```

**请求体**:
```json
{
  "content": "[Server thread/INFO]: Starting minecraft server version 1.20.1"
}
```

**响应示例**:
```json
{
  "name": "Vanilla Server Log",
  "type": "minecraft",
  "software": "vanilla",
  "version": "1.20.1",
  "entries": [...]
}
```

---

### 4. GET /1/insights/{id} - 获取日志洞察

获取已存储日志的分析洞察。

**路径参数**:
- `id` - 日志 ID

**请求示例**:
```bash
curl http://localhost:9300/1/insights/abc1234
```

**响应示例**:
```json
{
  "name": "Vanilla Server Log",
  "type": "minecraft",
  "software": "vanilla",
  "version": "1.20.1",
  "insights": [
    {
      "type": "version",
      "value": "1.20.1",
      "label": "Minecraft Version"
    }
  ]
}
```

---

### 5. DELETE /1/delete/{id} - 删除日志

删除指定的日志（需要 Token 认证）。

**路径参数**:
- `id` - 日志 ID（支持多个 ID，用逗号分隔）

**请求头**:
```
Authorization: Bearer {token}
```

**请求示例**:
```bash
# 删除单个日志
curl -X DELETE http://localhost:9300/1/delete/abc1234 \
  -H "Authorization: Bearer a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0"

# 删除多个日志
curl -X DELETE http://localhost:9300/1/delete/abc1234,def5678 \
  -H "Authorization: Bearer a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0"
```

**成功响应**:
```json
{
  "success": true,
  "deleted": ["abc1234", "def5678"],
  "failed": [],
  "total": 2,
  "deletedCount": 2,
  "failedCount": 0
}
```

**部分失败响应**:
```json
{
  "success": true,
  "deleted": ["abc1234"],
  "failed": [
    {
      "id": "def5678",
      "message": "Invalid token for log: def5678",
      "code": 403
    }
  ],
  "total": 2,
  "deletedCount": 1,
  "failedCount": 1
}
```

**错误响应**:
```json
{
  "success": false,
  "error": "Missing token in Authorization header."
}
```

---

### 6. POST /1/bulk/log/delete - 批量删除日志

使用 JSON 请求体批量删除多个日志。

**请求头**:
```
Content-Type: application/json
```

**请求体**:
```json
[
  {
    "id": "abc1234",
    "token": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0"
  },
  {
    "id": "def5678",
    "token": "x1y2z3a4b5c6d7e8f9g0h1i2j3k4l5m6n7o8p9q0"
  }
]
```

**响应示例**:
```json
{
  "success": true,
  "results": {
    "abc1234": {
      "success": true
    },
    "def5678": {
      "success": false,
      "error": "Invalid token.",
      "code": 403
    }
  }
}
```

**限制**:
- 最多一次删除 256 个日志

---

### 7. GET /1/limits - 获取限制信息

获取 API 的存储限制信息。

**请求示例**:
```bash
curl http://localhost:9300/1/limits
```

**响应示例**:
```json
{
  "storageTime": 7776000,
  "maxLength": 10485760,
  "maxLines": 25000
}
```

**字段说明**:

| 字段 | 类型 | 说明 |
|------|------|------|
| `storageTime` | integer | 日志存储时间（秒），默认 90 天 |
| `maxLength` | integer | 最大字节数，默认 10MB |
| `maxLines` | integer | 最大行数，默认 25000 行 |

---

### 8. GET /1/filters - 获取过滤器信息

获取当前启用的日志过滤器信息。

**请求示例**:
```bash
curl http://localhost:9300/1/filters
```

**响应示例**:
```json
{
  "success": true,
  "filters": [
    {
      "type": "trim",
      "data": null
    },
    {
      "type": "limit-bytes",
      "data": {
        "limit": 10485760
      }
    },
    {
      "type": "limit-lines",
      "data": {
        "limit": 25000
      }
    },
    {
      "type": "regex",
      "data": {
        "patterns": [
          {
            "pattern": "IPv4",
            "replacement": "**.**.**.**"
          },
          {
            "pattern": "IPv6",
            "replacement": "****:****:****:****:****:****:****:****"
          },
          {
            "pattern": "Username",
            "replacement": "********"
          },
          {
            "pattern": "AccessToken",
            "replacement": "********"
          }
        ]
      }
    }
  ]
}
```

---

### 9. GET /1/errors/rate - 获取错误率统计

获取错误率限制信息（由 Cloudflare 提供）。

**响应示例**:
```json
{
  "success": false,
  "error": "Unfortunately you have exceeded the rate limit for the current time period. Please try again later."
}
```

---

## 认证方式

### Token 认证

删除日志时需要在请求头中携带 Token：

```
Authorization: Bearer {token}
```

Token 在提交日志时返回，请妥善保存。

---

## 错误处理

### 标准错误响应格式

```json
{
  "success": false,
  "error": "错误描述信息",
  "code": 400
}
```

### 常见错误码

| 状态码 | 说明 |
|--------|------|
| 400 | 请求参数错误 |
| 403 | Token 无效或权限不足 |
| 404 | 日志不存在 |
| 405 | 请求方法不允许 |
| 413 | 请求体过大 |
| 415 | 不支持的内容类型 |
| 429 | 请求频率超限 |
| 500 | 服务器内部错误 |

---

## 隐私保护

所有提交的日志会自动应用以下过滤器：

1. **IPv4 地址过滤** - 替换为 `**.**.**.**`
2. **IPv6 地址过滤** - 替换为 `****:****:****:****:****:****:****:****`
3. **用户名过滤** - 替换路径中的用户名为 `********`
4. **访问令牌过滤** - 替换为 `********`

### 豁免规则

以下 IP 地址不会被过滤：
- `127.0.0.0/8` - 本地回环
- `0.0.0.0` - 任意地址
- `1.0.0.1`, `1.1.1.1` - 公共 DNS
- `8.8.8.8`, `8.8.4.4` - Google DNS

---

## 使用示例

### Python 示例

```python
import requests

API_BASE = "http://localhost:9300"

# 提交日志
def submit_log(content: str, metadata: list = None):
    response = requests.post(
        f"{API_BASE}/1/log",
        json={
            "content": content,
            "metadata": metadata or []
        }
    )
    return response.json()

# 获取原始日志
def get_raw_log(log_id: str):
    response = requests.get(f"{API_BASE}/1/raw/{log_id}")
    return response.text

# 删除日志
def delete_log(log_id: str, token: str):
    response = requests.delete(
        f"{API_BASE}/1/delete/{log_id}",
        headers={"Authorization": f"Bearer {token}"}
    )
    return response.json()

# 使用示例
if __name__ == "__main__":
    # 提交日志
    result = submit_log(
        "[Server thread/INFO]: Starting minecraft server",
        metadata=[{"key": "version", "value": "1.20.1"}]
    )
    
    log_id = result["id"]
    log_token = result["token"]
    
    print(f"Log ID: {log_id}")
    print(f"Token: {log_token}")
    
    # 获取日志
    content = get_raw_log(log_id)
    print(f"Content: {content[:100]}...")
    
    # 删除日志
    delete_result = delete_log(log_id, log_token)
    print(f"Delete result: {delete_result}")
```

### Node.js 示例

```javascript
const axios = require('axios');

const API_BASE = 'http://localhost:9300';

// 提交日志
async function submitLog(content, metadata = []) {
  const response = await axios.post(`${API_BASE}/1/log`, {
    content,
    metadata
  });
  return response.data;
}

// 获取原始日志
async function getRawLog(logId) {
  const response = await axios.get(`${API_BASE}/1/raw/${logId}`);
  return response.data;
}

// 删除日志
async function deleteLog(logId, token) {
  const response = await axios.delete(`${API_BASE}/1/delete/${logId}`, {
    headers: { 'Authorization': `Bearer ${token}` }
  });
  return response.data;
}

// 使用示例
(async () => {
  // 提交日志
  const result = await submitLog(
    '[Server thread/INFO]: Starting minecraft server',
    [{ key: 'version', value: '1.20.1' }]
  );
  
  console.log(`Log ID: ${result.id}`);
  console.log(`Token: ${result.token}`);
  
  // 获取日志
  const content = await getRawLog(result.id);
  console.log(`Content: ${content.substring(0, 100)}...`);
  
  // 删除日志
  const deleteResult = await deleteLog(result.id, result.token);
  console.log(`Delete result:`, deleteResult);
})();
```

### cURL 完整示例

```bash
#!/bin/bash

API_BASE="http://localhost:9300"

# 1. 提交日志
echo "=== 提交日志 ==="
RESPONSE=$(curl -s -X POST "${API_BASE}/1/log" \
  -H "Content-Type: application/json" \
  -d '{
    "content": "[Server thread/INFO]: Starting minecraft server version 1.20.1\n[Server thread/WARN]: Something happened",
    "metadata": [
      {"key": "version", "value": "1.20.1", "label": "Minecraft Version"}
    ],
    "source": "cli-upload"
  }')

echo "$RESPONSE" | jq .

# 提取 ID 和 Token
LOG_ID=$(echo "$RESPONSE" | jq -r '.id')
LOG_TOKEN=$(echo "$RESPONSE" | jq -r '.token')

echo ""
echo "=== 获取日志 ID 和 Token ==="
echo "Log ID: $LOG_ID"
echo "Token: $LOG_TOKEN"

# 2. 获取原始日志
echo ""
echo "=== 获取原始日志 ==="
curl -s "${API_BASE}/1/raw/${LOG_ID}"

# 3. 获取日志洞察
echo ""
echo ""
echo "=== 获取日志洞察 ==="
curl -s "${API_BASE}/1/insights/${LOG_ID}" | jq .

# 4. 获取限制信息
echo ""
echo "=== 获取限制信息 ==="
curl -s "${API_BASE}/1/limits" | jq .

# 5. 删除日志
echo ""
echo "=== 删除日志 ==="
curl -s -X DELETE "${API_BASE}/1/delete/${LOG_ID}" \
  -H "Authorization: Bearer ${LOG_TOKEN}" | jq .
```

---

## 最佳实践

### 1. Token 管理

- ✅ 提交日志后立即保存返回的 Token
- ✅ 将 Token 存储在安全的地方
- ❌ 不要在客户端代码中硬编码 Token
- ❌ 不要将 Token 提交到版本控制系统

### 2. 错误处理

```python
try:
    response = requests.delete(
        f"{API_BASE}/1/delete/{log_id}",
        headers={"Authorization": f"Bearer {token}"}
    )
    response.raise_for_status()
    result = response.json()
    
    if result.get('success'):
        print("删除成功")
    else:
        print(f"删除失败：{result.get('error')}")
        
except requests.exceptions.HTTPError as e:
    print(f"HTTP 错误：{e.response.status_code}")
except requests.exceptions.RequestException as e:
    print(f"请求错误：{e}")
```

### 3. 批量操作

对于大量日志的删除操作，建议使用批量删除 API：

```python
def bulk_delete(logs):
    """
    logs: [{"id": "...", "token": "..."}, ...]
    """
    response = requests.post(
        f"{API_BASE}/1/bulk/log/delete",
        json=logs
    )
    return response.json()
```

---

## 常见问题

### Q: Token 丢失了怎么办？

A: Token 丢失后无法通过 API 删除日志。日志会在 90 天后自动过期删除。

### Q: 可以自定义日志存储时间吗？

A: 可以通过配置文件 `core/config/storage.php` 修改 `storageTime` 参数。

### Q: 支持哪些日志格式？

A: 支持 Minecraft Java 版、基岩版、Fabric、Forge、Hytale 等主流服务器日志格式。

### Q: 如何报告滥用内容？

A: 请联系管理员或发送邮件至 abuse 报告邮箱（如配置）。

---

## 更新日志

### v1.0.0
- ✅ 新增 Token 认证系统
- ✅ 新增 Metadata 元数据支持
- ✅ 新增批量删除 API
- ✅ 新增过滤器信息 API
- ✅ 改进隐私保护过滤器
- ✅ 支持 JSON 请求体

---

## 支持

如有问题或建议，请：
- 查看 [Issues](https://github.com/your-repo/issues)
- 提交新的 Issue
- 查阅项目文档
