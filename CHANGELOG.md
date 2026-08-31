# Changelog

## 1.7.4 — 2026-08-30

### 新功能

- **LiteWAF v1.1.0**：规则扩充至 63 条（新增 `rce` 命令执行类目、12 种扫描器 UA、druid/nacos/jenkins/solr 等管理后台探测路径）；检查范围新增完整解码 URL（含 query，堵住 `%20` 编码绕过）与请求体扫描（POST/PUT/PATCH 前 64KB，日志内容端点豁免）；`python-httpx` 等常规客户端 UA 不再误伤
- **公开安全统计页重做**（`/security`）：总览卡片、最近 60 分钟拦截趋势图、类别分布、来源 IP Top（脱敏）、攻击日志分页（`/security/logs`，环形 500 条，每页 50 条，`token=` 参数自动打码）；拦截页重设计为卡片组件（iframe 友好，按特征命中/CC 超限/封禁期区分文案）
- **AI 工具轮次上限**默认值调整为 50

### 修复

- **LiteWAF 线上完全不拦截**：签名匹配依赖 `ngx.re.compile`（lua-resty-core FFI API，非原生），resty.core 未加载时 access 阶段报 nil 中止；现模块主动加载 `resty.core.re`，缺失时自动回退原生 `ngx.re.find` 字符串匹配路径
- **文件存储续期后仍被过期清理删除**（数据丢失）：`CleanupExpired` 改用与 `Get()` 一致的 `created` 时间源（`.meta.json` 优先），续期日志不再误删；`Delete` 同步清理元数据文件
- **构建镜像泄漏 `.env`**：`.dockerignore` 排除 `.env`、`.github`、`docs/` 等非运行时文件，生产密钥不再进入镜像层
- **`read_log_file` 续读与防重复拦截自相矛盾**：仅在完整读取时置已读标记，offset/行区间续读正常工作
- **分析缓存锁在异常路径不释放**：改 `finally` 无条件释放，互斥不再失效
- **SSE 客户端断开后 Agent 循环不中止**：`SseWriter` 检测底层写失败抛 `ClientDisconnectedException`，上层立即停止工具循环，不再浪费上游 API 配额
- **`rag:build` 后 Web 进程沿用旧索引**：`RagController` 按 `filemtime` 检测索引替换，重建后自动生效
- **`rag:build` 失败退出码为 0**：返回 FAILURE，CI 与启动脚本可感知
- **CI 冒烟证书域名硬编码**：改为从 `default.conf` 动态提取
- **`release.yaml` 脚本注入**：commit message 改经环境变量中转
- **`ai.sh` 错误事件被吞**：修复恒 false 的 error 检测条件与子 shell 状态丢失
- `content[]=` 类型污染返回 400 而非 500；`IPv6ShortFilter` 正则失败不再 500；删除日志时缓存删除失败写墓碑标记，杜绝已删日志在 TTL 内可读；URL 上传分析路径错误消息不再双重转义

### 改进

- **全仓库代码审查修复**（详见 `CRCLASH.md`）：共 63 项——存储层（ID 冲突重试、metadata 类型两端对齐、过期清理分批）、过滤链（safe 包装降级可观测、token 过滤器补无引号形态、UUID 预检精准化）、AI/RAG（RagSearch 语义缓存 O(n²) 修复、向量分批扫描、topics 进程级缓存、`hash_equals`）、WAF 与边缘（chunked body 扫描、nginx worker_connections/resolver/HSTS/http2）、部署（compose 镜像 pin、统一日志上限、SpinYarn 构建产物清理、rag:build 启动降级）
- 历史过程文档（迁移计划、审查报告、排障记录）归档至 `docs/`
- README / API.md / LiteWAF/README 重写为开发者文档风格

## 1.7.3-hotfix.1 — 2026-08-25

### 修复

- **AI 分析不可用**：修复内置 RAG 使用回环地址时 MCPClient 属性未初始化，导致 LogAgent 统一返回服务暂时不可用
- **Docker 映射路径**：修正 `docker/compose.yaml` 中 mappings 目录的挂载路径

## 1.7.3 — 2026-08-25

### 修复

- **AI 首次请求空流**：新增 `CURLOPT_ACCEPT_ENCODING` 允许 curl 透明解压，修复上游 gzip SSE 导致 `data:` 行乱码误判为空流的问题；空流失败后自动重试同 key 一次，缓解偶发的首次连接预热问题
- **`/rag` 访问控制**：默认仅允许本机回环访问，可选 `ai.mcp.rag.authToken` Bearer 鉴权；LogAgent 自调用自动携带 Token
- **出站 SSRF 防护**：MCPClient/SemanticClient 拒绝私网地址，未解析主机名不拦截；内置 RAG 仍可用回环
- **限流中间件**：支持 `trustedProxies` 下的 `X-Real-IP`；`/rag` 纳入限流桶
- **RAG 索引原子构建**：先写临时数据库，完成后原子 rename 替换，构建失败保留旧索引
- **日志分析缓存锁**：Redis NX 锁 + 等待缓存，缓解 cache stampede
- **上游错误脱敏**：SSE 统一返回 "AI service temporarily unavailable."，不再暴露上游细节
- **MCP 响应校验**：私网拦截、`data:` 无空格 SSE 兼容、response id 校验
- **PHPStan 排除收窄**：仅保留 `SpinYarnClient.php`
- **文件存储原子写入**：临时文件 + rename 替换；renew 只更新 `.meta.json`

### 改进

- **Compose 统一**：`docker/compose.yaml` 同时满足开发与生产，移除 `docker/compose.prod.yaml`；挂载 `Config.inc.php` 注入业务配置
- **Docker 编排**：移除内置 nginx，Hyperf 绑定回环 `127.0.0.1:9501` 由宿主机反代直连
- **CI 增强**：Docker 构建覆盖 PR、Composer validate/audit、coverage 生成
- **映射下载脚本**：失败返回非零、`.part` 临时文件原子替换、gzip/非空校验
- **MariaDB 外键**：`log_files`/`log_metadata` 增加 `ON DELETE CASCADE`

## 1.7.2-hotfix.2 — 2026-08-25

### 修复

- **SSE 流式响应缺失 CORS 头**：`/v1/ai/*` 等流式端点经 `EventStream` 直写连接，绕过了 `CorsMiddleware` 对响应对象加头的流程，生产环境跨域调用 LogAgent 被浏览器拦截（空响应、status 0）。`SseWriter::begin()` 现于首帧下发前显式携带 CORS 头，并补发 `Cache-Control: no-cache` 与 `X-Accel-Buffering: no` 防止反代缓冲延迟首帧

## 1.7.2 — 2026-08-25

### 修复

- CI：test job 补充 MariaDB schema 初始化步骤——v1.7.1 新增的存储链路集成测试（上传/读取/删除、token 哈希校验）在 CI 环境因 `logs` 表不存在而失败；本地开发时该用例集因无数据库被 skip，掩盖了缺口

## 1.7.1 — 2026-08-25

> 本版本新增语义 RAG：bge-m3 向量召回与 bge-reranker 精排，并扩充知识库内容。

### 新功能

- **语义 RAG 增强**：bge-m3 向量召回 + bge-reranker-v2-m3 精排（`ai.rag` 独立网关/密钥，多供应商 failover），API 异常自动回退纯词法检索
- **知识库扩充**：新增 Forge / NeoForge 官方开发者文档，PaperMC 全家桶（Paper/Velocity/Waterfall/Folia）、Purpur、Glowstone、Geyser、Quilt 服务端文档，以及 Android 启动器生态 issue 蒸馏库（patterns/renderers 等）——共 632 文件 / 2250 分块
- **知识库清洗器**：下载脚本自动剥离 frontmatter / MDX / admonition / HTML 噪声，fenced code 完整保护
- **CJK bigram 检索**：中文长串查询按 2-gram 降级匹配，避免无结果返回
- **tool_result 按工具定制摘要**：读文件只报行数、rag 输出命中清单、list_topics 折叠主题目录
- **生产部署编排** `docker/compose.prod.yaml`：挂载完整 Config.inc.php，四服务 healthcheck

### 改进

- `read_log_file` 全文直返（无行区间参数）+ 会话级防重复读取拦截
- 思维链 `reasoning_content` 按轮回传上游，修复 DeepSeek 思维模式工具调用 400
- 检索 snippet 长度上限提升（1600 字符整段阈值 / ±800 窗口）并保留 Markdown 结构；检索类工具结果限额放宽至 32KB
- 限流 key 归一化防随机 ID 绕过；计数键 SET NX EX 原子初始化
- Redis 支持密码/库选择；语义 RAG 支持 `AI_RAG_*` 环境变量；DB 连接池心跳保活
- SSE 基础设施收敛为 `App\Sse\SseWriter`，日志统一 `App\Syslog`
- 删除令牌落库改为 SHA-256 哈希（兼容存量明文）；Filters/Index 端点由配置动态生成

### 修复

- Docker 镜像补 `pdo_sqlite`（RAG 必需）；REDIS_PASSWORD 双端一致；AI_RAG env 透传
- rerank 返回空结果集时兜底回退词法排序，检索不再「归零」
- 分析结果 JSON 编码失败不再污染进程级缓存
- ApiTest 重写为真实 HTTP 集成测试（上传/读取/删除全链路）

## 1.7.0 — 2026-08-23

> 首个正式版（LTS）。在 1.7.0-beta.1 基础上完成 SpinYarn v1.0.0 去下载化、Docker 部署补全、性能优化与两轮代码审查修复。

### 新功能

- **SpinYarn v1.0.0 LTS**：移除运行时下载能力，映射表改由宿主提供（Git LFS + 下载脚本 + Docker bind mount），SpinYarn 只做「本地加载 + 解析 + LRU 缓存」
- **AI 可完全禁用**：新增 `ai.enabled` 开关 + `AI_ENABLED` 环境变量，禁用后 `/v1/ai/*` 统一返回 404

### 改进

- **Docker 部署补全**：镜像补齐 `composer install`、`zip`/`pcntl`/`posix`/`sockets` 扩展、`Config` 缺失回退示例配置，CI 6 个 job 全部通过
- **性能优化**：过滤器快速预检（IPv6/UUID/IPv4 等，脱敏 -2.3s）、Codex 分析结果进程级缓存（重复访问 ~3.7s → 8ms）
- Redis 连接改为协程级 `Context` 隔离；版本号收敛至 `App\Version`

### 修复

- 修复 CRCLASH 第六轮审查问题（反混淆重复 analyse、AI 环境变量缺口、版本号硬编码、RedisMock 缺 `ping()` 等）
- 映射表纳入 Git LFS 版本管理

## 1.7.0-beta.1 — 2026-08-21

### 新功能

- **Hyperf 3.2 + Swoole 6.2 常驻进程运行时** — 从传统 PHP CLI/FPM 架构迁移至 Hyperf 常驻协程进程（`bin/hyperf.php` 入口），全链路协程化，移除 `index.php`/`Router.php` 请求分发模型
- **SpinYarn 反混淆扩展** — 以自研 SpinYarn PHP 扩展取代已退役的 `aternos/sherlock`；日志写库前反混淆（DB 存反混淆后内容，读取时不再重复反混淆），映射句柄为进程级缓存（避免每次请求 ~110ms 重载）
- **RAG 整合进 Hyperf 进程** — 内置 RAG MCP server 由独立进程整合到主 server 的 `/rag` 路径（JSON-RPC 2.0），新增 `rag:build` 命令构建/重建索引
- **CORS 与限流中间件** — 全局 HTTP 中间件链（Cors → RateLimit），Redis `INCR`+`EXPIRE` 按 IP+method+path 限流，Redis 不可用时 fail-open

### 改进

- **存储层迁移**：MongoDB → MariaDB（`hyperf/database`），表结构 `logs` / `log_files` / `log_metadata`，`Get()` 以 `includeContent` 投影跳过超大文件体
- **代码迁移**：`src/` → `app/`，统一 `App\` PSR-4 命名空间（`composer.json` autoload）
- **路由改造**：`Router.php` 集中路由表 → Hyperf 注解路由（`#[Controller]` + `#[GetMapping]`/`#[PostMapping]`/`#[DeleteMapping]`）
- **AI 流式改造**：Swoole 协程 SSE 输出，流句柄存于协程 `Context`（避免跨请求串扰）
- 数据库连接改由 `DB_*` 环境变量（`config/autoload/databases.php`），替换原 `MONGODB_URI`

### 修复

- 修复 CRCLASH 第四轮审查问题（协程串扰、部署、资源泄漏等）
- RAG 改走主 server 的 `/rag` 路径，规避 Hyperf 多 HTTP server 单例冲突
- SSE 存储检测 Swoole（无则 static），修复 CLI 测试环境
- 修复 CRCLASH 第五轮审查问题：Redis 连接改为协程级隔离（Context）避免跨协程共享单连接串扰、`POST /v1/ai/analyse` 的 `id` 字段绑定失效、`Limit*` 过滤器异常契约、缓存热路径日志噪音等

### 配置变更

- 移除 `mongo` 配置段；数据库连接由 `DB_*` 环境变量提供
- 新增 `spinyarn` 配置段（`mappings_dir` / 缓存水位）；映射表改由下载脚本预生成并提交进仓库（Git LFS），Docker 以 bind mount 挂载，SpinYarn 不再运行时下载
- 存储后端改为 MariaDB（`s`，默认）↔ 文件系统（`f`）

### 测试

- 引入 `hyperf/testing` 编写 Controller 集成测试（HTTP 级）

## 1.6.0 — 2026-08-15

### 新功能

- **多文件日志上传** — `POST /v1/log` 支持 `files` 数组，同一 ID 下可存多个文件（主文件 + 附加文件）；`.zip` 压缩包自动展开（含子目录，路径遍历防护，上限 200 文件 / 12MB）
- **子文件读取** — 新增 `GET /v1/raw/{id}/{filename}`（支持子路径）与 `GET /v1/log/{id}`（元信息 + 文件列表）
- **LogAgent（AI 智能体）** — 模型驱动工具循环：LLM 自主调用工具（网络搜索 / RAG 检索 / 会话日志文件），SSE 流式透传思维链（reasoning_content）与工具事件
- **MCP 客户端** — 新增 `Client\MCPClient`（Streamable HTTP，curl + JSON-RPC 零依赖），接入 Exa WebSearch 托管端点
- **内置 RAG（SQLite FTS5）** — 新增 `rag/` 子系统：纯本地 BM25 检索静态知识库（不依赖网络与外部 embedding 服务），中英文双路检索；`php rag/build_index.php` 建索引，随 Docker Compose 启动自动重建索引
- **会话文件工具** — Agent 的 `list_log_files` / `read_log_file` 仅可访问当前分析日志 ID 下的文件，无法越权读取其他日志

### 改进

- `AIClient` 拆出底层 `streamChat()`，支持 content / reasoning_content / tool_calls 三分支流式解析，多 key 轮询保留
- 上游 AI 请求失败时暴露错误响应体（此前仅 `HTTP 400`，无法诊断）
- `Router` 支持 `{param:.+}` 通配段（子文件路由）+ 路由编译缓存
- 统一错误处理：全局 `set_exception_handler` 兜底，精简 Handler 重复样板
- Filesystem 存储补齐 TTL：`Renew()` 更新 `created`、`CleanupExpired()` 清理过期文件（与 Mongo TTL 语义对齐）
- 配置支持环境变量覆盖（MONGODB_URI / REDIS_HOST / REDIS_PORT / REDIS_TIMEOUT / AI_API_KEYS / AI_BASE_URL / AI_MODEL）
- 性能：Log 行数缓存、路由表一次性编译
- SSE 协议扩展 `event: status`（thinking / tool / tool_result / limit），兼容旧客户端

### 修复

- **ApiError 未继承 Throwable** — 所有 `throw new ApiError` 运行时必 fatal，已修复为继承 `\Exception`
- **架构测试静默失效** — glob 路径错误导致 7 项测试 0 断言（risky 通过），已修复并真实生效（67 断言）
- **PHPStan 无法运行** — `phpstan.neon` 无效配置项，现 level 5 零错误（覆盖 `src` + `rag`）
- **MongoCache 缺陷** — 补 `Delete()` 实现，`Set()` 的 updateOne 补 `$set` 操作符
- **tests/bootstrap 从未加载** — Pest 3 的 `bootstraps` 键失效，mock `class_alias` 从未生效，改由 `tests/Pest.php` 加载
- RedisCache `Get` 用 `?:` 导致缓存值 `"0"` 误判、RedisClient 无连接超时
- `Id::get()` 恒 false 的空值检查

### 配置变更

- 新增 `ai.agent`（enabled / maxToolRounds / maxFileLines / maxFileBytes）与 `ai.mcp`（webSearch / rag，rag 含 url + db）
- 新增 `storage.uploadFiles`（maxFiles / maxTotalBytes）
- 移除冗余依赖 `chillerlan/php-qrcode`

## 1.5.5 — 2026-07-28

### 改进

- **存储层重构** — MongoDB 和文件系统作为主存储二选一，Redis 仅作可配置缓存层（开关、TTL、大小限制），移除 RedisStorage
- **MongoDB TTL 索引修复** — 索引从无效的 `expires` 字段改为 `created` + `expireAfterSeconds`，滚动删除正确生效
- **FilesystemStorage 完整实现** — 修复 `StorageInterface` 兼容性，存储完整文档（含 token、metadata、source）
- **依赖版本校验** — aternos/codex v4.1.0、codex-minecraft v5.2.0、sherlock v1.1.3 均已最新

### 修复

- **AccessTokenFilter 引号格式** — 修复 pattern 中多余 `"` 导致的匹配失败
- **ClientIdFilter/SessionTokenFilter 模式修复** — 去除 pattern 中多余的引号，确保正确匹配
- **IPv6 地址过滤优化** — 正确处理 `::ffff:127.0.0.1` 等混合格式
- **各类过滤器 PHP 8.5 兼容** — 避免可变长后顾断言，改用简单模式

### 文档

- README / API.md / AGENTS.md 全面更新
- 生成 Postman Collection（23 个请求）
- 新增 CHANGELOG.md 更新日志

### 弃用

- **`/1/` API 端点** — 标记为已弃用，推荐使用 `/v1/` 替代。`/1/log` 上传接口将在过渡期内保持可用

## 1.5.4 — 2026-07-28

### 新功能

- **新增 `/v1/` API 端点** — 全部接口新增 `/v1/` 版本路径，原有 `/1/` 端点保持向后兼容，便于生态逐步迁移
- **敏感数据过滤增强** — 新增 6 个过滤器：UUID、IPv6 短格式、Xbox XUID、会话令牌、客户端/设备 ID、Minecraft 坐标
- **Postman 文档生成** — 基于 OpenAPI 3.1 规范自动生成 Postman Collection，可直接导入测试

### 改进

- **OOP 架构重构** — 代码目录扁平化，文件命名规范化，提升可维护性
- **测试框架搭建** — 引入 PestPHP 测试框架，覆盖过滤器、Handler 和 API 集成测试（47 个测试通过）
- **`safePregReplace` 安全包装** — 所有过滤器统一使用安全正则替换，避免 PHP 8.5 兼容性问题
- **OpenAPI 规范更新至 1.5.4** — 完整描述全部 20+ 端点，标记 `/1/` 为已弃用

### 修复

- **AccessTokenFilter 引号格式** — 修复 pattern 中多余 `"` 导致的匹配失败
- **ClientIdFilter/SessionTokenFilter 模式修复** — 去除 pattern 中多余的引号，确保正确匹配
- **IPv6 地址过滤优化** — 正确处理 `::ffff:127.0.0.1` 等混合格式
- **各类过滤器 PHP 8.5 兼容** — 避免可变长后顾断言，改用简单模式

### 弃用

- **`/1/` API 端点** — 标记为已弃用，推荐使用 `/v1/` 替代。`/1/log` 上传接口将在过渡期内保持可用