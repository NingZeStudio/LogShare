# McLogs Next API 文档

本文档详细介绍了 McLogs Next API 的所有端点、参数、请求和响应格式，以及开发相关信息。

## API 基础信息

- **API 版本**: v1
- **基础 URL**: `http://localhost:9300` (或配置的域名)
- **协议**: HTTP/HTTPS
- **认证**: 无需认证 (公共 API)
- **内容类型**: 默认返回 JSON 格式

## 端点详情

### 1. 获取 API 信息

- **URL**: `GET /`
- **描述**: 返回 API 的欢迎信息和可用端点列表
- **请求参数**: 无
- **示例请求**:
  ```bash
  curl http://localhost:9300/
  ```
- **示例响应**:
  ```json
  {
    "message": "Welcome to the API. Please use the following endpoints:",
    "endpoints": {
      "POST /1/log": "Submit log data",
      "POST /1/analyse": "Analyze log data",
      "GET /1/errors/rate": "Get error rate statistics",
      "GET /1/limits": "Get API rate limits",
      "GET /1/raw/{id}": "Retrieve raw log by ID",
      "GET /1/ai-analysis/{id}": "Get AI analysis for log ID",
      "GET /1/insights/{id}": "Get insights for log ID",
      "DELETE /1/delete/{id}": "Delete log by ID"
    },
    "documentation": "Please refer to the API documentation for detailed usage."
  }
  ```

### 2. 提交日志

- **URL**: `POST /1/log`
- **描述**: 提交新的日志内容到服务器
- **请求体**: 原始日志文本内容
- **请求头**:
  - `Content-Type: application/json` 或 `text/plain`
  - `Content-Encoding: gzip` (可选，支持压缩内容)
- **示例请求**:
  ```bash
  curl -X POST http://localhost:9300/1/log \
    -H "Content-Type: text/plain" \
    -d "你的日志内容..."
  ```
- **示例响应**:
  ```json
  {
    "success": true,
    "id": "abc123def456",
    "url": "http://localhost:9300/#/abc123def456",
    "raw": "http://localhost:9300/1/raw/abc123def456"
  }
  ```
- **响应字段**:
  - `id`: 生成的日志唯一标识符
  - `url`: 用于查看日志的前端 URL
  - `raw`: 获取原始日志内容的 API URL

### 3. 分析日志

- **URL**: `POST /1/analyse`
- **描述**: 对提供的日志内容进行分析
- **请求体**: 原始日志文本内容
- **请求头**:
  - `Content-Type: application/json` 或 `text/plain`
- **示例请求**:
  ```bash
  curl -X POST http://localhost:9300/1/analyse \
    -H "Content-Type: text/plain" \
    -d "你的日志内容..."
  ```
- **示例响应**:
  ```json
  {
    "success": true,
    "software": "Unknown",
    "version": null,
    "problems": [],
    "entries": []
  }
  ```

### 4. 获取错误率统计

- **URL**: `GET /1/errors/rate`
- **描述**: 获取错误率统计信息（目前由 Cloudflare 处理）
- **请求参数**: 无
- **示例请求**:
  ```bash
  curl http://localhost:9300/1/errors/rate
  ```
- **示例响应**:
  ```json
  {
    "success": false,
    "error": "Unfortunately you have exceeded the rate limit for the current time period. Please try again later.",
    "code": 429
  }
  ```

### 5. 获取 API 限制

- **URL**: `GET /1/limits`
- **描述**: 获取 API 的存储和大小限制
- **请求参数**: 无
- **示例请求**:
  ```bash
  curl http://localhost:9300/1/limits
  ```
- **示例响应**:
  ```json
  {
    "storageTime": 7776000,
    "maxLength": 10485760,
    "maxLines": 25000
  }
  ```
- **响应字段**:
  - `storageTime`: 日志存储时间（秒）
  - `maxLength`: 最大日志长度（字节）
  - `maxLines`: 最大日志行数

### 6. 获取原始日志

- **URL**: `GET /1/raw/{id}`
- **描述**: 根据 ID 获取原始日志内容
- **路径参数**:
  - `{id}`: 日志的唯一标识符
- **示例请求**:
  ```bash
  curl http://localhost:9300/1/raw/abc123def456
  ```
- **示例响应**:
  ```
  [2023-01-01 12:00:00] [Server thread/INFO]: Starting minecraft server version 1.19.2
  [2023-01-01 12:00:01] [Server thread/INFO]: Using default channel type
  ...
  ```

### 7. 获取 AI 分析

- **URL**: `GET /1/ai-analysis/{id}`
- **描述**: 根据 ID 获取日志的 AI 分析结果
- **路径参数**:
  - `{id}`: 日志的唯一标识符
- **示例请求**:
  ```bash
  curl http://localhost:9300/1/ai-analysis/abc123def456
  ```
- **示例响应**:
  ```json
  {
    "success": true,
    "analysis": "# Minecraft 服务器日志分析\n\n根据提供的日志，服务器遇到了以下问题...\n\n## 解决方案建议\n\n1. ..."
  }
  ```
- **注意事项**:
  - 需要在 `core/config/ai.php` 中配置有效的 Gemini API 密钥
  - 如果未配置 API 密钥，会返回相应的错误信息

### 8. 获取日志洞察

- **URL**: `GET /1/insights/{id}`
- **描述**: 根据 ID 获取日志的洞察信息
- **路径参数**:
  - `{id}`: 日志的唯一标识符
- **示例请求**:
  ```bash
  curl http://localhost:9300/1/insights/abc123def456
  ```
- **示例响应**:
  ```json
  {
    "success": true,
    "software": "Spigot",
    "version": "1.19.2",
    "problems": [
      {
        "level": "ERROR",
        "message": "Could not load plugin",
        "solution": "Check plugin compatibility"
      }
    ],
    "entries": []
  }
  ```

### 9. 删除日志

- **URL**: `DELETE /1/delete/{id}`
- **描述**: 根据 ID 删除日志
- **路径参数**:
  - `{id}`: 日志的唯一标识符
- **请求头**:
  - `Content-Type: application/json`
- **示例请求**:
  ```bash
  curl -X DELETE http://localhost:9300/1/delete/abc123def456
  ```
- **示例响应**:
  ```json
  {
    "success": true,
    "message": "Log deleted successfully"
  }
  ```

## 错误码

| 错误码 | 描述 |
|--------|------|
| 400 | 请求参数错误 |
| 404 | 资源未找到 |
| 405 | 方法不被允许 |
| 415 | 不支持的内容编码 |
| 429 | 请求频率超限 |
| 500 | 服务器内部错误 |

## 配置文件

API 行为受以下配置文件影响：

- `core/config/storage.php`: 存储限制和后端配置
- `core/config/ai.php`: AI 分析配置（需要 Gemini API 密钥）
- `core/config/filter.php`: 日志过滤规则
- `core/config/id.php`: ID 生成规则
- `core/config/urls.php`: 基础 URL 配置

## 限制和注意事项

1. **日志大小限制**: 单个日志最大为 10MB，最多 25000 行
2. **存储时间**: 日志默认保存 90 天（7776000 秒）
3. **ID 格式**: 日志 ID 仅包含字母、数字、下划线和连字符
4. **AI 分析**: 需要有效的 Gemini API 密钥才能使用 AI 分析功能
5. **CORS**: API 启用了跨域资源共享（CORS）
6. **内容编码**: 支持 gzip、deflate 和 x-gzip 内容编码

## 故障排除

如果遇到问题，请检查以下几点：

1. 确保 Docker 服务正在运行
2. 检查 MongoDB 和 Redis 服务是否正常连接
3. 验证 API 端点 URL 是否正确
4. 检查请求头和内容类型是否符合要求
5. 对于 AI 分析功能，确保已正确配置 API 密钥

# 开发文档

## 项目架构

McLogs Next API 采用模块化架构，主要分为以下几个部分：

### 核心组件

1. **Log 类** (`core/src/Log.php`): 
   - 负责日志的加载、分析和存储
   - 集成 Aternos Codex 进行日志分析
   - 支持反混淆功能（Deobfuscation）

2. **Id 类** (`core/src/Id.php`):
   - 生成和管理日志的唯一标识符
   - 支持编码存储类型信息到 ID 中

3. **ContentParser 类** (`core/src/ContentParser.php`):
   - 解析 HTTP 请求中的内容
   - 支持多种内容编码（gzip, deflate）

4. **Detective 类** (`core/src/Detective.php`):
   - 检测日志类型（Minecraft、Hytale 等）
   - 使用 Aternos Codex 库进行日志类型识别

5. **ApiError 类** (`core/src/ApiError.php`):
   - 统一处理 API 错误响应
   - 实现 JSON 序列化接口

### 存储系统

API 支持多种存储后端，通过 `StorageInterface` 统一管理：

1. **MongoDB** (`core/src/Storage/Mongo.php`):
   - 默认存储后端
   - 使用 TTL 索引自动清理过期数据
   - 支持数据过期和续期

2. **Redis** (`core/src/Storage/Redis.php`):
   - 基于内存的快速存储
   - 支持自动过期功能

3. **文件系统** (`core/src/Storage/Filesystem.php`):
   - 本地文件存储
   - 适合小规模部署

### 配置系统

配置通过 `Config` 类统一管理，所有配置文件位于 `core/config/` 目录：

- `storage.php`: 存储配置（后端类型、限制等）
- `ai.php`: AI 分析配置
- `filter.php`: 过滤规则配置
- `id.php`: ID 生成配置
- `urls.php`: URL 配置
- `cache.php`: 缓存配置
- `legal.php`: 法律合规配置

## 扩展开发

### 添加新的存储后端

要添加新的存储后端，需要实现 `Storage\StorageInterface` 接口：

```php
namespace Storage;

class MyNewStorage implements StorageInterface
{
    public static function Put(string $data): ?\Id
    {
        // 实现数据存储逻辑
        // 返回 ID 或 null
    }

    public static function Get(\Id $id): ?string
    {
        // 实现数据获取逻辑
        // 返回数据或 null
    }

    public static function Renew(\Id $id): bool
    {
        // 实现数据续期逻辑
        // 返回操作是否成功
    }

    public static function Delete(\Id $id): bool
    {
        // 实现数据删除逻辑
        // 返回操作是否成功
    }
}
```

然后在 `core/config/storage.php` 中注册新的存储后端：

```php
"storages" => [
    // ... 其他存储
    "n" => [
        "name" => "MyNewStorage",
        "class" => "\\Storage\\MyNewStorage",
        "enabled" => true
    ]
],
"storageId" => "n", // 设置为默认存储
```

### 添加新的过滤器

可以通过在 `core/config/filter.php` 中配置预处理过滤器来扩展日志处理逻辑。

### 添加新的端点

API 端点在 `api/public/index.php` 中定义，通过路由匹配调用相应的处理文件：

```php
case "/my-new-endpoint":
    require_once("../endpoints/my-new-endpoint.php");
    break;
```

## 性能考虑

当前 API 版本在性能方面仍有优化空间。我们计划在未来版本中持续改进 API 响应速度和整体性能表现，特别是：

1. 优化日志分析算法
2. 改进存储访问效率
3. 添加缓存层
4. 实现异步处理机制

## 安全性

1. **输入验证**: 所有输入都会经过验证和清理
2. **内容过滤**: 自动过滤敏感信息（如 IP 地址）
3. **访问控制**: 通过 ID 限制对日志的访问
4. **内容编码**: 支持安全的内容编码和解码

## 调试和日志

在开发过程中，可以通过以下方式进行调试：

1. 在配置文件中启用调试模式
2. 检查 PHP 错误日志
3. 使用 Docker 日志命令查看容器日志
4. 检查 MongoDB/Redis 连接状态

## 测试

API 的测试应该包括：

1. 功能测试：验证所有端点按预期工作
2. 性能测试：评估 API 在负载下的表现
3. 安全测试：确保没有安全漏洞
4. 集成测试：验证与其他系统的兼容性