# LogShare

LogShare 是一个 Minecraft / Hytale 日志分析与分享平台。使用者通过 HTTP 接口上传服务端或客户端日志，获得一个可分享的短链接；系统在上传时自动识别日志类型与服务端版本，执行敏感信息脱敏，并借助 Aternos Codex 解析引擎与 SpinYarn 混淆映射反解，输出结构化的诊断结果。在此之上，平台提供可选的 AI 分析能力：LogAgent 智能体由大模型驱动工具循环，可自主调用网络搜索、内置知识库检索（RAG）与日志文件读取工具，并以 SSE 流式输出思维链与结论。

服务端为 Hyperf 3.2 常驻进程，运行于 Swoole 6.2 协程运行时，要求 PHP 8.4 及以上。存储后端支持 MariaDB 与文件系统二选一，Redis 作为可选的缓存与限流层。当前版本 v1.7.4，更新记录见 [`CHANGELOG.md`](CHANGELOG.md)。

## 环境要求

- PHP 8.4+，扩展 ext-json、ext-zlib、ext-mbstring、ext-pdo_mysql、ext-pdo_sqlite
- Swoole 6.2（常驻进程运行时）
- MariaDB 或文件系统（主要存储，二选一）
- Redis（可选，用于缓存与限流；缺少 ext-redis 时以内置 mock 降级）
- SQLite（RAG 知识库检索）
- SpinYarn 扩展（可选，用于混淆堆栈反解；缺失时日志原样透传）

## 安装与启动

```bash
composer install
cp Config.inc.example.php Config.inc.php
php bin/hyperf.php start
```

`Config.inc.php` 是唯一的应用配置文件（已被 Git 忽略），数据库与 Redis 连接信息可通过 `DB_*`、`REDIS_*` 环境变量覆盖。服务监听 `9501` 端口；内置 RAG 的 MCP 服务承载于同一进程的 `/rag` 路径，默认仅允许本机回环访问。

## 配置

`Config.inc.php` 的主要配置段：

| 配置段 | 说明 |
|---|---|
| `storage` | 存储后端（MariaDB 与文件系统二选一）、日志保留时间（TTL）、上传限制（`uploadFiles`） |
| `cache` | Redis 缓存：开关、TTL、大小限制与连接信息 |
| `filter` | 上传前的预处理过滤链（脱敏规则），按配置顺序执行 |
| `id` | 日志 ID 的字符集与长度（修改会破坏已有 ID） |
| `ai` | AI 相关：API Key 列表、接口地址、模型名称、`agent`（LogAgent 开关）、`mcp`（webSearch / rag 端点） |
| `rateLimit` | 应用层限流（limit / window，Redis INCR 实现） |
| `spinyarn` | 反混淆扩展：映射目录与缓存水位 |
| `urls` | 前端与 API 的基础 URL |

## Docker 部署

```bash
docker compose -f docker/compose.yaml up -d --build
```

启动前先将 `.env.example` 复制为 `.env` 并填写数据库密码与 API Key。`.env` 由 Hyperf 进程读取，同时供 Compose 初始化 MariaDB 与 Redis 服务，其中 `MARIADB_PASSWORD`、`MARIADB_ROOT_PASSWORD` 与 `REDIS_PASSWORD` 必须在 Hyperf、MariaDB、Redis 与定时清理服务之间保持一致。AI 默认关闭；只有同时设置 `AI_ENABLED=true`、`AI_API_KEYS`、`AI_BASE_URL` 和 `AI_MODEL` 后才会启用，语义 RAG 启用时还要求 `AI_RAG_PROVIDERS` 为合法的 JSON 数组。

完整的业务配置（推理密钥、语义 RAG 供应商、生产域名）通过挂载项目根目录的 `Config.inc.php` 注入容器，服务器上放置好该文件后执行上面的命令即可。该文件包含密钥，已被 Git 与 Docker 构建上下文排除，只需在服务器上手动放置，不要提交进仓库。挂载后 AI 配置以文件为准；`DB_*` 与 `REDIS_*` 的容器网络寻址仍由 compose 环境变量提供。

Compose 定义五个服务：**nginx**（OpenResty 反向代理，对外监听 80/443，承载 OpenLiteWaf 与 TLS 证书）；**hyperf**（应用主进程，仅在内部网络监听 9501，启动时自动构建 RAG 索引，构建采用临时数据库加原子替换，失败时保留旧索引）；**mariadb**（数据存储，表结构由 `docker/mariadb-init.sql` 在首次创建卷时初始化）；**mariadb-events**（在数据库可用后创建过期日志清理 Event）；**redis**（缓存与限流）。

OpenLiteWaf（`OpenLiteWaf/`）是 nginx 容器内的 OpenResty Lua 模块，在反向代理层做按 IP 的固定窗口 CC 限速与封禁，以及 SQL 注入、XSS、路径穿越、命令执行、扫描器探测等特征检查；检查范围包括 URL（原始与解码形态）、User-Agent 和请求体（日志上传端点豁免），拦截时返回 403 页面。统计页 `https://<域名>/security`（JSON 汇总：`/security/stats`，攻击日志分页：`/security/logs`）展示内存计数与脱敏后的攻击记录，进程重启后清零。工作方式、配置与规则编写见 `OpenLiteWaf/README.md`；改动 Lua 文件后执行 `docker compose -f docker/compose.yaml exec nginx nginx -s reload` 生效（`git pull` 只更新文件，不 reload 不生效）。应用层安全由 Hyperf 负责，参数化查询、输出转义与脱敏过滤链不依赖本模块。

### HTTPS 证书

将 `docker/nginx/default.conf` 中两个 `server_name` 改为实际域名，并将 HTTPS 证书路径中的域名同步修改。首次签发前，先临时注释 HTTPS server 并启动 nginx，然后执行：

```bash
docker compose -f docker/compose.yaml run --rm acme certonly --webroot -w /var/www/acme -d api.example.com --email admin@example.com --agree-tos --no-eff-email
```

恢复 HTTPS 配置后重新加载 nginx：

```bash
docker compose -f docker/compose.yaml up -d nginx
docker compose -f docker/compose.yaml exec nginx nginx -s reload
```

`acme` 容器每 12 小时检查续期，续期完成后需要执行一次 nginx reload 使新证书生效。证书目录不提交到 Git；域名需解析到服务器且公网开放 80 端口。

### 日志过期清理

MariaDB 的 Event Scheduler 每小时删除超过 `Config.inc.php` 中 `storage.storageTime` 秒的日志，默认 604800 秒（7 天）。仓库内的 `docker/mariadb-events.sql` 是与默认 TTL 对应的默认版本，由 `mariadb-events` 服务在数据库可用后执行；修改 TTL 后先执行 `php scripts/sync_mariadb_events.php` 重新生成该 SQL，再重启 `mariadb-events` 服务，重新生成的文件按需提交以保持与部署一致。可用以下命令检查 Event 是否启用及下次执行时间：

```bash
docker compose -f docker/compose.yaml exec mariadb mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" logshare -e "SHOW EVENTS LIKE 'cleanup_expired_logs'\\G"
```

mariadb 的 healthcheck 也会验证该 Event 存在且状态为 `ENABLED`。MariaDB 使用固定名称的卷 `logshare-mariadb-data`，重建容器不会删除数据；不要执行 `docker compose down -v`，否则持久化卷会被删除。生产环境禁止使用默认密码，建议通过部署环境或 Docker secrets 注入凭据。

### 手动部署（无 Docker）

直接运行 `php bin/hyperf.php start` 并使用任意反向代理转发到 9501 即可，nginx 参考配置：

```nginx
location / {
    proxy_pass http://127.0.0.1:9501;
    proxy_buffering off;
    proxy_read_timeout 300s;
}
```

## API

同时提供 `/1/`（已弃用）与 `/v1/` 两组路径，响应格式统一为 JSON（`/raw` 端点返回纯文本），建议新集成使用 `/v1/`。完整规范见 [`API.md`](API.md)、[`openapi.yaml`](openapi.yaml) 与 [`postman_collection.json`](postman_collection.json)。

```
POST   /1/log | /v1/log                          上传日志（支持多文件与 ZIP）
DELETE /1/log/{id} | /v1/log/{id}                删除日志（Bearer Token 鉴权）
GET    /1/raw/{id} | /v1/raw/{id}                获取原始日志（主文件）
GET    /v1/raw/{id}/{filename}                   获取指定子文件（支持子路径）
GET    /1/log/{id} | /v1/log/{id}                获取日志元信息与文件列表
GET    /1/insights/{id} | /v1/insights/{id}      获取结构化分析结果
POST   /1/analyse | /v1/analyse                  直接分析日志内容（不存储）
GET    /1/ai/{id} | /v1/ai/{id}                  AI 分析（SSE 流式）
POST   /1/ai/analyse | /v1/ai/analyse           提交内容直接分析（不落盘，SSE）
GET    /1/limits | /v1/limits                    获取速率限制信息
GET    /1/filters | /v1/filters                  获取当前启用的过滤器列表
```

上传接口接受 `application/x-www-form-urlencoded` 与 `application/json`，支持 gzip / deflate 压缩请求体；`files` 数组可附加多个文件，`.zip` 压缩包自动展开并保留内部相对路径，展开后每个文件独立经过脱敏过滤链。上传响应中的 `token` 是删除该日志的唯一凭证。AI 分析使用 SSE 流式输出，LogAgent 模式下模型可自主调用网络搜索（Exa MCP）、RAG 检索与当前日志的文件读取工具；AI 关闭时相关端点统一返回 404。

## 架构

### 目录结构

```
bin/hyperf.php            入口文件（Hyperf Application）
core.php                  引导文件（定义 CORE_PATH 并加载 Config）
app/                      核心类库（App\ 命名空间）
├── Agent/                LogAgent（模型驱动工具循环）
├── Cache/                Redis 缓存实现
├── Client/               AI、MCP、Redis、SpinYarn 客户端
├── Command/              Hyperf 命令（rag:build）
├── Controller/           HTTP 控制器（注解路由）
├── Data/                 数据模型（Token、MetadataEntry）
├── Filter/               预处理过滤链
├── Middleware/           CORS、限流中间件
├── Rag/                  RAG 检索（SQLite FTS5）
├── Sse/                  SSE 输出（SseWriter）
├── Storage/              存储后端（MariaDbStorage、FilesystemStorage）
├── ApiError.php          API 错误异常（ApiExceptionHandler 渲染）
├── ApiResponse.php       统一响应结构
├── Config.php            配置加载器与环境变量覆盖
├── ContentParser.php     请求体解析（含 files 数组）
├── Detective.php         日志类型与服务端版本检测
├── UploadParser.php      多文件校验与 zip 展开
├── Log.php               日志核心模型
└── Id.php                ID 生成与编解码
config/autoload/          Hyperf 框架配置（server、databases、middlewares 等）
rag/                      内置 RAG：knowledge/ 静态知识库，index.db 索引（构建生成，勿提交）
docker/                   Compose 编排、nginx 站点配置、镜像构建
OpenLiteWaf/                  边缘 WAF（OpenResty Lua，含独立文档与测试）
Config.inc.php            全部配置（gitignored）
Config.inc.example.php    配置模板
AGENTS.md                 项目上下文文档（架构约定与协作规范）
API.md                    完整 API 文档
CHANGELOG.md              更新日志
docs/                     历史过程文档（迁移计划、审查报告、排障记录）
```

### 请求处理流程

1. **上传**：`POST /v1/log` → ContentParser 解析请求体（`content` / `files`）→ UploadParser 校验并展开 zip → 过滤链脱敏 → SpinYarn 反混淆 → 写入 MariaDB / 文件系统 → 可选写入 Redis 缓存。
2. **读取**：`GET /v1/raw/{id}`（或子文件路径）→ Redis 缓存查询（如启用）→ 未命中时回源存储 → TTL 续期。
3. **分析**：`GET /v1/insights/{id}` → 加载日志 → Detective 检测服务端类型与版本 → Codex 引擎解析 → 格式化输出。
4. **AI 分析**：`GET /v1/ai/{id}` → LogAgent 工具循环 → LLM 流式输出 → 按需调用 Exa 搜索、RAG 检索、日志文件工具 → SSE 输出思维链与结论。
5. **删除**：`DELETE /v1/log/{id}` → Bearer Token 鉴权（token 哈希比对）→ 删除存储与缓存。

### ID 编码

日志 ID 为 7 位字符，首字符编码存储后端类型：`s` 表示 MariaDB，`f` 表示文件系统；其余 6 位为随机字符。字符集与长度可配置，但修改会破坏所有已有 ID，配置项 `id.characters` 与 `id.length` 不应改动。

### 限速

公开流量的限速由边缘承担：nginx `limit_req`（30r/s burst=60）与 OpenLiteWaf 的 CC 封禁（10 秒窗口 240 次，超限封禁 IP 600 秒）。仓库中存在 `RateLimitMiddleware`（Redis INCR 按应用层计数，默认 600 次/60 秒，键为 IP + 方法 + 归一化路径，动态资源段折叠共享计数桶），但当前未注册到中间件链，属预留实现；如启用需注意其 Redis 故障路径为返回 503（fail-closed），与注释中的 fail-open 描述不符，见 `app/Middleware/RateLimitMiddleware.php`。

## 开发说明

### 测试

```bash
composer test               # Pest 测试套件
composer test:architecture  # Pest 架构约束测试
composer stan               # PHPStan 静态分析（level 5）
```

测试覆盖过滤器、存储、上传解析、MCP 客户端、LogAgent 工具循环、RAG 检索、Controller 集成与架构约束。存储链路的集成测试需要本地 MariaDB 与 Redis（可用 `docker compose -f docker/compose.yaml up -d` 启动）。Termux 环境运行 PHPStan 需加前缀 `PHPSTAN_TURBO=0`。OpenLiteWaf 有独立的回归测试，见 `OpenLiteWaf/README.md`。

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

`/{version:v?1}` 前缀同时匹配 `/1/` 与 `/v1/`；路径参数支持 `{param}`（单段）与 `{param:.+}`（含斜杠的通配段，用于子文件名）。

### RAG 知识库

```bash
php bin/hyperf.php rag:build   # 扫描 rag/knowledge/ 构建 SQLite FTS5 索引
```

索引构建使用临时数据库，完成后原子替换正式索引，失败时保留旧索引；Docker 部署时 hyperf 容器启动命令会自动执行（幂等）。数据库路径由 `ai.mcp.rag.db` 指定，默认 `rag/index.db`。

知识库文档分两类维护：Forge/NeoForge 开发者文档用 `scripts/download_modloader_docs.sh` 刷新；PaperMC、Purpur、Geyser 等服务端文档用 `scripts/download_server_docs.sh` 刷新。两个脚本拉取后会自动执行清洗（剥离 frontmatter、MDX、admonition 与 HTML 噪声，fenced code 完整保留）。新增机器拉取的知识库目录时，需要同步登记到 `scripts/clean_knowledge_docs.php` 的 `UPSTREAM_DIRS` 白名单；手工维护的目录不受元文件删除规则影响。语义 RAG 开启后需重新执行 `rag:build` 生成分块向量。

## 许可证

MIT
