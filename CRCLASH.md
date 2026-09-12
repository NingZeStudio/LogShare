# LogShare 全仓库 Code Review 报告（第二期）

## 修复记录

- **第一期（2026-08-30）：** 共发现 64 项问题（严重 2、一般 21、建议 41）。除 G10（按架构决策保留现状）外，其余 63 项已全部闭环修复并通过自动化回归验证。
- **第二期（2026-09-11）：** 共发现 22 项问题（严重 5、一般 10、建议 7）。**全量 22 项问题已全部于 2026-09-11 闭环修复**，补充了对应的单测与回归用例，并通过 Pest 全量测试、架构测试、PHPStan level 5 静态分析以及 OpenLiteWaf / OpenLiteStats 的 Lua 全量测试验证。详细修复状态参见各条目。

---

## 概览

- **审查日期：** 2026-09-11
- **审查范围：** 全仓库源代码与基础设施。核心对象包括：
  - `app/`：核心应用层（71 个 PHP 文件，含新增的 `RequestParser.php`、`AiQueueConsumer.php`、`AnalysisQueue.php`、`RedisStreams.php` 等协程微队列与流式处理组件）。
  - `OpenLiteWaf/`：独立 Git 子模块，Nginx/OpenResty 边缘 WAF 规则与防护引擎。
  - `OpenLiteStats/`：独立 Git 子模块，基于 OpenResty 共享内存的站点访问分析插件。
  - `docker/`：容器编排与环境定义（Dockerfile、compose、nginx 配置、数据库与事件 SQL）。
  - `scripts/`：自动化与运维脚本（文档同步、映射下载清洗、AI 调试等 7 个脚本）。
  - `.github/workflows/`：CI/CD 自动化流水线。
  - 根目录配置文件与依赖（`composer.json`、`phpstan.neon`、`core.php`、`bin/hyperf.php`、`.dockerignore` 等）。
- **技术栈识别：** PHP 8.4/8.5 + Hyperf 3.2（Swoole 6.2 协程常驻）；jemalloc 内存分配器；OpenResty 1.27.1.2（Lua 5.1/LuaJIT）；MariaDB 11.8 / Redis 7.4 / SQLite FTS5；Pest 3 + PHPStan level 5。
- **审查维度：** 架构设计、代码逻辑、并发与常驻进程资源管理、系统安全与输入校验、错误处理与一致性、测试覆盖、DevOps 与环境适配。
- **问题汇总：** 本次审查共发现 **22 项问题**，按严重程度分级如下：
  - **严重（Critical）：** 5 项
  - **一般（Medium）：** 10 项
  - **建议（Minor）：** 7 项
- **整体评价：** 项目在经历第一期重构后，核心架构健壮性明显提升。近期引入的 Redis Streams 微队列、Agentic RAG Plus 以及 Gzip 请求体自适应解析机制展现了出色的工程设计水平。但新引入的功能在与边缘 WAF 协同、大并发边界控制、极端错误处理及部署脚本细节上仍存在数个高危漏洞，需立即闭环修复。

---

## 问题清单

### 严重

#### S1. OpenLiteWaf：请求体检查豁免端点遗漏 `/ai/analyse` 与 `/analyse`，日志分析请求被大面积误杀并封禁用户 IP

- **位置：** `OpenLiteWaf/lua/openlitewaf.lua:24-27` (`CONFIG.body_exempt_prefixes`)
- **问题：** OpenLiteWaf 的 `body_exempt_prefixes` 当前仅配置了 `"/v1/log"` 与 `"/1/log"`。而系统新增和支持的日志分析端点 `POST /{version}/ai/analyse` 与 `POST /{version}/analyse`（由 `AIAnalyseController` 及 `AnalyseController` 承载）同样直接接收用户上传的原始日志文本。这些日志普遍包含真实的 SQL 异常堆栈、`/etc/` 路径报错、系统崩溃诊断代码或探测关键字。WAF 在处理这些端点的 POST 请求时，会对请求体执行完整的 SQLi/XSS/Traversal/RCE/Probe 正则扫描，将其判定为恶意攻击，直接返回 403 红色拦截页并将用户客户端 IP 封禁 600 秒（10 分钟）。
- **潜在影响：** 任何尝试使用 AI 分析或规则分析含有报错痕迹日志的正常用户均会被 WAF 误封，导致核心分析功能在生产环境大面积瘫痪。
- **修复建议：** 在 `CONFIG.body_exempt_prefixes` 中补齐所有接收用户日志正文的端点前缀：
  ```lua
  body_exempt_prefixes = {
      "/v1/log",
      "/1/log",
      "/v1/ai/analyse",
      "/1/ai/analyse",
      "/v1/analyse",
      "/1/analyse",
  },
  ```

#### S2. OpenLiteWaf：敏感文件探测规则误伤合法日志附件下载路径，请求 `latest.log` 等文件触发 403 封禁

- **位置：** `OpenLiteWaf/lua/openlitewaf.lua:125` 与 `OpenLiteWaf/lua/openlitewaf.lua:611-615`
- **问题：** WAF 规则表第 125 行为 `{ "probe", [[\.(sql|bak|backup|old|ini|conf|cfg|log|yml|yaml|json|xml)(\?|$|(?![\w.]))]] }`。然而 LogShare 支持多文件归档日志（如 Minecraft 崩溃包），并通过 `GET /{version}/raw/{id}/{filename}` 提供单一附件的原文下载（如 `GET /v1/raw/s123456/latest.log` 或 `GET /v1/raw/s123456/config.yml`）。当请求该接口时，URL 末尾直接匹配 `\.log$` 或 `\.yml$`，命中 probe 规则。
- **潜在影响：** 访问多文件日志中常规日志文件的用户会被判定为恶意探测扫描，直接被阻断并封禁 IP 10 分钟。
- **修复建议：**
  1. 在 `openlitewaf.lua` 的 `_M.access` 中，对匹配 `^/[^/]+/raw/` 的合法文件提取请求做特征放行或排除扩展名探测；
  2. 或将 probe 规则的判定前缀精确限制为非 raw 数据端点。

#### S3. RequestParser：Gzip/Deflate 请求体解压缺少最大展开长度限制，存在高压缩比炸弹 OOM 拒绝服务漏洞

- **位置：** `app/Parser/RequestParser.php:57-82` (`decodeBody`)
- **问题：** `RequestParser` 在 Hyperf 框架接收请求的最前沿自动解压压缩流。代码中直接对原始数据调用裸 `gzdecode($rawBody)`、`gzinflate($rawBody)` 及 `gzuncompress($rawBody)`，未传递最大解压长度参数。虽然下游 `ContentParser` 中存在请求体长度上限校验，但该校验发生在 `RequestParser` 已经完全解压之后。攻击者可构造一个仅百 KB 级别、但展开后达数 GB 的全零字节 Gzip 炸弹请求，瞬间占满 Swoole 工作进程的 `memory_limit`（1GB），触发 Fatal Error 导致 Worker 进程 OOM 崩溃。
- **潜在影响：** 极低的网络开销即可造成 Swoole 常驻工作进程频繁崩溃重启，形成严重的拒绝服务攻击（DoS）。
- **修复建议：** 引入解压上限（可对齐 `storage.maxLength * 2`，例如 20MB）。在 PHP 8 中利用 `gzdecode($rawBody, $maxBytes)` 或在解压时限制输出缓冲长度，一旦超出上限立即终止并返回失败。

#### S4. 运维脚本：`scripts/ai.sh` 进程替换输入重定向语法颠倒，导致脚本语法错误或无限等待

- **位置：** `scripts/ai.sh:81`
- **问题：** `scripts/ai.sh` 第 81 行代码书写为：
  ```bash
  while IFS= read -r line; do < <(curl -sN "${REQ_ARGS[@]}" "$URL")
  ```
  重定向操作符 `< <(...)` 被错误地放置在 `do` 关键字后，而不是 while 循环体结尾的 `done` 之后（`done < <(...)`）。
- **潜在影响：** 脚本执行时循环结构未与 curl 的管道流正确挂接，导致脚本从标准输入阻塞挂起，或者执行出现语法异常，脚本彻底失效。
- **修复建议：** 修正语法，将进程替换移到 `done` 关键字之后：
  ```bash
  while IFS= read -r line; do
      ...
  done < <(curl -sN "${REQ_ARGS[@]}" "$URL")
  ```

#### S5. 容器构建：`.dockerignore` 遗漏本地敏感运维笔记 `LOCAL_DEV_NOTES.md` 与子模块 `OpenLiteStats`，存在凭证泄漏风险

- **位置：** `.dockerignore`
- **问题：** 项目构建生产镜像时采用 `COPY . .`。`.dockerignore` 排除了 `OpenLiteWaf`，但遗漏了另一个子模块 `OpenLiteStats`；更关键的是，遗漏了本地运维恢复笔记 `LOCAL_DEV_NOTES.md`（按 `AGENTS.md` 规范，该文件记录了线上实际服务器 SSH 端口、登录方式、容器部署名等敏感运维细节，永不应提交或发布）。在生产环境执行 `docker compose build` 时，该文件会随代码拷贝直接打入生产镜像层中。
- **潜在影响：** 镜像一旦推送到公开或半公开 Registry，或者镜像包被下载，服务器私有运维配置将彻底泄露。
- **修复建议：** 在 `.dockerignore` 中追加：
  ```dockerignore
  OpenLiteStats
  LOCAL_DEV_NOTES.md
  .env*
  Plan.md
  ```

---

### 一般

#### G1. AnalysisQueue：`enqueue` 注册活跃任务后若写入载荷或 Stream 失败，遗留 activeKey 导致后续同 key 分析永久超时

- **位置：** `app/Ai/AnalysisQueue.php:148-164`
- **问题：** `AnalysisQueue::enqueue` 通过 `setNxEx(self::activeKey($cacheKey), $jobId, $ttl)` 抢占任务槽位。若后续执行 `RedisStreams::set(payloadKey)` 或 `RedisStreams::xAdd(QUEUE_KEY)` 时遭遇网络抖动或 Redis 异常抛错，`activeKey` 未被清理，仍持有长达数分钟的 TTL。后续针对同一条日志或相同内容的分析请求，均会命中该残留的 `activeKey` 并作为 follower 挂接，但由于队列中根本不存在该任务，中继端将持续等待直至客户端超时断开。
- **修复建议：** 在 `enqueue` 关键步骤中增加异常捕获，若写入载荷或 Stream 失败，立即在 catch 中显式删除 `activeKey`。

#### G2. RedisStreams：`xAutoClaim` 游标固定为 `'0-0'`，长耗时任务卡在 pending 时导致后续超时任务回收饥饿

- **位置：** `app/Client/RedisStreams.php:134` 与 `app/Process/AiQueueConsumer.php:150`
- **问题：** `RedisStreams::xAutoClaim` 内部调用时起始游标固定传入 `'0-0'`。当消费者存在若干个正在执行的慢速分析长任务时（任务仍在运行且持有 running 锁），`reclaimLoop` 每次扫描都从头部读取这批条目，检测到锁后放弃并保持 pending。由于游标从未按 Redis 返回的 `next-start` 推进，如果未确认条目超过单次扫描条数（10 条），排在后面的真正因进程崩溃而超时的孤儿任务将永远无法被回收。
- **修复建议：** 维护 `xAutoClaim` 的游标状态，单次迭代按 `next-start` 推进，返回 `'0-0'` 后再重置回起始点。

#### G3. 文档清洗脚本：`scripts/clean_knowledge_docs.php` 编写了 `deleteOrWarn` 错误处理函数，但在主逻辑中完全未使用

- **位置：** `scripts/clean_knowledge_docs.php:58, 65, 72, 81` 与 `:104-112`
- **问题：** 脚本第 104-112 行定义了带 STDERR 输出与成功统计的 `deleteOrWarn(string $path, int &$removed)` 函数，并在注释中明确说明用于防止 `@unlink` 静默吞错。然而在上方的主扫描循环中（第 58、65、72、81 行），代码依然全部直接调用 `@unlink($file->getPathname())` 并无条件执行 `$removed++`，使该安全函数沦为死代码。
- **修复建议：** 将主循环中的 `@unlink(...)` 与 `$removed++` 统一替换为调用 `deleteOrWarn($file->getPathname(), $removed)`。

#### G4. 映射下载：`scripts/download_vanilla_mappings.py` 缺少 SHA-1 完整性校验，网络截断导致损坏映射被永久缓存

- **位置：** `scripts/download_vanilla_mappings.py:40-47`
- **问题：** 脚本从 Mojang version manifest 中拉取了包含 `sha1` 和 `size` 的元数据，但在通过 `urlopen` 下载后未做任何数据校验，直接写入目标文件。若网络波动导致下载被截断（仅拉取到部分字节），损坏的映射会被直接落盘；而后续由于 `os.path.isfile(target) and os.path.getsize(target) > 0` 的跳过判断，损坏的映射文件会被永久跳过，导致 SpinYarn 反混淆扩展在解析该版本时发生崩溃。
- **修复建议：** 写入完成或写入前比对数据的 SHA-1 与声明字节数，校验失败时抛出异常并清理临时分块，避免污染正式映射文件。

#### G5. 编排安全：`docker/compose.yaml` 中数据库和 Redis 密码在容器命令行参数中明文暴露

- **位置：** `docker/compose.yaml:135, 137, 164`
- **问题：** `mariadb-events` 中的 `mariadb-admin ping -p"$$MARIADB_ROOT_PASSWORD"`、`mariadb -p"$$MARIADB_ROOT_PASSWORD"` 以及 `redis` 服务的健康检查 `redis-cli -a "$$REDIS_PASSWORD"` 均直接将敏感密码作为命令行参数传入。在宿主机上执行 `ps -ef` 或通过 `/proc/$PID/cmdline` 可直接查看到明文密码。
- **修复建议：** MariaDB 采用 `MYSQL_PWD="$$MARIADB_ROOT_PASSWORD" mariadb ...` 环境变量方式传递；Redis 采用 `REDISCLI_AUTH="$$REDIS_PASSWORD" redis-cli ...` 避免凭据在进程列表中曝光。

#### G6. 工作流安全：`.github/workflows/release.yaml` 未等待 CI 测试通过即触发 Release 发布

- **位置：** `.github/workflows/release.yaml:14-38`
- **问题：** `release.yaml` 只要检测到 Commit message 带有 `[Build]` 前缀即直接打包 Release 并打 Tag，与 `ci.yaml` 并行触发，且没有配置任何对 CI 检查状态的等待。如果提交的代码存在致命语法错误或单元测试不通过，CI 流程虽然会报错红叉，但 Release 却已经成功创建并对外发布。
- **修复建议：** 在 Release 前置步骤中集成基础验证（运行 Pest 与 PHPStan），或通过 `workflow_run` 监听 CI 成功事件再触发发布。

#### G7. 入口环境时序：`bin/hyperf.php` 在引入 Composer 自动加载之前检查 `APP_ENV`，导致 `.env` 中的生产配置失效

- **位置：** `bin/hyperf.php:6` 与 `core.php:11-28`
- **问题：** `bin/hyperf.php` 第 6 行执行 `$isProduction = getenv('APP_ENV') === 'prod';` 时，尚未引入 `vendor/autoload.php`，`core.php` 尚未执行，`.env` 文件中的环境变量根本尚未被解析加载。因此若用户在 `.env` 中声明 `APP_ENV=prod`，该检查恒为 `false`，导致 `display_errors` 与 `display_startup_errors` 在生产环境下仍然被设置为 `'on'`。
- **修复建议：** 将 `APP_ENV` 的读取与错误显示设置下移到 `require BASE_PATH . '/vendor/autoload.php';` 之后。

#### G8. 协议规范：`RawController` 响应 Content-Type 缺少 `charset=utf-8`

- **位置：** `app/Controller/RawController.php:44, 47`
- **问题：** Raw 纯文本接口直接输出 `$this->respondText($content, 'text/plain')`，缺少字符集声明。当日志包含非 ASCII 字符（如中文报错、多语言日志）时，部分客户端或直接在浏览器查看时，可能按默认编码解析导致中文乱码。
- **修复建议：** 统一显式指定为 `text/plain; charset=utf-8`。

#### G9. 隐私脱敏：`OpenLiteStats` 中 Referer Host 提取正则未正确剥离 HTTP Basic Auth 认证信息

- **位置：** `OpenLiteStats/lua/openlitestats.lua:67`
- **问题：** 代码使用 `host = host:match("^(.*@?)") or host` 试图去除来路 Referer 中的用户信息。但由于 `.*` 是贪婪匹配且 `@?` 是可选的，该正则表达式恒等匹配并返回整个字符串。若用户来路 URL 带有 `http://username:password@example.com/`，其账号与密码将被完整记录并展示在公开的 Top Referer 统计面板中。
- **修复建议：** 将提取逻辑修正为 `host = host:gsub("^[^@]+@", "")`。

#### G10. 死配置：`docker/nginx/default.conf` 中遗留未实现的临时诊断端点 `/waf-diag`

- **位置：** `docker/nginx/default.conf:70-72`
- **问题：** Nginx 配置中保留了 `location = /waf-diag { content_by_lua_file /usr/local/openresty/nginx/lua/diag.lua; }`。但在代码仓库中 `diag.lua` 并不存在。任何对该端点的公网请求均会触发 Nginx 内部错误日志并返回 500；若未来被误放文件，则可能泄露内部 WAF 状态。
- **修复建议：** 彻底移除该 location 配置块。

---

### 建议

#### M1. FilesystemStorage：`Renew` 与 `Delete` 并发下的孤儿 `.meta.json` 防御
- **位置：** `app/Storage/FilesystemStorage.php:128-133`
- **描述：** `Renew` 先检查主文件是否存在，随后写入 `.meta.json`。若在检查与写入之间主文件被 `Delete` 删除，将遗留一个孤儿 `.meta.json` 文件。建议在写入 `.meta.json` 时复核主文件存在性，或使用文件锁保持原子性。

#### M2. UploadParser：文件名校验增加不可见控制字符过滤
- **位置：** `app/UploadParser.php:24-44`
- **描述：** `validateFileName` 虽已防范路径穿越与超长文件名，但未过滤 `\r`、`\n` 等控制字符。建议加入正则过滤，防止多文件下载或导出响应头中出现 HTTP 响应拆分风险。

#### M3. Dockerfile：`LD_PRELOAD` 路径硬编码为 `x86_64`，缺乏多架构构建兼容性
- **位置：** `docker/hyperf.Dockerfile:38`
- **描述：** 容器环境变量直接硬编码为 `/usr/lib/x86_64-linux-gnu/libjemalloc.so.2`。若在 ARM64 架构下构建该镜像，将因找不到动态库而导致预加载报错。建议采用通配符软链或在启动脚本中动态判定。

#### M4. 运维脚本：临时目录创建增加 Termux 适配
- **位置：** `scripts/download_modloader_docs.sh:15` 与 `download_server_docs.sh:23`
- **描述：** 脚本直接调用 `mktemp -d`。在部分 Termux 环境下若未设置 `TMPDIR`，可能尝试写入只读的 `/tmp` 导致失败。建议优先采用 `TMP_DIR="${TMPDIR:-/tmp}/..."` 或项目本地临时目录。

#### M5. 运维脚本：`download_server_docs.sh` 网络请求缺少错误拦截标志
- **位置：** `scripts/download_server_docs.sh:31`
- **描述：** `fetch_tarball` 中使用 `curl -sL`，缺少 `-f`（`--fail`）。遇到 GitHub 404 或限流时，会将返回的 HTML 报错页面写入 `.tgz` 并继续执行解压，产生误导性的解压报错。建议补充 `-f` 选项。

#### M6. 编排优化：`mariadb-events` 一次性容器应避免无限休眠
- **位置：** `docker/compose.yaml:138`
- **描述：** 容器在执行完事件注册 SQL 后使用 `while :; do sleep 86400; done` 保持长跑。建议执行完毕后直接正常退出，并保持 `restart: "no"`。

#### M7. OpenLiteWaf：IPv6 `::1` 等压缩地址脱敏展示优化
- **位置：** `OpenLiteWaf/lua/openlitewaf.lua:326` 与 `OpenLiteStats/lua/openlitestats.lua:48-55`
- **描述：** 在处理以 `::` 形式简写的 IPv6 地址时，简单按冒号切分可能只截取出单个组。建议完善 IPv6 的掩码规则，确保格式统一。

---

## 架构与设计改进建议

1. **统一微队列在极端故障下的自愈机制**
   当前 `AnalysisQueue` 的生产者与消费者解耦设计非常优秀，但仍需加强对 Redis 偶发断连、主从切换等网络分区的自愈容错能力。建议对关键写入操作引入重试熔断与背压保护。

2. **边缘 WAF 与应用层契约的自动化对齐**
   WAF 作为独立子模块运行在 Nginx 接入层，当应用层新增控制器路由（如 `/v1/ai/analyse`、`/v1/raw/{id}/{filename}`）时，WAF 的白名单与规则表容易产生漂移，导致误报。建议在 CI 中增加端到端测试，自动化模拟调用全量对外 API 路由，确保所有合法接口均不会被 WAF 误杀。

3. **CI/CD 流水线流水化卡点**
   建议将 `release.yaml` 与 `ci.yaml` 深度绑定，仅允许在全量单元测试、架构测试与静态分析全部通过后才允许生成 Release 与 Docker 镜像发布。

---

## 正面亮点与最佳实践

1. **常驻内存与垃圾回收治理机制**
   针对 Swoole 常驻进程中 PHP 内存池与连接池不归还导致的 RSS 缓慢攀升问题，引入了基于处理量与空闲时长的自回收机制（`AiQueueConsumer::maybeRecycle`），优雅地将空闲内存重置，彻底解决了常驻服务长期运行的内存膨胀顽疾。

2. **精细的微队列事件流设计**
   微队列将庞大的日志正文剥离为短 TTL 的 gzip 载荷，Stream 仅传递极轻量的消息 ID；同时实现了精准的 `activeKey` 去重与合并中继机制，既避免了重复分析，又大幅降低了 Redis 内存占用与带宽消耗。

3. **双重防御与时序安全比对**
   Token 校验严格遵循恒时哈希比较（`hash_equals`），对历史明文与新哈希兼容处理；文件存储采用原子文件创建（`fopen 'x'`）与分布式锁，从根本上杜绝了并发冲突与 TOCTOU 竞态。

4. **规范的错误收敛与异常隔离**
   在各个网络客户端（AIClient、MCPClient、SemanticClient）中均严格落地了 `CURLOPT_FORBID_REUSE => true` 与显式 handle 销毁（`$ch = null`），并在上层统一了异常转换，绝不向下游泄露原始敏感错误，符合高标准的安全设计规范。
