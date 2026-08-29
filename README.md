# LogShare v1.7.3-hotfix.1

Minecraft / Hytale 日志分析与分享平台。基于 Aternos Codex 与 SpinYarn 构建，提供日志上传、自动诊断、敏感信息脱敏、多文件日志和大模型 AI 智能体（LogAgent）辅助分析能力。

## 功能特性

- **自动日志识别**：支持 Paper、Spigot、Bukkit、Forge、Fabric、NeoForge、Vanilla 服务端及客户端日志，自动检测服务端类型和版本
- **混淆映射反解**：集成 SpinYarn 引擎，使用预下载的对应版本映射，还原可读堆栈信息
- **敏感信息脱敏**：上传时自动过滤 IPv4/IPv6 地址、用户名、Access Token，支持可配置的过滤链
- **结构化分析**：基于 Codex-Minecraft 和 Codex-Hytale 解析引擎，提取错误堆栈、崩溃原因、性能问题
- **多文件日志**：同一 ID 下可上传多个文件或 `.zip` 压缩包（自动展开），子文件按路径读取
- **LogAgent（AI 智能体）**：模型驱动工具循环，LLM 自主调用网络搜索（Exa MCP）、内置 RAG（SQLite FTS5）与日志文件工具，SSE 流式透传思维链
- **内置 RAG**：SQLite FTS5（BM25）知识库检索，默认纯本地运行；可选 bge-m3 向量召回主排序（词法结果补充），整合进主进程 `/rag` 路径
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

服务监听 9501 端口，RAG MCP server 承载于主 server 的 `/rag` 路径；该端点默认只允许本机回环访问。

### 配置

`Config.inc.php` 包含全部配置项，主要模块：

| 配置段 | 说明 |
|---|---|
| `storage` | 存储后端配置（MariaDB ↔ 文件系统二选一）、上传限制（`uploadFiles`） |
| `cache` | Redis 缓存配置（开关、TTL、大小限制）及连接信息（`password`/`database` 可选） |
| `ai` | AI API Key 列表、接口地址、模型名称、`agent`（LogAgent 开关）、`mcp`（webSearch / rag 端点） |
| `filter` | 预处理过滤链，按顺序执行 |
| `id` | ID 字符集和长度（修改会破坏现有 ID） |
| `urls` | 前端和 API 的基础 URL |
| `rateLimit` | 限流配置（limit / window，Redis INCR） |
| `spinyarn` | 反混淆扩展配置（映射目录、缓存水位） |

> `.env` 统一保存敏感配置与 AI 配置：`AI_ENABLED`、`AI_API_KEYS`（逗号分隔）、`AI_BASE_URL`、`AI_MODEL`、`AI_RAG_PROVIDERS`（JSON 数组）。数据库和 Redis 凭据也由 `.env` 提供。

### Docker 部署

```bash
docker compose -f docker/compose.yaml up -d
```

生产部署同样使用 `docker/compose.yaml`。启动前复制 `.env.example` 为 `.env` 并填写密码/API Key；`.env` 由 Hyperf 读取，同时供 Compose 初始化 MariaDB/Redis。AI 默认关闭，只有同时设置 `AI_ENABLED=true`、`AI_API_KEYS`、`AI_BASE_URL` 和 `AI_MODEL` 后才会启用；语义 RAG 启用时还会校验 `AI_RAG_PROVIDERS`。Docker 构建已固定 PHP、Rust、Composer、扩展安装器及 SpinYarn 版本；完整业务配置（主推理密钥、双供应商语义 RAG、生产域名）通过挂载项目根目录的 `Config.inc.php` 注入容器，服务器上放置好该文件后：

```bash
docker compose -f docker/compose.yaml up -d --build
```

> `Config.inc.php` 含密钥，已被 Git 与 Docker 构建上下文排除——只需在服务器手动放置，切勿提交进仓库。挂载后 AI 配置以文件为准；`DB_*`/`REDIS_*` 容器网络寻址仍由 compose env 提供。
>
> 内置 `/rag` MCP 端点默认仅允许 Hyperf 本机回环调用。若通过反向代理或外部 MCP 客户端访问，在 `Config.inc.php` 的 `ai.mcp.rag.authToken` 设置随机强 token，并使用 `Authorization: Bearer <authToken>`；不要将未配置 token 的 `/rag` 端点暴露到公网。

Nginx 对外监听 80/443，Hyperf 仅在 Compose 内部网络监听 9501，包含以下容器：

- **nginx**：OpenResty（Nginx + Lua）反向代理，内置 LiteWAF 边缘防护；配置文件为 `docker/nginx/default.conf` 与 `LiteWAF/`，证书放在 `docker/certs/`
- **mariadb**：启用 Event Scheduler，每小时由数据库直接删除超过 7 天 TTL 的日志
- **hyperf**：Hyperf 常驻进程（Swoole 6.2 + SpinYarn + pdo_mysql + redis），启动命令会先构建 RAG 索引；索引完成后原子替换，构建失败不会破坏旧索引
- **mariadb**：MariaDB 11，schema 由 `docker/mariadb-init.sql` 自动创建
- **redis**：Redis 7 Alpine，缓存层与限流

LiteWAF（`LiteWAF/`）是以 OpenResty Lua 实现的极简边缘 WAF：对每个 IP 做固定窗口 CC 限流与封禁，并拦截 SQL 注入、XSS、路径穿越及扫描器探测等明显攻击特征，触发时返回 403 警告页。公开的极简安全统计页位于 `https://<域名>/security`（JSON：`/security/stats`），仅展示内存计数，不含 IP 等敏感信息，进程重启后清零。规则与阈值调整见 `LiteWAF/README.md`；改动 Lua 文件后执行 `docker compose -f docker/compose.yaml exec nginx nginx -s reload` 生效。注意 LiteWAF 不能替代应用层安全，也建议在前置 CDN/WAF 层做更高强度的速率限制。

将 `docker/nginx/default.conf` 中两个 `server_name` 改为实际域名，并将 HTTPS 证书路径中的域名同步修改。首次签发前，需先临时注释 HTTPS server，启动 Nginx 后执行：

```bash
docker compose -f docker/compose.yaml run --rm acme certonly --webroot -w /var/www/acme -d api.example.com --email admin@example.com --agree-tos --no-eff-email
```

恢复 HTTPS 配置后重新加载 Nginx：

```bash
docker compose -f docker/compose.yaml up -d nginx
docker compose -f docker/compose.yaml exec nginx nginx -s reload
```

`acme` 容器每 12 小时检查续期；续期完成后需执行一次 Nginx reload 使新证书生效。证书目录不会提交到 Git，域名解析须指向服务器且公网开放 80 端口。MariaDB 的 Event Scheduler 每小时清理超过 `Config.inc.php` 中 `storage.storageTime` 秒的日志，默认值为 604800 秒（7 天）。首次部署或修改 TTL 后，先执行 `php scripts/sync_mariadb_events.php` 生成 SQL，再重启 `mariadb-events` 服务；生成文件不会提交到 Git。可用以下命令检查 Event 是否启用及下次执行时间：

```bash
docker compose -f docker/compose.yaml exec mariadb mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" logshare -e "SHOW EVENTS LIKE 'cleanup_expired_logs'\\G"
```

MariaDB healthcheck 也会验证该 Event 存在且状态为 `ENABLED`。

MariaDB 使用固定名称的 Docker 卷 `logshare-mariadb-data`。生产环境禁止使用默认密码，建议通过部署环境或 Docker secrets 注入凭据，重建容器不会删除数据。首次部署时，`mariadb` 会初始化表结构，`mariadb-events` 会在数据库可用后创建清理 Event；修改 `Config.inc.php` 中的 TTL 后重新运行同步脚本并重启 `mariadb-events`。不要执行 `docker compose down -v`，否则会删除持久化卷。

### 手动部署

如需 Nginx 反向代理，参考 `docker/mclogs.conf`：

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

**内置 RAG**：`rag/` 目录提供 SQLite FTS5（BM25）本地检索。索引需先执行 `php bin/hyperf.php rag:build`（Docker 启动命令会自动执行）；构建过程使用临时数据库，完成后原子替换正式索引，失败时保留旧索引。RAG MCP 服务随 Hyperf 主进程一并启动（`/rag` 路径），默认 `ai.mcp.rag.url = http://127.0.0.1:9501/rag`；该端点默认仅允许本机回环访问。请求体不设应用层大小限制（MCP transport 的有意设计），但 `rag_search.query` 受服务端长度限制。通过反代或外部访问时，需配置 `ai.mcp.rag.authToken` 并携带 Bearer Token。

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

全局 `RateLimitMiddleware` 按 IP + method + **归一化路径** 做 Redis `INCR`+`EXPIRE` 限流（动态资源段如 `/v1/raw/{id}` 折叠为 `/v1/raw/*` 共享计数桶，防止随机 id 绕过限流；配置 `rateLimit`，默认 36000/60s），命中返回 HTTP 429；Redis 不可用时 fail-open。

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

> **知识库维护：** Forge/NeoForge 文档用 `scripts/download_modloader_docs.sh` 刷新，服务端/代理端文档（PaperMC 全家桶、Purpur、Glowstone、Geyser、Quilt）用 `scripts/download_server_docs.sh`——两个脚本拉取后自动执行清洗（剥离 frontmatter/MDX/admonition/HTML）。新增机器拉取的知识库目录时需同步登记到 `scripts/clean_knowledge_docs.php` 的 `UPSTREAM_DIRS` 白名单；手写蒸馏目录不受元文件删除规则影响。语义 RAG 开启后需重跑 `rag:build` 生成分块向量。

数据库路径由 `ai.mcp.rag.db` 指定（默认 `rag/index.db`）；Docker Compose 部署时 hyperf 容器启动命令会执行 `rag:build`（幂等）。

更新日志见 [`CHANGELOG.md`](CHANGELOG.md)。

## 许可证

MIT
