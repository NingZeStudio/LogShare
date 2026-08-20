# CRCLASH — LogShare 全面代码审查报告（第四轮 · Hyperf 迁移后）

- **审查日期：** 2026-08-20
- **审查范围：** 项目全部源码（`app/`、`config/`、`bin/`、`tests/`、`core.php`、`pest.php`、`phpstan.neon`、`docker/`、`rag/README.md`），排除 `vendor/`、`.git/`、`runtime/`、`tmp/`、`rag/knowledge/`
- **技术栈：** PHP 8.5 · Hyperf 3.2（Swoole 6.2 常驻 + 协程）· MariaDB（`hyperf/database` + `hyperf/db-connection`）· Redis（可选，自研 `RedisClient`）· SpinYarn PHP 扩展 · SQLite FTS5（RAG）· Aternos Codex · Pest + PHPStan
- **文件统计：** 核心 PHP 文件约 75 个（`app/` 55 + `config/` 12 + `bin/` 1 + `tests/` 20 + 根级 2），代码约 7,900 行
- **背景：** 本轮审查针对 Hyperf 迁移（阶段 0-5）完成后的代码，即「自研 Router + PHP-FPM」→「Hyperf 注解路由 + Swoole 协程 + MariaDB」的迁移结果。阶段 6（部署）、阶段 7（测试迁移）尚未完成。

## 整体评价

迁移骨架已基本成型，核心链路（上传→存储→读取→AI 流式→RAG）已全部切换到 Hyperf/Swoole 运行模型，PHPStan level 5 零错误、104 项非 mock 测试通过。但存在**三处严重问题**（协程状态串扰、Redis 连接、部署脱节）和一批迁移遗留的收尾项，尚未达到「可生产」状态。

---

## 问题清单

### 🔴 严重

#### S1. SSE 输出用 `static $stream` 导致协程串扰 — `app/Client/AIClient.php:16`、`app/Agent/LogAgent.php:23`
- **问题：** 阶段 4 改造时，SSE 输出器被存为**进程级静态属性** `private static ?EventStream $stream`。Hyperf 是协程并发模型，两个 AI 分析请求同时进行时，`startSSE()` 会互相覆盖 `self::$stream`，导致请求 A 的 `emitContent()` 把数据写到请求 B 的连接上。
- **影响：** 并发 AI 分析（`GET /v1/ai/{id}`、`POST /v1/ai/analyse`）时 SSE 输出错乱、串流。这是协程环境下最典型的状态泄漏。
- **修复：** 将 `$stream` 存入 `Hyperf\Context\Context`（协程级上下文，随协程自动隔离），或把 stream 作为参数一路传入 `write()/emit*()`，禁止用 `static` 保存请求级状态。

#### S2. Redis 静态连接在常驻进程/协程下不安全 — `app/Client/RedisClient.php:14`
- **问题：** `protected static ?Redis $connection` 进程级单例，两个隐患：
  1. ext-redis 的 `Redis` 对象是**阻塞 + 非协程安全**的，多个协程共享同一连接并发调用会串扰；
  2. Redis 服务重启后连接失效（broken pipe），但 `static $connection` 仅在 `null` 时才重连，不会自动恢复。
- **影响：** 缓存/限流在协程并发或 Redis 抖动时出错。
- **修复：** 迁移到 `hyperf/redis` 连接池（生产环境，Docker 已有 ext-redis），或至少在 `Connect()` 中加 `ping()` 检测失效重连。Termux 本地无 ext-redis，靠 `class_exists('Redis')` 降级，逻辑可保留为 fallback。

#### S3. Docker 部署配置与架构完全脱节 — `docker/compose.yaml`、`docker/php-fpm.Dockerfile`
- **问题：** 阶段 6 未做。部署配置仍是旧架构：
  - `compose.yaml` 仍起 `php-fpm`（非 Hyperf 常驻进程）、`mongo`（已换 MariaDB）、独立 `rag` 服务（已整合进 Hyperf）；
  - `php-fpm.Dockerfile` 仍 `install-php-extensions mongodb redis`（MongoDB 已删），无 Swoole、无 MariaDB。
- **影响：** 当前 Docker 配置部署即崩溃，与代码完全不符。
- **修复：** 阶段 6 重写：Hyperf 常驻进程镜像（Swoole 6.2 + SpinYarn + pdo_mysql + redis）、`mariadb` 服务替换 `mongo`、删除独立 `rag` 服务（由 Hyperf 8081 承载）、nginx 反代 9501/8081、启动时执行建表 migration + `rag:build`。

### 🟠 一般

#### M1. Config 环境变量覆盖残留 MONGODB_URI 死代码 — `app/Config.php:37-39`
- MongoDB 已替换为 MariaDB，`MONGODB_URI → mongo.url` 覆盖已无对应配置段。应删除；MariaDB 连接参数已由 `config/autoload/databases.php` 的 `env()` 处理，无需在此补。

#### M2. MariaDbStorage::CleanupExpired 未接入定时清理 — `app/Storage/MariaDbStorage.php:145`
- MongoDB 时代靠 TTL 索引自动过期，MariaDB 无此机制。`CleanupExpired()` 已实现但**无任何调用点**；`Log::renew()`（`app/Log.php:333`）只在 `storage === 'f'`（Filesystem）时概率触发清理，MariaDB（`s`）侧完全无清理。
- **修复：** 用 Hyperf Crontab（或 Process）定时调用 `MariaDbStorage::CleanupExpired()`。

#### M3. UploadParser::expandZip 资源泄漏 — `app/UploadParser.php:143-178`
- `for` 循环内 `validateFileName` 失败、声明 size 超限、slots 不足时直接 `return new ApiError(...)`，跳过 `$zip->close()` 与 `@unlink($tmpFile)`，导致临时 zip 文件残留、ZipArchive 句柄未释放。
- **修复：** 将清理逻辑放入 `try-finally`，所有 `return` 路径统一走 finally 清理。

#### M4. AppExceptionHandler 兜底返回纯文本 500 — `app/Exception/Handler/AppExceptionHandler.php:24-27`
- 未捕获异常返回 `Internal Server Error.`（纯文本），与 API 统一 JSON 格式（`{success, error, code}`）不一致，前端解析会失败。
- **修复：** 返回 JSON（如 `{"success":false,"error":"Internal Server Error.","code":500}`），并区分 `app_env` 是否透出错误详情。

#### M5. 静态接口设计未迁移（去 static 未完成）— `app/Cache/CacheInterface.php`、`app/Storage/StorageInterface.php`、`app/Filter/Filter.php`
- Cache/Storage/Filter 仍是 `static` 方法接口 + 静态调用链（`$storage::Put()`、`Filter::filter()`、`RedisCache::Get()`）。PLAN 阶段 2 规划了「去 static → 服务类 + DI」，但未落地。静态方法在 Hyperf 下难以走 DI/连接池，也加剧了 S1/S2 的状态问题。
- **修复：** 阶段 7 一并重构为实例方法 + 依赖注入。

#### M6. AGENTS.md 完全过时 — `AGENTS.md:5`
- 顶部仍写 *"Monolithic PHP 8.4+ app with `index.php` entrypoint, `src/` classes"*，与实际（Hyperf + Swoole 常驻 + `app/` + `bin/hyperf.php` 入口 + MariaDB）完全不符。`index.php`、`src/` 均已删除。全文多处描述旧架构（Commands、Entrypoint、Storage 等）。
- **修复：** 阶段 7 全面重写 AGENTS.md。

#### M7. 部署/文档/配置三处漂移
- `rag/README.md` 仍描述 `build_index.php`、`server.php`、`php -S 127.0.0.1:8081 rag/server.php`（均已删除/迁移为 `App\Command\RagBuildCommand` 与 `App\Controller\RagController`）。
- `phpstan.neon` 的 `paths` 仍含 `rag`（该目录已无 PHP 文件，只剩 `knowledge/` 文档）。
- 根目录残留空 `src/` 目录（`git mv` 后未清理）。

### 🟡 建议

#### R1. CacheEntry 死代码 — `app/Cache/CacheEntry.php`
- 生产代码无任何引用（仅 `CacheEntryTest` 使用）。且其 `new $config['cacheId']()` + 静态调用的写法本身怪异。建议删除或随 M5 一并重构。

#### R2. AbstractController 残留未使用 import — `app/Controller/AbstractController.php:8`
- `use Hyperf\Context\Context;` 在 `apiPrefix()` 改用 `request path` 判断后不再使用。

#### R3. Filter 抛通用 `\Exception` — `app/Filter/LimitBytesFilter.php:13`、`LimitLinesFilter.php:14`
- 超限拒绝抛裸 `\Exception`，语义不清晰。建议抛专用异常（如 `ApiError`），由 `ApiExceptionHandler` 统一转 JSON 400。

#### R4. FilesystemStorage 注释过时 + 写入未校验 — `app/Storage/FilesystemStorage.php:54,125`
- `Renew()` 注释仍写 *"MongoDB TTL reset behaviour"*；
- `Put()` 的 `file_put_contents(...)` 返回值未检查，写入失败时静默返回 `$id`。

#### R5. LogController::delete 丢失 ID 格式校验 — `app/Controller/LogController.php:57`
- 原 `RequestValidator::extractIds` 会校验 ID 格式（返回 400），迁移后直接 `explode(',', $id)`，非法 ID 退化为 404（`Id::decode` 失败 → not found）。行为变化，建议补显式校验。

#### R6. Id 用 `rand()` 生成 — `app/Id.php:53`
- `rand()` 非密码学安全。日志 ID 虽非安全敏感，但建议 `random_int` 以免可预测。

#### R7. Log 每次 load 都完整 analyse（性能）— `app/Log.php:116`
- `load()` 末尾无条件 `$this->analyse()`（Detective 检测 + Codex parse + SpinYarn 反混淆 ~110ms）。但 `GET /raw`、`GET /log/{id}` 只需原文/元信息，并不需要分析结果。每次读取都付出分析 + 反混淆成本。
- **修复：** 将 `analyse()` 改为懒加载（仅在 `InsightsHandler` 等需要 `getAnalysis()` 时触发）。

#### R8. ApiExceptionHandler 冗余判断 — `app/Exception/Handler/ApiExceptionHandler.php:19`
- `handle()` 内 `if ($throwable instanceof ApiError)` 与 `isValid()` 的判断重复（`isValid` 已保证仅 ApiError 进入）。`stopPropagation()` 也可移到 `isValid` 通过后的首行即可。

#### R9. 测试框架未迁移（阶段 7）
- 仍是 Pest + mock。AI/RAG 相关测试（`AIClientTest`、`MCPClientTest`、`LogAgentLoopTest`、`LogAgentTest`）依赖 `php -S` 后台 mock 服务器；`RagServerTest`、`LogAgentRagTest` 已因 `rag/server.php` 删除而移除。建议迁移到 `hyperf/testing`，用协程内测试替代后台进程 mock。

---

## 改进建议（按优先级）

1. **修 S1（协程串扰）**：`$stream` 改 `Context` 或参数传递 —— 这是并发正确性问题，最高优先级。
2. **修 S3（部署脱节）**：重写 Docker（阶段 6），否则无法部署。
3. **修 S2（Redis 连接）**：接入 `hyperf/redis` 连接池或加失效重连。
4. **修 M3（资源泄漏）**：`expandZip` 改 try-finally。
5. **修 M2（TTL 清理）**：接入 Crontab 定时清理 MariaDB。
6. **收尾 M1/M4/M6/M7**：删死代码、统一 JSON 兜底、重写 AGENTS.md、清理漂移。
7. **阶段 7**：测试迁移 + R4-R9 各项。

---

## 正面亮点

- **迁移架构清晰**：`app/` 命名空间统一（全局类 + 子命名空间 → `App\` PSR-4），Controller/Storage/Client/Agent/Rag 分层明确；注解路由 + 依赖注入已落地。
- **双前缀路由优雅**：`/{version:v?1}` 正则段 + `apiPrefix()` 从 path 判断，`/1/` 与 `/v1/` 共享一套 Controller，无重复代码。
- **异常处理链路完整**：`ApiError` → `ApiExceptionHandler`（stopPropagation）→ JSON；兜底 `AppExceptionHandler`；架构测试约束 Controller 不直接访问超全局。
- **存储迁移干净**：`MariaDbStorage` 用关联表（logs/log_files/log_metadata）+ 事务保证原子性，`Get` 的 `includeContent` 投影避免不必要的大字段读取。
- **安全防护到位**：`UploadParser` 路径遍历防护 + zip 炸弹双重校验；Filter 链脱敏（IPv4/IPv6/UUID/XUID/Token 等）；删除走 Bearer Token + `hash_equals`。
- **反混淆优雅降级**：`SpinYarnClient` 扩展缺失时透传，`static $handle` 进程级复用（这正是迁移要解决的 CRCLASH #1）。
- **RAG 整合干净**：`RagController` 承载 MCP JSON-RPC 协议不变，`RagSearch` 检索逻辑（BM25 + LIKE 兜底 + snippet 按句边界）与 `RagBuildCommand` 建索引分工明确。
- **质量门槛持续有效**：PHPStan level 5 零错误、架构测试、104 项测试通过，且迁移过程中多次通过测试发现并修复回归（如 `App\App` 双前缀、内置类缺 `\`、`@throws` 类型）。

---

*第四轮审查：Hyperf 迁移（阶段 0-5）后，三处严重问题（协程状态串扰、Redis 连接、部署脱节）需优先处理；其余为迁移遗留收尾（死代码、文档漂移、测试迁移），建议按阶段 6-7 排期。*
