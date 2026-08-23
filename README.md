# LogShare v1.7.0

Minecraft / Hytale 日志分析与分享平台。基于 Aternos Codex 与 SpinYarn 构建，提供日志上传、自动诊断、敏感信息脱敏、多文件日志和大模型 AI 智能体（LogAgent）辅助分析能力。

## 功能特性

- **自动日志识别**：支持 Paper、Spigot、Bukkit、Forge、Fabric、NeoForge、Vanilla 服务端及客户端日志，自动检测服务端类型和版本
- **混淆映射反解**：集成 SpinYarn 引擎，使用预下载的对应版本映射，还原可读堆栈信息
- **敏感信息脱敏**：上传时自动过滤 IPv4/IPv6 地址、用户名、Access Token，支持可配置的过滤链
- **结构化分析**：基于 Codex-Minecraft 和 Codex-Hytale 解析引擎，提取错误堆栈、崩溃原因、性能问题
- **多文件日志**：同一 ID 下可上传多个文件或 `.zip` 压缩包（自动展开），子文件按路径读取
- **LogAgent（AI 智能体）**：模型驱动工具循环，LLM 自主调用网络搜索（Exa MCP）、内置 RAG（SQLite FTS5）与日志文件工具，SSE 流式透传思维链
- **内置 RAG**：纯本地 SQLite FTS5（BM25）知识库检索，零网络、零 embedding，整合进主进程 `/rag` 路径
- **RESTful API**：同时提供 `/1/`（已弃用）和 `/v1/` 路径，支持单 ID 和多 ID（逗号分隔）操作，删除采用 Bearer Token 鉴权
- **Redis 缓存**：可配置开关、TTL 和大小限制，超过阈值自动跳过缓存直读 MariaDB

## 环境要求

- PHP 8.4+
- Swoole 6.2（常驻进程运行时）
- 扩展：ext-json、ext-zlib、ext-mbstring、ext-pdo_mysql、ext-redis（可选）
- SpinYarn 扩展（可选，反混淆，缺省时日志原样透传）
- MariaDB 或文件系统（主要存储）
- Redis（可选缓存层）
- SQLite（RAG 知识库检索）

## 快速开始

### 安装

```bash
composer install
cp Config.inc.example.php Config.inc.php
```

### 启动

```bash
php bin/hyperf.php start
```

服务监听 9501 端口，RAG MCP server 承载于主 server 的 `/rag` 路径。

### 配置

`Config.inc.php` 包含全部配置项，主要模块：

| 配置段 | 说明 |
|---|---|
| `storage` | 存储后端配置（MariaDB ↔ 文件系统二选一）、上传限制（`uploadFiles`） |
| `cache` | Redis 缓存配置（开关、TTL、大小限制）及连接信息 |
| `ai` | AI API Key 列表、接口地址、模型名称、`agent`（LogAgent 开关）、`mcp`（webSearch / rag 端点） |
| `filter` | 预处理过滤链，按顺序执行 |
| `id` | ID 字符集和长度（修改会破坏现有 ID） |
| `urls` | 前端和 API 的基础 URL |
| `rateLimit` | 限流配置（limit / window，Redis INCR） |
| `spinyarn` | 反混淆扩展配置（映射目录、缓存水位） |

> 支持环境变量覆盖：`REDIS_HOST`、`REDIS_PORT`、`REDIS_TIMEOUT`、`AI_API_KEYS`（逗号分隔）、`AI_BASE_URL`、`AI_MODEL`。数据库连接由 `DB_*` 环境变量提供（`config/autoload/databases.php`）。

### Docker 部署

```bash
docker compose -f docker/compose.yaml up -d
```

服务监听 9300 端口（nginx 反向代理），包含以下容器：

- **nginx**：反向代理，`proxy_buffering off` 支持 SSE，请求体限制 210MB
- **hyperf**：Hyperf 常驻进程（Swoole 6.2 + SpinYarn + pdo_mysql + redis），启动时自动建表并重建 RAG 索引
- **mariadb**：MariaDB 11，schema 由 `docker/mariadb-init.sql` 自动创建
- **redis**：Redis 7 Alpine，缓存层与限流

### 手动部署

Nginx 反向代理到 Hyperf 9501 端口，参考 `docker/mclogs.conf`：

```
location / {
    proxy_pass http://127.0.0.1:9501;
    proxy_buffering off;
    proxy_read_timeout 300s;
}
```

## API 文档

同时提供 `/1/`（已弃用）和 `/v1/` 端点。建议新集成使用 `/v1/`。响应格式统一为 JSON（`/1/raw`/`/v1/raw` 返回纯文本）。

完整 OpenAPI 3.1 规范见 [`openapi.yaml`](openapi.yaml)，Postman Collection 见 [`postman_collection.json`](postman_collection.json)。

### 日志管理

```
POST   /1/log              上传日志（旧版，保持兼容）
POST   /v1/log              上传日志（新版）
DELETE /1/log/{id}          删除日志
DELETE /v1/log/{id}          删除日志
GET    /1/raw/{id}          获取原始日志（主文件）
GET    /v1/raw/{id}          获取原始日志（主文件）
GET    /v1/raw/{id}/{filename}  获取指定子文件（支持子路径）
GET    /v1/log/{id}          获取日志元信息与文件列表
```

**上传日志**：接受 `application/x-www-form-urlencoded` 和 `application/json`，支持 gzip/deflate 压缩。字段：

| 字段 | 类型 | 说明 |
|---|---|---|
| `content` | string | 日志内容（多文件上传时可为空） |
| `files` | array | 附加文件数组 `{name, content}`，`.zip` 自动展开 |
| `metadata[]` | array | 可选元数据 |
| `source` | string | 来源标识（最长 64 字符） |

多文件上传示例：

```json
{
    "files": [
        { "name": "crash-reports/crash-01.txt", "content": "---- Crash Report ----" },
        { "name": "logs.zip", "content": "<zip 二进制>" }
    ]
}
```

上传成功返回：

```json
{
    "id": "sAbCdEf",
    "url": "https://logshare.cn/sAbCdEf",
    "raw": "https://api.logshare.cn/1/raw/sAbCdEf",
    "token": "f3a2b1..."
}
```

> `/v1/log` 返回格式相同，`raw` URL 指向 `/v1/raw/`。

**删除日志**：通过 `Authorization: Bearer <token>` 鉴权，token 来自上传响应。支持多 ID：`DELETE /1/log/id1,id2`。

### 日志分析

```
GET  /1/insights/{id}       获取分析结果
GET  /v1/insights/{id}       获取分析结果
POST /1/analyse             直接分析日志内容（不存储）
POST /v1/analyse             直接分析日志内容（不存储）
```

`/insights` 返回 Codex 解析引擎的结构化分析结果，包含服务端版本、错误信息、堆栈跟踪等。

### AI 分析

```
GET  /1/ai/{id}             基于已存储日志的 AI 分析
GET  /v1/ai/{id}             基于已存储日志的 AI 分析
POST /1/ai/analyse          提交内容直接分析（不落盘）
POST /v1/ai/analyse          提交内容直接分析（不落盘）
```

AI 分析使用 SSE（Server-Sent Events）流式输出。配置 API Key 后，系统自动轮换 Key 处理限流。

**LogAgent 模式**（`ai.agent.enabled = true`）：模型驱动工具循环，LLM 可自主调用：

| 工具 | 说明 | 配置 |
|---|---|---|
| `web_search_exa` | Exa 网络搜索 | `ai.mcp.webSearch.url` |
| `rag_search` | 内置 RAG 知识库检索 | `ai.mcp.rag.url` |
| `list_log_files` / `read_log_file` | 会话日志文件读取（分页） | 绑定分析日志 ID |

SSE 在原有 `data:` 正文增量基础上，扩展 `event: status` 事件（`thinking` 思维链 / `tool` / `tool_result` / `limit`），供前端展示思考过程。

**内置 RAG**：`rag/` 目录提供 SQLite FTS5（BM25）纯本地检索。构建索引 `php bin/hyperf.php rag:build`，随 Hyperf 主进程启动，默认 `ai.mcp.rag.url = http://127.0.0.1:9501/rag`。

### 其他

```
GET  /1/limits              获取速率限制信息
GET  /v1/limits              获取速率限制信息
GET  /1/filters             获取当前启用的过滤器列表
GET  /v1/filters             获取当前启用的过滤器列表
```

完整 API 规范见 [`openapi.yaml`](openapi.yaml)，Postman Collection 见 [`postman_collection.json`](postman_collection.json)。

## 架构

### 目录结构

```
bin/hyperf.php            入口文件（Hyperf Application）
core.php                  引导文件（定义 CORE_PATH + 加载 Config）
app/                      核心类库（App\ 命名空间）
├── Agent/                LogAgent（模型驱动工具循环）
├── Cache/                Redis 缓存实现 + CacheInterface
├── Client/               AI、MCP、Redis、SpinYarn 客户端
├── Command/              Hyperf 命令（rag:build）
├── Controller/           HTTP 控制器（注解路由）
├── Data/                 数据模型（Token、MetadataEntry）
├── Filter/               预处理过滤链
├── Middleware/           CORS、限流中间件
├── Rag/                  RagSearch（SQLite FTS5 检索）
├── Storage/              存储后端（MariaDbStorage、FilesystemStorage）
├── Config.php            配置加载器 + 环境变量覆盖
├── ContentParser.php     请求体解析（含 files 数组）
├── UploadParser.php      多文件校验 + zip 展开
├── Log.php               日志核心模型
└── Id.php                ID 生成与编解码
config/autoload/          Hyperf 框架配置（server、databases、middlewares 等）
rag/                      内置 RAG（SQLite FTS5）
├── knowledge/            静态知识库文档
└── index.db              索引（构建生成，勿提交）
Config.inc.php            全部配置（gitignored）
Config.inc.example.php    配置模板
AGENTS.md                 项目上下文文档
API.md                    完整 API 文档
CHANGELOG.md              更新日志
```

### 核心流程

1. **上传**：`POST /v1/log` → ContentParser 解析请求体（`content` / `files`） → UploadParser 展开 zip → 过滤链脱敏 → 反混淆（SpinYarn） → Log::put() → MariaDB/文件系统写入 → Redis 缓存（可选）
2. **读取**：`GET /v1/raw/{id}`（或 `/{id}/{filename}`） → Redis 缓存查询（如启用） → 未命中则 MariaDB/文件系统回源 → TTL 续期
3. **分析**：`GET /v1/insights/{id}` → 加载日志 → Detective 自动检测服务端类型 → Codex 解析 → 格式化输出
4. **AI 分析**：`GET /v1/ai/{id}` → LogAgent 工具循环 → LLM 流式 → 工具调用（Exa 搜索 / RAG 检索 / 文件读取）→ SSE 输出思维链与结论
5. **删除**：`DELETE /v1/log/{id}` → Token 鉴权 → MariaDB/文件系统删除 → Redis 缓存清理（如启用）

### ID 编码

ID 为 7 位字符，首字符编码存储后端类型。例如 `sAbCdEf`：

- 首字符 `s` 通过校验和算法编码，解码后得到实际存储后端 ID（`s`=MariaDB / `f`=文件系统）
- 后 6 位为随机字符，字符集可配置但禁止修改（会破坏所有已有 ID）

### 限速

全局 `RateLimitMiddleware` 按 IP + method + path 做 Redis `INCR`+`EXPIRE` 限流（配置 `rateLimit`，默认 36000/60s），命中返回 HTTP 429；Redis 不可用时 fail-open。

## 开发说明

### 测试

```bash
composer test              # Pest 测试套件
composer test:architecture # Pest 架构约束
composer stan              # PHPStan 静态分析（level 5）
```

测试覆盖过滤器、存储、上传解析、MCP 客户端、LogAgent 工具循环、RAG 检索、Controller 集成与架构约束。Termux 环境运行 PHPStan 需加 `PHPSTAN_TURBO=0`。

### 路由注册

在 `app/Controller/` 中新增控制器，通过注解声明路由：

```php
#[Controller(prefix: '/{version:v?1}')]
class ExampleController extends AbstractController
{
    #[GetMapping(path: 'example')]
    public function example(): ResponseInterface { ... }
}
```

`/{version:v?1}` 前缀同时匹配 `/1/`（已弃用）与 `/v1/`。路径支持 `{param}`（单段）与 `{param:.+}`（含斜杠的通配段，如子文件名）。

### RAG 索引构建

```bash
php bin/hyperf.php rag:build   # 扫描 rag/knowledge/ 构建 SQLite FTS5 索引
```

数据库路径由 `ai.mcp.rag.db` 指定（默认 `rag/index.db`）；Docker Compose 部署时随 hyperf 容器启动自动重建索引。

更新日志见 [`CHANGELOG.md`](CHANGELOG.md)。

## 许可证

MIT
