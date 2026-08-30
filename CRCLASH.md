# LogShare 全仓库 Code Review 报告

## 修复记录（2026-08-30，当日闭环）

除 **G10**（SSE 内部错误消息原文下发，按决策**保留现状**）外，本报告全部 63 条发现（严重 2、一般 20、建议 41）已修复，并通过验证：`pest` 全量 146 通过 / 10 跳过（含新增 S1 回归用例）、架构测试 7 通过、PHPStan level 5 零错误、LiteWAF 两套回归测试全部通过（规则解析修复后 63 条规则 / 75 样本）。要点：

- **S1**：`FilesystemStorage::CleanupExpired` 改用与 `Get()` 一致的 `readCreated()` 时间源（meta 优先），续期日志不再被误删；新增「Renew 后不清理」回归测试；`Delete` 同步清理 `.meta.json`（G7）。
- **S2**：`.dockerignore` 补 `.env`、`.github`、`docs/`、根目录文档与 openapi/postman。
- **G1**：`RateLimitMiddleware` 注释改为与实现一致的 fail-closed，注明未挂载状态（README 同步为准确口径：默认 600/60s 且未注册）。
- **G2**：`IPv6ShortFilter` 改用 `safePregReplaceCallback`；`Filter` 的两个 safe 包装在降级时写入 error 日志，fail-open 可观测。
- **G3**：`ContentParser` urlencoded 分支补 `is_string` 校验，`content[]=` 返回 400。
- **G4**：删除流程改为「主存储成功 → 删缓存（失败重试一次）→ 仍失败写墓碑标记（TTL 与缓存一致）」，`load()` 命中墓碑跳过缓存直接回源。
- **G5**：`$analysisJsonCache` 增加单条 4MB / 总量 32MB 字节预算，淘汰改 LRU 语义。
- **G6**：两个存储后端的 `CleanupExpired` 均分批执行（每轮 500 条/文件），限制请求路径内的清理工作量。
- **G8**：`read_log_file` 仅在完整读取（覆盖全文件）时置已读标记，offset/行区间续读不再被防重复拦截误杀。
- **G9**：分析缓存锁改在 `finally` 中无条件释放；`acquireCacheLock` 简化为 bool 返回，删除无用锁 token。
- **G11**：`RagController::getSearch()` 按 `filemtime` 检测索引替换，`rag:build` 后 Web 进程自动用上新索引。
- **G12**：`rag:build` 失败返回 `Command::FAILURE`。
- **G13**：`SseWriter` 直接持有 `Writable` 连接检查写返回值，客户端断开抛 `ClientDisconnectedException`；`LogAgent` / `AIClient` 捕获后立即中止（不再 emitError），AIClient 对断连异常原样传播不参与换 key 重试。
- **G14**：regex 测试解析器支持等号长括号 `[==[...]==]`（组号同步调整），uname 规则纳入回归（63 条规则 / 74→75 样本）。
- **G15**：扫描器 UA 规则的 `httpx` 加 `(?<!python-)` 负向断言，`python-httpx` 客户端不再被误封（补反样本）。
- **G16**：body 扫描不再依赖 `Content-Length`（chunked 请求始终扫描），仅对 CL > 2MB 的请求快速跳过。
- **G17**：`litewaf.lua` 暴露 `_M._rules_compiled()` 测试访问器，logic 测试改断言真实编译缓存时序（init 后 nil、首个非豁免请求后非 nil）。
- **G18**：删除 `docker/mclogs.conf`（9300 死配置）；`openapi.yaml` 本地 server 与 `ai.sh` 默认地址同步改为 9501。
- **G19**：`release.yaml` 的 commit message / event name 改经环境变量中转，消除 Actions 脚本注入。
- **G20/G21**：`ai.sh` 错误事件改为对 JSON 载荷判错（修复恒 false 条件）；管道 while 改进程替换，`thinking_buf` 状态可传播回父 shell。
- **G22-G25**：`download_mappings.sh` 改 argv 传参消除 python 注入、完整性校验前移（损坏映射重新下载）；`download_server_docs.sh` 排除正则修正锚定；`download_vanilla_mappings.py` 修复异常路径二次 close；`clean_knowledge_docs.php` 删除失败输出 STDERR 且不计数。
- **建议级 41 条**全部落地：ID 唯一性 TOCTOU（MariaDB 冲突重试 / Filesystem `fopen 'x'` 原子占位）、metadata 类型两端对齐（读取端 JSON 还原）、UploadParser（文件名 512 上限、tmp 0700、移除双重转义、zip 风险注释）、`MetadataEntry` 字符安全截断、`UuidFilter` 精准预检、SessionToken/AccessToken 无引号形态补漏、`Config` fail-fast + 密码占位符清洗、`ApiResponse` 保留键冲突防御、CORS `Max-Age`/`Vary`、`files[0]` 复用过滤结果与 `exists` 修正、`PatternWithReplacement` 构造转调、`CacheInterface::Set` 签名、Limit 过滤器 `@throws` 契约、语义缓存 O(n²) 淘汰、向量分批扫描 + prepare 外提、SSE 帧 `JSON_INVALID_UTF8_SUBSTITUTE`、topics 进程级缓存、SSRF 信任边界注释、RedisClient database 字符串兼容、SpinYarn fail-open 注释、`AIClient` goto 改内层循环、`AIAnalyseController` 脱敏差异写入 API.md、probe 扩展名规则边界、subject 顺序注释修正、incr/expire 窗口注释、nginx（worker_connections 4096、resolver 动态解析、HSTS/http2/TLS 钉版/会话缓存）、compose（mariadb 11.8 / redis 7.4 pin、统一日志上限）、Dockerfile（SpinYarn 源码清理、rag:build 降级、ADD 校验和说明）、`core.php` dotenv 限制声明、README mariadb-events 措辞。

G10 保留说明：`LogAgent::analyze` 与 `AIClient::streamChat` 的 SSE error 帧仍携带原始异常消息（已通过 `JSON_INVALID_UTF8_SUBSTITUTE` 保证帧完整性），如需对外脱敏另行处理。

---

## 概览

- **审查日期：** 2026-08-30
- **审查范围：** 全仓库源代码。核心对象为 `app/`（62 个 PHP 文件，约 7,159 行）、`LiteWAF/`（3 个 Lua 模块约 1,119 行 + 2 个测试）、`docker/`（Dockerfile、compose、nginx/mariadb 配置）、`scripts/`（7 个运维脚本）、`.github/workflows/`（CI）、`config/`、`core.php`、`bin/hyperf.php`。排除 `vendor/`、`runtime/`、`rag/index.db` 等依赖与生成物（已核实无意外提交的生成物）。
- **技术栈识别：** PHP 8.4 + Hyperf 3.2（Swoole 6.2 协程常驻）；Rust 构建的 SpinYarn FFI 扩展（PHP C-API）；OpenResty 1.27.1.2（Lua 5.1/LuaJIT）承载 LiteWAF；MariaDB 11 / Redis 7 / SQLite FTS5；测试为 Pest 3 + PHPStan level 5；CI 为 GitHub Actions（PHP 8.4/8.5 矩阵）。
- **审查方式：** 按存储链路、AI/RAG 链路、WAF 与部署三个分区并行精读全部源文件，共 93 个文件；所有"严重"级与关键"一般"级发现均经人工二次核验或实机验证（包括实际运行回归测试与解析器比对）。
- **测试基线：** `pest tests/Unit` 127 通过 / 8 跳过（跳过项为需要本地 MariaDB 的集成测试），架构测试 7 通过；`LiteWAF` 两套回归测试通过（但见 G1：其中一条规则实际未被解析进测试）。
- **整体评价：** 项目整体质量良好——协程级状态隔离、token 哈希存储、ZIP 分层防护、原子写库、CI 分层冒烟等关键设计都有明确意识且实现到位。本次共发现 **64 条问题：严重 2、一般 21、建议 41**。两条严重问题（文件存储续期后仍被过期清理删除、构建镜像未排除 `.env` 导致密钥进入镜像层）修复成本都很低，建议立即处理。无第三方依赖供应链问题。

---

## 问题清单

### 严重

#### S1. FilesystemStorage：`Renew` 续期后日志仍会被 `CleanupExpired` 删除（数据丢失）

- **位置：** `app/Storage/FilesystemStorage.php:113-135`（Renew）、`:158-173`（CleanupExpired）、`:90-100`（readCreated）
- **问题：** `Put` 把 `created` 同时写进主文档 JSON（第 28 行）与 `.meta.json`（第 56 行）；`Renew` 只更新 `.meta.json`（第 132-134 行）；而 `CleanupExpired` 判定过期时**只读主文档内嵌的 `created`**（第 163-167 行），不读 meta。`Get`/`readCreated` 却是 meta 优先。结果是：用户续期后，读取接口返回续期后的过期时间，但过期清理仍按原始 `created` 判定并删除主日志文件——续期在文件系统后端完全无效。MariaDB 后端的 `Renew` 直接 update `created` 列，行为正确，两条后端语义割裂。
- **修复建议：** `CleanupExpired` 统一改用 `readCreated()`（meta 优先、主文档兜底、mtime 最后）；补回归测试：Put → Renew → CleanupExpired 断言文件仍存在。

#### S2. 构建镜像未排除 `.env`，生产密钥会进入镜像层

- **位置：** `.dockerignore`（全文）与 `docker/hyperf.Dockerfile:46`（`COPY . .`）
- **问题：** `.dockerignore` 排除了 `Config.inc.php` 但**没有 `.env`**。生产部署机上 `.env` 必然存在（compose 以 `../.env:/app/.env:ro` 挂载，含 `AI_API_KEYS`、`AI_RAG_PROVIDERS` 内嵌密钥、数据库密码），执行 `docker compose build` 时随 `COPY . .` 进入镜像层；运行时 ro 挂载只是遮蔽，`docker history` / 解包层均可提取。镜像一旦推送 Registry 即泄露。CI 不受影响（`.env` 在构建之后才由 `.env.example` 复制，`ci.yaml:266`）。
- **修复建议：** `.dockerignore` 加入 `.env`（一行修复，建议顺带加入 `.github`、`docs/`、`API.md` 等非运行时文件）。

### 一般

#### 存储与数据链路

- **G1. `RateLimitMiddleware` 未注册，且注释与实现矛盾。** `config/autoload/middlewares.php` 只注册了 CORS；类注释（`RateLimitMiddleware.php:16`）声称 "Fails open when Redis is unavailable"，实现却是 Redis 故障时 `throw ApiError(503)`（fail-closed，第 66-69 行）。README 此前声称的"36000 次/60 秒"与实际默认值 600/60 亦不符（README 已随本报告修正）。若未来有人按错误注释把它注册回生产，Redis 抖动会以 503 打挂全站。建议三选一：删除该类；或修正注释并在 README 标注"未挂载"；或真正启用并把 Redis 故障改为放行 + 警告日志。
- **G2. `IPv6ShortFilter` 正则失败返回 null → TypeError 500。** `app/Filter/IPv6ShortFilter.php:50-58` 用裸 `preg_replace_callback`（未走 `Filter::safePregReplaceCallback`），回溯超限（长日志高概率）时返回 null，与 `filter(): string` 签名冲突直接 500。其余过滤器用了 safe 包装，但失败时静默返回原文——对隐私过滤器是"失败开放"：整篇 IP/UUID 静默不打码入库且无日志。建议：该过滤器改用 safe 包装；safe 包装降级时补 `Syslog::error` 使 fail-open 可观测；长内容考虑分段正则。
- **G3. form 提交 `content[]=x` 触发 500（应为 400）。** `ContentParser.php:83-95` urlencoded 分支不校验 `content` 是否为字符串即返回，`LogController::create`（第 23-37 行）把数组当结构化数据处理后以 null 调 `Log::put(string)` → TypeError。JSON 分支有 `is_string` 校验，urlencoded 缺失。补 `is_string` 检查返回 400。
- **G4. 删除成功但缓存删除失败时，已删日志在 TTL 内仍可读。** `Log.php:453-464` 缓存删除失败仅记日志；且 `LogController::delete` 每次先 `new Log()` 触发 `load()`，会把内容写入缓存，随后删除失败时残留窗口长达 `cache.ttl`。建议删除流程先删缓存（失败重试或墓碑标记）再删主存储。
- **G5. 进程级 `$analysisJsonCache` 只限条数不限字节。** `Log.php:136-166`，32 条 × 每条可达数十 MB，Swoole 常驻内存只增不还；淘汰为 FIFO 而非 LRU。建议加单条字节上限与总预算。
- **G6. 1% 概率在用户请求内同步执行过期清理，无分批。** `Log.php:381-391` 触发；MariaDB 路径全量 `pluck` 后三表无分批 `whereIn` 删除，Filesystem 路径全目录逐文件全文 `file_get_contents`（`FilesystemStorage.php:158-173`）。清理积压时单个用户请求可被拖住数十秒。建议移入定时进程，或分批执行。
- **G7. `FilesystemStorage::Delete` 不删 `.meta.json`。** `app/Storage/FilesystemStorage.php:178-189` 只 `unlink` 主文件，残留的 meta 文件被 `CleanupExpired` 当独立文档遍历，且 `Renew` 后 meta 寿命长于主文件。删除成功后同步删除 meta。

#### AI 与 RAG 链路

- **G8. `read_log_file` 的续读机制与防重复拦截自相矛盾，大文件永远读不全。** `app/Agent/LogAgent.php:516-518` 的去重检查先于 offset/行区间处理，而首次**部分**读取即标记 `readFiles=true`（第 529、549 行），随后按 `tail` 提示用 `offset` 续读会被"文件已读取"提示拦截——与系统提示词（第 340 行）和工具描述（第 231 行）宣传的续读能力直接矛盾。建议仅当本次读取覆盖全文件时才置已读标记。
- **G9. 分析缓存锁在异常/空结果路径不释放。** `LogAgent.php:53`（获取）与 `152-155`（仅在成功且非空时释放）；`streamChat` 抛异常进入 catch（158 行）后锁只能等 120 秒 TTL，期间 `waitForCache` 仅轮询 1 秒即放弃，互斥失效、并发重复分析。建议在 `finally` 中释放。
- **G10. 内部异常消息原样流入公网 SSE。** `LogAgent.php:160` 与 `AIClient.php:338` 将 `$e->getMessage()` 直接发给客户端，链路可携带上游网关响应片段、上游 URL、Redis 主机端口、RAG 磁盘路径。`RagController.php:176-181` 已有正确的通用文案处理，SSE 路径未对齐。建议 SSE error 帧统一固定文案，细节仅进 Syslog。
- **G11. `rag:build` 重建索引后 Web 进程永远使用旧索引。** `RagController.php:23-38` 进程级静态单例持有指向旧 inode 的 PDO 连接（`rename` 原子替换不失效旧 fd），直到重启 Swoole。建议 `getSearch()` 低频比对 `fileinode`/`filemtime` 自动重建，或在文档明确"构建后必须重启"。
- **G12. `RagBuildCommand` 构建失败退出码为 0。** `app/Command/RagBuildCommand.php:42-45` catch 后 `return`（void ≡ 0），CI 与脚本无法感知构建失败。返回 `Command::FAILURE`。
- **G13. 客户端断开后 Agent 循环不中止。** `SseWriter.php:73-78` 依赖的 `EventStream::write`（hyperf/engine）不检查底层写返回值，连接断开后 `LogAgent::analyze` 会继续跑满全部工具轮次与 MCP/嵌入调用，产物全部丢弃，浪费上游配额。建议 `SseWriter` 检查写结果，断开抛专用异常终止循环。

#### LiteWAF 与边缘

- **G14. 规则回归测试的解析器不兼容 `[==[ ]==]` 长括号，一条规则零覆盖（实测证实）。** `LiteWAF/tests/litewaf_regex_test.php:11` 的解析正则只支持 `[[...]]`；`litewaf.lua:99` 的 `uname` 规则用 `[==[...]==]` 书写，实测测试输出"已加载规则数：62"而规则表实际 63 条，差的正是这条（php 逐条比对确认）。`LiteWAF/README.md` 自己推荐的写法会静默脱离回归。解析正则改为支持可选等号（如 `/\[(=*)\[(.*?)\]\2\]/s`）并补 uname 正样本。
- **G15. probe UA 规则误伤 `python-httpx` 等常规客户端。** `litewaf.lua:128` 的 `\bhttpx\b` 命中 Python 生态常用 HTTP 库 `python-httpx` 的默认 UA——API 服务的正常脚本用户会被 403 并封禁 600 秒。改为 `(?<!python-)httpx` 或移出规则。
- **G16. body 扫描依赖 `Content-Length`，chunked 请求整体绕过。** `litewaf.lua:353-354` 无 `Content-Length` 时 `cl == 0` 直接跳过扫描，非豁免路径上的 payload 可经 chunked body 免检送达。改为先 `read_body()` 后按实际读取量控制扫描上限。
- **G17. logic 测试存在恒真断言。** `LiteWAF/tests/litewaf_logic_test.lua:189` 断言 `waf._compiled == nil`，而编译缓存是模块内局部变量从未挂到 `_M`，断言无条件通过，声称覆盖的"惰性编译"实际什么都没测。补测试访问器或删除。
- **G18. `docker/mclogs.conf` 的 9300 端口 server 为死配置，且一旦暴露将绕过全部防护。** `docker/mclogs.conf:2` 的 server 无 TLS、无 `access_by_lua_file`、无 `limit_req`；当前因 compose 未发布 9300 而不可达，但常驻生产配置中。删除该文件或显式注释用途并补齐防护。

#### 部署与 CI

- **G19. `release.yaml` 存在 GitHub Actions 脚本注入。** `.github/workflows/release.yaml:24` 将 `${{ github.event.head_commit.message }}` 直接插值进 `run:` shell。构造特定 commit message 推送到 main 即可在 runner 执行任意命令（该 job 有 `contents: write`）。改为环境变量中转：`env: HEAD_MSG: ${{ ... }}` + `run: [[ "$HEAD_MSG" == ... ]]`。
- **G20. `scripts/ai.sh` SSE error 事件检测条件恒为 false。** `scripts/ai.sh:93` `[[ "$line" == "data: " && ... ]]` 只有载荷为空才成立，真实 error 帧（`data: {"error":...}`）永远不进该分支，错误被静默吞掉。改为对 `$JSON` 本身判错。
- **G21. `scripts/ai.sh` 管道 while 在子 shell 执行，跨迭代状态丢失。** `scripts/ai.sh:78-144`，`thinking_buf` 的累积与第 144 行"刷新残留缓冲"永远读到空值（死代码）。改用进程替换 `done < <(curl ...)`。

#### 脚本

- **G22. `scripts/download_mappings.sh:41-49` 将 shell 变量直接拼进 `python3 -c` 代码**，版本号含单引号时可破坏语法乃至注入（本地运行、风险有限）。改为 `python3 - args <<'EOF'` 传参。
- **G23. `scripts/download_mappings.sh` 已存在文件的完整性检查永远不会触发。** `process_version`（117-120 行）对已存在文件仅 `[[ -f ]]` 即跳过，`download_one` 的 `gzip -t` 校验（58 行）不经此路径，损坏的映射文件被永久跳过。校验前移。
- **G24. `scripts/download_server_docs.sh:81` 排除正则锚定失效。** `find` 输出带 `./` 前缀，`^README`、`^LICENSE` 永不匹配（实际靠下游清洗脚本兜底）。修正模式。
- **G25. `scripts/download_vanilla_mappings.py:46-47` 异常路径二次 `os.close(fd)`** 抛 EBADF，把原始网络错误替换成误导信息。仅当未 fdopen 时关闭。

### 建议

**存储与数据链路**

- `MariaDbStorage.php:20-22` ID 唯一性检查存在 TOCTOU（并发碰撞一个 500、一个静默覆盖，两后端失败模式不一致）；捕获主键冲突重试 / 用 `O_EXCL` 语义。
- `MariaDbStorage.php:48-52` metadata value 经 json_encode 后类型漂移（bool→"true"），与 Filesystem 后端读回类型不一致；统一约定或带类型标记。
- `UploadParser.php` 文件名无长度上限（DB 列 VARCHAR(512)，超长在 MariaDB 路径 500 而文件路径正常）；tmp 目录 0777 建议 0700；JSON 错误消息误用 `htmlspecialchars` 产生双重转义；zip 声明 size 篡改时 `getFromIndex` 实际行为待验证（已有解压后复检兜底）。
- `MetadataEntry.php:104-121` `substr` 按字节截断可产生非法 UTF-8 / 非法 JSON；改 `mb_strcut`。
- `UuidFilter.php:36-39` 快速预检 `[0-9a-fA-F]{8}` 几乎恒真，失去跳过意义。
- `SessionTokenFilter.php:16-22`、`AccessTokenFilter.php:15-16` 规则强制要求闭引号，`accessToken: xxxxx`（无引号）形态漏报不打码。
- `Config.php:24-30` 配置文件返回非数组时每次 `Get` 重读文件（`$loaded` 永不置位）；example 中 `'${REDIS_PASSWORD}'` 占位符在非 Docker 部署会被当字面量用于 AUTH（待验证场景）。
- `ApiResponse.php:14-31` 数据平铺存在键冲突（`success`/`message` 可被覆盖）与数字键问题。
- `CorsMiddleware.php:28-34` 预检缺 `Access-Control-Max-Age` 与 `Vary: Origin`。
- `Log.php:319-340`（配合 LogController）`files[0]` 与主 content 双重过滤、双份存储（约 2 倍存储放大）；`Put` 返回 null 时仍置 `exists = true`。
- `PatternWithReplacement` 子类重复提升父类同名属性；`CacheInterface::Set` 缺返回类型。
- `LimitLinesFilter` / `LimitBytesFilter` 在过滤链契约内抛 `ApiError`，超出 `filter(): string` 隐含契约。

**AI 与 RAG 链路**

- `LogAgent.php:730-743` 锁 token 生成后未使用，释放不校验归属（锁过期后可能误删下一任持有者）。
- `RagController.php:57` Bearer token 非恒时比较（项目在 `Token::matches` 已有 `hash_equals` 先例）。
- `RagSearch.php:448` 语义缓存淘汰每条重新 `serialize` 整个缓存，O(n²)。
- `RagSearch.php:508-549` 向量全表载入内存做余弦 + 循环内重复 `prepare`；当前规模可接受，扩容前分批。
- `LogAgent.php:604-632` SSE 帧 `json_encode` 未加 `JSON_INVALID_UTF8_SUBSTITUTE`，非法 UTF-8 时帧退化为空数据。
- `LogAgent.php:299-307` 每次分析同步预取 topics（完整 MCP 握手），可按进程级短 TTL 缓存。
- `MCPClient.php:35-41`、`SemanticClient.php:40` SSRF 校验不覆盖域名解析结果与 DNS rebinding（URL 均来自服务端配置，实际风险低，建议注释声明信任边界）。
- `RedisClient.php:103-106` `database` 为数字字符串时静默不 select。
- `SpinYarnClient.php:78-94` init 失败后进程内永不重试（fail-open 可接受但未注释说明）。
- `AIClient.php:57、279` `goto retry` 可读性差。
- `AIAnalyseController.php:20-50` `POST /ai/analyse` 直传内容不经脱敏链即发往第三方网关（与已存储日志路径行为不一致），建议在 API.md 明示该差异（设计确认项）。

**LiteWAF 与边缘**

- `litewaf.lua:446-449` 注释声称的 subject 匹配顺序（decoded URI 第二位）与实际（raw→uri→UA→decoded→body）不符，影响计数归属。
- `litewaf.lua:433-435、268-269` `incr(init)` 与 `expire` 之间存在无 TTL 键泄漏窗口（worker 在两步间退出时）。
- `litewaf.lua:121` probe 扩展名规则以 `(\?|$)` 收尾会匹配 body/解码 subject 中以 `.log`/`.conf` 结尾的正常表单值。
- `LiteWAF/nginx/nginx.conf:13` `worker_connections 1024` 对 SSE 长连接场景偏小。
- `docker/nginx/default.conf:57` `proxy_pass` 为启动时静态解析，Hyperf 重启 IP 变化后持续 502，建议 `resolver 127.0.0.11` + 变量。
- 443 server 可选加固：HSTS、`ssl_session_cache`、http2、显式 `ssl_protocols`。

**部署与 CI**

- `compose.yaml:85、126` `mariadb:11`、`redis:7-alpine` 为浮动 tag，与全项目严格 pin 风格不一致。
- Compose 各服务未配置 `logging` 上限，长期运行日志可占满磁盘。
- `hyperf.Dockerfile:17` `ADD` 远程安装脚本无校验和（BuildKit 支持 `--checksum=sha256:...`）。
- `hyperf.Dockerfile:30` SpinYarn Rust 源码与全部构建产物进入最终生产镜像。
- `hyperf.Dockerfile:52` 启动链 `rag:build && start` 在远程 embedding 故障时会 crash-loop，建议降级或拆分 init 容器。
- `core.php:15-22` 简易 dotenv 不支持行内注释。
- `README.md`（已随本报告修正限流描述）中"mariadb-events.sql 生成文件不提交到 Git"与仓库实际（`docker/mariadb-events.sql` 已入库且被 compose 挂载）表述含混，应改为"仓库内为默认值，修改 TTL 后重新生成、按需提交"。

---

## 改进建议（按优先级）

**P0（立即，修复成本极低）**

1. `.dockerignore` 加入 `.env`（S2，一行）。
2. `FilesystemStorage::CleanupExpired` 改用 `readCreated()`（S1，数行），并补"Put → Renew → 清理后仍存在"回归测试。
3. `release.yaml` commit message 改环境变量中转（G19）。

**P1（短期，影响正确性与真实用户）**

4. `httpx` UA 规则加 `(?<!python-)`（G15）——直接影响 API 正常用户。
5. body 扫描不依赖 `Content-Length`（G16）。
6. regex 测试解析器支持 `[==[` 并补 uname 样本（G14）。
7. `LogAgent`：续读仅在覆盖全文件时置已读（G8）、锁在 `finally` 释放（G9）、SSE error 帧固定文案（G10）。
8. `IPv6ShortFilter` 改 safe 包装；safe 降级补日志（G2）。
9. `ContentParser` urlencoded 分支补 `is_string` 校验（G3）。
10. 删除流程先删缓存（G4）。
11. `ai.sh` 修复 error 检测与子 shell 状态（G20、G21）。

**P2（中期，架构与运维）**

12. 过期清理移出请求路径（Hyperf process / crontab），至少分批（G6）。
13. 进程级缓存补字节预算（G5）；RAG 语义缓存淘汰改增量字节计数。
14. `SseWriter` 检测断连并中止 Agent 循环（G13）。
15. `rag:build` 后索引自动失效或文档明确需重启（G11）；`RagBuildCommand` 返回失败码（G12）。
16. 澄清 `RateLimitMiddleware` 去留（G1）；清理 `mclogs.conf` 死配置（G18）。
17. 文档一致性：mariadb-events 措辞、`AIAnalyseController` 脱敏差异写入 API.md。

**P3（择机）**

18. 其余"建议"级条目按模块顺手处理；重点是隐私过滤器漏报（SessionToken 无引号形态）与 zip 声明 size 信任点的行为验证。

---

## 正面亮点

1. **Token 安全存储与比对**（`app/Data/Token.php:26-33`）：存储层只落 SHA-256 哈希，校验先比对哈希再兼容存量明文，两次均用 `hash_equals`，无时序侧信道。
2. **协程级连接隔离成体系**（`app/Client/RedisClient.php:52-63`、`app/Sse/SseWriter.php:90-106`）：明确识别"常驻进程共享连接会被并发协程交错读写"的 Swoole 关键风险，连接与流句柄均存于协程级 `Context`，注释解释了为什么。
3. **SSE 异常边界自觉**（`LogAgent.php:42-44`）：try 边界紧贴 `begin()`，流开始后任何异常都以流内 error 收尾而非逃逸到全局 JSON handler，实现与注释一致。
4. **RAG 索引原子替换**（`app/Rag/RagSearch.php:124-216`）：临时库事务写入 → 失败回滚清理 → `rename` 原子上限，构建失败永不破坏线上索引。
5. **ZIP 上传纵深防护**（`app/UploadParser.php:128-197`）：文件数配额先于解压、声明 size 预检、解压后 `strlen` 复检、文件名拒绝遍历、`finally` 清理临时目录，每层注释说明意图。
6. **Content-Encoding 防护**（`app/ContentParser.php:43-65`）：解码步数上限防嵌套解压 DoS，inflate 带 max-length 防解压膨胀。
7. **AIClient 对劣质上游的防御矩阵**（`app/Client/AIClient.php`）：流内 error 帧显式失败防"空流假成功"、`httpCode=0` 假成功拦截、空流检测与非流式回退、tool_calls 分片归桶、已 emit 后不换 key 重试、日志只记 key 指纹。
8. **LiteWAF 统计页隐私设计**（`LiteWAF/lua/litewaf.lua`）：公开页 IP 脱敏、URI `token=` 打码、完整 IP 仅进 error log，且逻辑测试逐条断言这些约定（T19）。
9. **S1 前缀绕过的双语言回归闭环**：`skip_signature` 精确匹配修复后，PHP 与 Lua 两套测试都有反样本——"修复 + 双回归"的闭环意识。
10. **CI 分层与生产贴近**：单测矩阵 → 静态分析 → 架构测试 → Swoole 启动冒烟（真实上传 + MCP 握手）→ Docker 构建冒烟；schema 复用生产 `mariadb-init.sql`；compose 密码全部 `${VAR:?}` 强制校验。
11. **Docker/Compose 版本纪律**：rust/php/swoole/openresty/certbot 等版本全面 pin（仅 mariadb/redis 浮动，见建议）。
12. **mariadb-init.sql 与代码一致**：`logs.id CHAR(6)` 与 `getRaw()` 对应、`idx_created` 恰好覆盖 Event 删除路径、外键与索引齐全。

---

## 汇总

| 分区 | 文件数 | 严重 | 一般 | 建议 |
|---|---|---|---|---|
| 存储与数据链路 | 35（精读）+ 6（核实） | 1 | 7 | 12 |
| AI 与 RAG 链路 | 26（精读）+ 8（核实） | 0 | 6 | 11 |
| LiteWAF / 部署 / 脚本 / CI | 32（精读）+ 8（核实） | 1 | 8 | 18 |
| **合计** | **93** | **2** | **21** | **41** |

标注"待验证"保留项 3 处：zip 声明 size 篡改时 `ZipArchive::getFromIndex` 的实际行为、redis password 占位符在非 Docker 部署的实际影响、SpinYarn C 扩展内部是否做同步 Redis IO。此前 2026-08-29 的 LiteWAF 专项报告已移至 `docs/CRCLASH.md` 归档（该轮 14 项发现当时已全部闭环；本轮对该范围的增量发现见 G14-G18 与建议节）。
