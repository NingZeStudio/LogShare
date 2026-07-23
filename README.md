# LogShare

Minecraft / Hytale 日志分析与分享平台。基于 Aternos Codex 和 Sherlock 构建，提供日志上传、自动诊断、敏感信息脱敏和大模型 AI 辅助分析能力。

## 功能特性

- **自动日志识别**：支持 Paper、Spigot、Bukkit、Forge、Fabric、NeoForge、Vanilla 服务端及客户端日志，自动检测服务端类型和版本
- **混淆映射反解**：集成 Sherlock 引擎，自动获取对应版本的混淆映射，还原可读堆栈信息
- **敏感信息脱敏**：上传时自动过滤 IPv4/IPv6 地址、用户名、Access Token，支持可配置的过滤链
- **结构化分析**：基于 Codex-Minecraft 和 Codex-Hytale 解析引擎，提取错误堆栈、崩溃原因、性能问题
- **AI 辅助诊断**：接入大模型 API，提供两种模式——基于已存储日志的 ID 分析和直接提交内容的不落盘分析
- **RESTful API**：支持单 ID 和多 ID（逗号分隔）操作，删除采用 Bearer Token 鉴权
- **Redis 缓存**：读取缓存 30 分钟 TTL，超过 600KB 的日志自动跳过缓存直读 MongoDB

## 环境要求

- PHP 8.4+
- 扩展：ext-json、ext-zlib、ext-mbstring、mongodb、redis
- MongoDB（主要存储）
- Redis（缓存）

## 快速开始

### 安装

```bash
composer install
cp Config.inc.example.php Config.inc.php
```

### 配置

`Config.inc.php` 包含全部配置项，主要模块：

| 配置段 | 说明 |
|---|---|
| `storage` | 存储后端配置，默认 MongoDB；Redis/Filesystem 可选但默认禁用 |
| `cache` | Redis 连接信息，默认主机 mclogs-redis:6379 |
| `mongo` | MongoDB 连接 URL 和数据库名 |
| `ai` | AI API Key 列表、接口地址、模型名称 |
| `filter` | 预处理过滤链，按顺序执行 |
| `id` | ID 字符集和长度（修改会破坏现有 ID） |
| `urls` | 前端和 API 的基础 URL |

### Docker 部署

```bash
docker compose -f docker/compose.yaml up -d
```

服务监听 9300 端口，包含以下容器：

- **nginx**：反向代理，根目录指向项目根，请求体限制 210MB
- **php-fpm**：PHP 8.5，启用 opcache，`post_max_size = 16M`
- **mongo**：MongoDB 最新版，持久化数据卷
- **redis**：Redis 7 Alpine，缓存层

### 手动部署

Nginx 配置参考 `docker/mclogs.conf`：

```
root /path/to/project;
location / {
    try_files $uri /index.php =404;
}

location ~ \.php$ {
    fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
    fastcgi_param SCRIPT_FILENAME $document_root/$fastcgi_script_name;
    include fastcgi_params;
}
```

## API 文档

所有端点前缀 `/1/`。响应格式统一为 JSON（`/1/raw` 返回纯文本）。

### 日志管理

```
POST   /1/log              上传日志
DELETE /1/log/{id}          删除日志
GET    /1/raw/{id}          获取原始日志
```

**上传日志**：接受 `application/x-www-form-urlencoded` 和 `application/json`，支持 gzip/deflate 压缩。字段：

| 字段 | 类型 | 说明 |
|---|---|---|
| `content` | string | 日志内容（必填） |
| `metadata[]` | array | 可选元数据 |
| `source` | string | 来源标识（最长 64 字符） |

上传成功返回：

```json
{
    "id": "mAbCdE",
    "url": "https://logshare.cn/mAbCdE",
    "raw": "https://api.logshare.cn/1/raw/mAbCdE",
    "token": "f3a2b1..."
}
```

**删除日志**：通过 `Authorization: Bearer <token>` 鉴权，token 来自上传响应。支持多 ID：`DELETE /1/log/id1,id2`。

### 日志分析

```
GET  /1/insights/{id}       获取分析结果
POST /1/analyse             直接分析日志内容（不存储）
```

`/1/insights` 返回 Codex 解析引擎的结构化分析结果，包含服务端版本、错误信息、堆栈跟踪等。

### AI 分析

```
GET  /1/ai/{id}             基于已存储日志的 AI 分析
POST /1/ai/analyse          提交内容直接分析（不落盘）
```

AI 分析使用 SSE（Server-Sent Events）流式输出。配置 API Key 后，系统自动轮换 Key 处理限流。

### 其他

```
GET  /1/limits              获取速率限制信息
GET  /1/filters             获取当前启用的过滤器列表
```

## 架构

### 目录结构

```
index.php                入口文件，CORS + Router::dispatch()
src/                      核心类库，PSR-4 自动加载
├── Cache/                Redis/Mongo 缓存实现
├── Client/               MongoDB、Redis、AI 客户端
├── Data/                 数据模型（Token、MetadataEntry）
├── Filter/               预处理过滤链
├── Printer/              日志打印格式化
├── Storage/              存储后端（MongoDB、Redis、Filesystem）
├── Config.php            配置加载
├── Router.php            路由匹配 + 限速
├── Log.php               日志核心模型
├── Id.php                ID 生成与编解码
└── ...
Config.inc.php            全部配置（gitignored）
Config.inc.example.php    配置模板
core.php                  引导文件，定义 CORE_PATH + 自动加载器
```

### 核心流程

1. **上传**：`POST /1/log` → ContentParser 解析请求体 → 过滤链脱敏 → Log::put() → MongoDB 写入 → Redis 缓存
2. **读取**：`GET /1/raw/{id}` → Redis 缓存查询 → 未命中则 MongoDB 回源 → TTL 续期
3. **分析**：`GET /1/insights/{id}` → 加载日志 → Detective 自动检测服务端类型 → Codex 解析 → Sherlock 混淆映射反解 → 格式化输出
4. **删除**：`DELETE /1/log/{id}` → Token 鉴权 → MongoDB 删除 → Redis 缓存清理

### ID 编码

ID 为 7 位字符，首字符编码存储后端类型。例如 `mAbCdE`：
- 首字符 `m` 通过校验和算法编码，解码后得到实际存储后端 ID
- 后 6 位为随机字符，字符集可配置但禁止修改（会破坏所有已有 ID）

### 限速

路由支持按 IP 的 Redis 限速，在 `api/routes.php` 中为每条路由配置 `[请求数, 时间窗口秒]`。限速命中返回 HTTP 429。

## 开发说明

本项目无测试框架、无 CI、无 lint/typecheck 配置。如需添加测试或格式化工具，自行配置。

路由注册在 `Router::getRoutes()`（`src/Router.php`），每行一条：

```php
['METHOD', '/path', HandlerClass::class, [rate_limit, window]],
['GET', '/1/raw/{id}', \Handler\RawHandler::class, [36000, 60]],
```

添加新端点只需加一行路由定义 + 创建对应的 `src/Handler/` 类。

## 许可证

MIT
