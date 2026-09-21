# LogShare 全仓库 Code Review 报告（第三期）

## 修复与审查历史

- **第一期（2026-08-30）：** 共发现 64 项问题（严重 2、一般 21、建议 41）。除 G10（按架构决策保留现状）外，其余 63 项已全部闭环修复并通过自动化回归验证。
- **第二期（2026-09-11）：** 共发现 22 项问题（严重 5、一般 10、建议 7）。全量 22 项问题已于 2026-09-11 全部闭环修复并纳入版本发布流水线。
- **第三期（2026-09-21，当前）：** 伴随 v1.8.0 统一日志异步事件队列（EventQueue）、违规规则 AES-256-GCM 加密存储、LogAgent CoT 思维链因果追踪重塑、已知领域知识管理等核心重构落地，本期针对系统全量模块实施深度审查。共发现 **33 项问题**（严重 10 项、一般 12 项、建议 11 项），梳理核心正面亮点 16 项。

---

## 概览

- **审查日期：** 2026-09-21
- **审查范围：** 全仓库源代码与基础设施配置：
  - `app/Queue/` & `app/Process/`：统一异步事件队列体系、死信队列（DLQ）、常驻消费者进程（`EventQueueConsumer` / `AiQueueConsumer`）、Redis 流式驱动与内存自回收机制。
  - `app/System/` & `app/Config.php`：已知领域知识管理、AES-256-GCM 规则加密、客户端真实 IP 判定与安全封禁、动态配置热重载、审计与系统诊断日志。
  - `app/Agent/` & `app/Rag/`：LogAgent 思维链（CoT）因果线索硬性排查策略、已知领域知识强行拼接、混合 RAG 检索引擎（FTS5 + LIKE + 向量余弦召回）。
  - `app/Controller/` & `app/Middleware/`：HTTP 控制器鉴权与错误处理、反向代理限流中间件、CORS 与上传安全展开引擎。
  - `docker/`、`scripts/` & `tests/`：Docker 多阶段构建、jemalloc 内存配置、CI/CD 自动化验证套件及单元/架构测试用例。
- **代码规模统计：** `app/` 目录下共 91 个核心 PHP 类库文件，`tests/` 目录下 40 个测试及辅助文件，以及 OpenLiteWaf / OpenLiteStats 子模块与自动化脚本。
- **技术栈识别：** PHP 8.4/8.5 + Hyperf 3.2（Swoole 6.2 协程常驻）；jemalloc 内存分配器；OpenResty 1.27.1.2（Lua 5.1/LuaJIT）；MariaDB 11.8 / Redis 7.4 / SQLite FTS5；Pest 3 + PHPStan level 5。
- **问题汇总：**
  - **严重（Critical）：** 10 项
  - **一般（Moderate）：** 12 项
  - **建议（Minor）：** 11 项
- **整体评价：**
  项目在经历 v1.8.0 重大架构演进后，整体设计格局极为现代化：基于 Redis Stream 的 Claim Check 异步事件解耦将日志上传响应压缩至毫秒级；两阶段优雅排空与基于 VmRSS 物理常驻内存自回收机制展现了对 Swoole 常驻进程与 Linux 内存管理极高水准的掌控力；LogAgent 强制因果追踪思维链与权威领域知识机制切中了复杂工程日志排障的核心命脉。
  然而，在跨 Worker 并发一致性、极端环境重试收敛（毒丸事件在 Redis Stream 中的可变性约束）、客户端真实 IP 判定、解压缩炸弹防护以及公开 AI 端点防护等边界防护上，仍存在若干高危隐患，需进行严密闭环修复。

---

## 问题清单

### 一、严重问题（Critical）

#### C1. `SecurityService.php`：客户端真实 IP 判定存在 XFF 伪造与私网强信任漏洞，可绕过 IP 封禁与限流
- **定位：** `app/System/SecurityService.php:59-61`, `app/System/SecurityService.php:73-86`
- **问题成因：**
  1. 代码第 59-61 行无条件将私网 IP 判定为可信代理：即使配置中未声明，只要连接来自私网（`isPrivateIp($remote)`），代码直接置 `$isTrusted = true`；
  2. 解析 `X-Forwarded-For` 头时采用 `explode(',', $forwardedFor)` 从左往右顺序遍历，并采纳**首个**有效 IP。
- **潜在影响：**
  在标准 Nginx 反代环境下（`X-Forwarded-For: $proxy_add_x_forwarded_for`），攻击者只需在请求头中伪造 `X-Forwarded-For: 1.2.3.4`，经 Nginx 转发后形成 `1.2.3.4, <真实IP>`。后端的 `resolveClientIp` 直接采纳伪造的 `1.2.3.4`，被封禁的攻击者可借此 **100% 绕过 IP 封禁**；同时可恶意消耗任意正常用户的限流配额，并导致安全审计溯源完全失效。
- **修复方案：**
  严格以配置项 `trustedProxies` 为唯一准绳；优先采用反代经强行覆盖的单值标头 `X-Real-IP`；使用 `X-Forwarded-For` 时须从右往左逐级剥离信任代理，提取最外层的第一个公网 IP。

#### C2. `RequestParser.php`：Brotli 解压缩缺乏解压尺寸硬性限制，易引发 OOM 拒绝服务（压缩炸弹）
- **定位：** `app/Parser/RequestParser.php:82-87`
- **问题成因：**
  `RequestParser::decodeBody` 中对 Brotli 解压执行 `brotli_uncompress($rawBody)`，解压完成后才通过 `strlen($decompressed) <= static::MAX_DECOMPRESSED_BYTES` 校验。PHP `ext-brotli` 的 `brotli_uncompress` 第二个参数为字典而非解压限制。
- **潜在影响：**
  攻击者只需发送几十 KB 的超高压缩比 Brotli 炸弹（解压后达数 GB），单次系统调用瞬间吞噬宿主机物理内存，触发操作系统 OOM Killer，直接造成 Swoole resident worker 进程崩溃被杀。
- **修复方案：**
  结合 `Content-Length` 对 Brotli payload 实施前置比例阈值限制，或采用分块流式解码并在累计达到 `MAX_DECOMPRESSED_BYTES` 时立刻阻断抛出异常。

#### C3. `SecurityService.php`：AES-256-GCM 规则解密失败时 fail-open 返回密文，导致敏感词与正则拦截完全静默失效
- **定位：** `app/System/SecurityService.php:523-524`, `app/System/SecurityService.php:714-724`
- **问题成因：**
  在 `SecurityService::decryptSecret` 中，当 `openssl_decrypt` 失败（返回 false）时，函数直接返回了原始密文字符串 `$ciphertext`（即 `enc:v1:...`）。
- **潜在影响：**
  若密钥因故变更或文件损坏导致解密失败，关键词列表将填充无效密文，敏感词过滤 **100% 静默失效**；正则规则变为非法正则表达式 `@preg_match("enc:v1:...", ...)`，被 `@` 抑制后恒返回 false。整个违规内容拦截层在零告警状态下彻底失效。
- **修复方案：**
  当字符串以 `enc:v1:` 开头但解密失败时，必须记录 `Syslog::critical` 级别警报，抛出配置异常或阻断通过，决不能返回密文字符串当作明文规则。

#### C4. `SecurityService.php`：密钥生成逻辑存在文件权限竞态窗口与多 Worker 并发重写冲突
- **定位：** `app/System/SecurityService.php:471-481`
- **问题成因：**
  `file_put_contents` 创建 `.security_secret` 受系统 umask（通常 0022）影响初始权限为 0644（全系统可读），下一行才执行 `chmod 0600`，存在微秒级读取竞态；且当 `.env` 未指定密钥时，多个 Worker 进程启动初次并发访问会互相覆盖写入不同的随机密钥，导致 Worker A 加密的规则 Worker B 无法解密。
- **潜在影响：**
  同机多租户环境下存在密钥被读取风险；多 Worker 之间因密钥不一致频繁抛出解密失败异常。
- **修复方案：**
  使用 `fopen($path, 'x')` 原子独占创建，或通过排他文件锁 `flock(LOCK_EX)` 保护，确保由单一主进程安全生成一次。

#### C5. `EventQueueConsumer.php`：重试次数未持久化导致死循环，毒丸事件永不进入死信队列（DLQ）
- **定位：** `app/Process/EventQueueConsumer.php:175-179, 194-205`, `app/Queue/QueueEvent.php:157-160`
- **问题成因：**
  Redis Stream 条目只读且不可变。处理失败时调用 `$event->incrementAttempts()` 仅递增了 PHP 内存中对象的尝试次数，并未回写 Redis Stream。当后台协程 `reclaimLoop` 通过 `XAUTOCLAIM` 回收超时消息时，重新拉取的 `$fields['attempts']` 始终是初始值 `'1'`，导致 `$event->canRetry()` 恒为 `true`，`DeadLetterQueue::push()` 永远不会被触发。
- **潜在影响：**
  任何因数据损坏或逻辑缺陷失败的毒丸事件会被每隔 60 秒无限循环重复消费，无法流转至死信队列，造成 CPU、I/O 浪费和错误日志刷屏。
- **修复方案：**
  使用外部原子计数键 `events:retry:{$id}`，或通过 `XPENDING` 提取 Redis Stream 内核维护的真实投递次数。

#### C6. `RedisClient.php`：连接默认 1.5s 读取超时与 Stream 阻塞读取（2s / 5s）冲突，引发周期性读取超时与重连风暴
- **定位：** `app/Client/RedisClient.php:21-22, 60-63`, `app/Process/EventQueueConsumer.php:111`, `app/Process/AiQueueConsumer.php:239`
- **问题成因：**
  `RedisClient::createConnection()` 默认连接超时为 1.5 秒。在 phpredis 中，未显式设置 `OPT_READ_TIMEOUT` 时，底层 socket 读取超时会沿用连接超时（1.5s）。而消费者的阻塞等待时间为 2 秒或 5 秒。在空闲无消息时，Redis 客户端 socket 在 1.5 秒即发生读取超时，抛出 `RedisException: read error on connection`。
- **潜在影响：**
  常驻消费者在无新消息流入时，每隔 1.5 秒即遭遇一次连接断开重连，造成高频错误日志和不必要的系统损耗。
- **修复方案：**
  在 `createConnection()` 中显式配置 `$conn->setOption(\Redis::OPT_READ_TIMEOUT, 30.0)`（或 -1 无限），并开启 TCP Keepalive。

#### C7. `EventQueueConsumer.php`：死信入队失败时仍执行 XACK 与 XDEL，导致致命事件无感知静默丢失
- **定位：** `app/Process/EventQueueConsumer.php:168-175`, `app/Queue/DeadLetterQueue.php:22-45`
- **问题成因：**
  `DeadLetterQueue::push()` 发生异常时内部容错返回 `null`。而 `EventQueueConsumer` 未对返回值进行校验，无论是否成功写入死信流，均执行了 `xAck` 和 `xDel`。
- **潜在影响：**
  在极端内存压力或网络偶发抖动导致死信流写入失败时，原始事件直接从主流中被物理删除，业务事件无感知静默蒸发。
- **修复方案：**
  断言 `DeadLetterQueue::push()` 返回有效 streamId 后才执行 ACK 与删除；否则保留在 Pending 列表中等待下一轮回收。

#### C8. `AIController.php`：公开 AI 分析接口遗漏 IP 黑名单校验，黑名单防御被击穿
- **定位：** `app/Controller/AIController.php:14-30`
- **问题成因：**
  `GET /{version:v?1}/ai/{id}` 端点未调用 `$this->checkIpBan()`。对比 `LogController::create` 与 `AIAnalyseController::analyse`，二者均在入口严格执行了 IP 封禁检查。
- **潜在影响：**
  被列入黑名单的恶意来源 IP 可直接通过 `GET /v1/ai/{id}` 消耗昂贵的大模型推理 Token 并挤占 AI 队列并发槽位。
- **修复方案：**
  在 `AIController::ai(string $id)` 方法入口首行补充 `$this->checkIpBan()`。

#### C9. `AIAnalyseController.php`：调用 `runAiAnalysis` 漏传 `$logId`，导致 LogAgent 丢失文件与上下文检索能力
- **定位：** `app/Controller/AIAnalyseController.php:46`
- **问题成因：**
  当客户端提供现有日志 ID 请求分析时，第 46 行调用 `return $this->runAiAnalysis($content, "ai:analysis:" . $id->getRaw());`，未传第三个参数 `$logId`，导致传递给 `LogAgent::analyze()` 的 `logId` 恒为 `null`。
- **潜在影响：**
  在 `LogAgent::buildTools` 中，`list_log_files`、`read_log_file`、`grep_log_file` 三个关键文件工具完全不会被注册给模型。模型接收到了强制线索查找的系统提示词，却缺乏排查工具，排障能力大幅倒退甚至引发工具调用幻觉。
- **修复方案：**
  显式传入日志 ID：`return $this->runAiAnalysis($content, "ai:analysis:" . $id->getRaw(), $id->get());`。

#### C10. `RateLimitMiddleware.php`：限流中间件解析客户端 IP 时未传 Headers，反向代理下所有用户被聚合成代理 IP 导致全局误限流
- **定位：** `app/Middleware/RateLimitMiddleware.php:56, 91-94`
- **问题成因：**
  中间件仅调用了 `SecurityService::resolveClientIp($server)` 而未传递 `$request->getHeaders()`。Nginx 反代透传的真实客户端 IP 存放于 Header 中，缺少 Header 导致其回退至代理自身的本地 IP（`127.0.0.1`）。
- **潜在影响：**
  一旦开启限流，所有经反代访问的正常用户共享同一个速率限制计数桶，极短时间内全站触发 429 误拦截。
- **修复方案：**
  将 `$request->getHeaders()` 传入 `resolveClientIp($server, $request->getHeaders())`。

---

### 二、一般问题（Moderate）

#### M1. `Config.php`：`ensureFresh` 在每次 `Config::Get()` 均执行磁盘 stat 与 Redis 远程 IO，高并发下拖垮吞吐
- **定位：** `app/Config.php:268-292, 295-299`
- **问题成因：** `Config::Get()` 每次调用均触发 `ensureFresh()`，执行 `clearstatcache` + `filemtime` + `filesize` 以及同步 Redis `get`。单请求内多次读配置产生数倍的无谓系统调用和网络往返。
- **修复方案：** 引入微秒/毫秒级进程内时间戳防抖缓存（如 500ms 检查一次，或在请求生命周期入口中间件中统一检查一次）。

#### M2. `DomainKnowledgeManager.php` & `SecurityService.php`：本地文件读写缺乏完整事务文件锁（flock），存在并发脏写覆盖风险
- **定位：** `app/System/DomainKnowledgeManager.php:89-155`, `app/System/SecurityService.php:342-351`
- **问题成因：** “读取 -> 修改 -> 写入”全过程无排他文件锁；且 `writeToFile` 在磁盘写满失败时静默退出，调用方依然继续同步 Redis（幽灵写入）。
- **修复方案：** 引入 `flock(LOCK_EX)` 事务锁；写盘失败时显式抛出异常，杜绝脏写。

#### M3. `UploadParser.php`：`expandZip` 存在目录炸弹 CPU 耗尽风险与非文本文件穿透
- **定位：** `app/UploadParser.php:89-100, 163-174`
- **问题成因：** 对条目数量的计数仅在非目录文件时扣减，若 ZIP 内构造数百万个 `/` 结尾的纯目录条目将触发空转耗尽 CPU；且解压出的非文本文件未做扩展名过滤。
- **修复方案：** 在 `zip->open` 之后断言 `$zip->numFiles <= $maxFiles * 2`；严格限定解压文件后缀白名单。

#### M4. `SecurityService.php`、`AiMetricsService.php` 等：生产环境下多处直接使用高危 `$redis->keys()` 命令
- **定位：** `app/System/SecurityService.php:144`, `app/System/AiMetricsService.php:613`, `app/System/StorageHealthService.php:110`
- **问题成因：** 直接调用 O(N) 复杂度的 `KEYS` 命令，大库下阻塞 Redis 单线程事件循环数秒。
- **修复方案：** 改用增量游标 `SCAN` 遍历，或使用专门的 Set 集合维护索引。

#### M5. `RedisClient.php`：协程内长连接失效后未在 Hyperf Context 中清理，死连接可能被持续复用
- **定位：** `app/Client/RedisClient.php:64-75`
- **问题成因：** 协程内仅凭本地 `isConnected()` 判定可用性，服务端关闭连接后仍返回 true；捕获异常后未从 `Context` 中清理句柄。
- **修复方案：** 提供 `purgeCoroutineConnection()` 并在遇到连接异常时销毁 Context 句柄。

#### M6. `EventQueue.php`：`executePipeline` 粗暴短路系统内置开关，且未注册监听器时将事件误判为执行失败并抛入死信队列
- **定位：** `app/Queue/EventQueue.php:205-216`
- **问题成因：** 当两个内置异步开关均关闭时，流水线直接提前返回，导致后续挂载的自定义事件处理器被强制跳过；当无监听器时返回 `false` 导致空事件被当作失败重试并打入死信。
- **修复方案：** 移除顶层短路，由各个 Handler 自身开关决策；无监听器时视作安全的空操作并返回 `true`。

#### M7. `EventQueueConsumer.php`：排空与空闲自回收判断存在盲区
- **定位：** `app/Process/EventQueueConsumer.php:200-205, 262-273`
- **问题成因：** `reclaimLoop` 遍历中遗漏了 `$this->draining` 检查；空闲判断仅检查 `xPending` 而未检查未消费积压（Lag），突发流量涌入时易误判为空闲并触发重启。
- **修复方案：** 补充排空跳出逻辑；空闲检测结合 `lag` 与 `pending` 综合评定。

#### M8. `DeadLetterQueue.php`：`retry()` 重试时丢失原事件 ID，且并发重试缺乏防重机制
- **定位：** `app/Queue/DeadLetterQueue.php:107-137`
- **问题成因：** `dispatch` 重新生成了新的 UUID，丢弃了死信中持久化的原事件 ID，导致审计链路断裂；多管理员并发重试缺乏互斥。
- **修复方案：** 允许 `dispatch` 透传原 `id`；重试时增加短期分布式锁防重。

#### M9. `AdminController.php`：RAG 文档上传端点缺少文件大小校验，绕过 `MAX_UPLOAD_SIZE` 防护
- **定位：** `app/Controller/AdminController.php:778-831`
- **问题成因：** 控制器直接调用 `RagManager::saveDoc`，绕过了 `uploadDoc` 中的 5MB 大小限制检查。
- **修复方案：** 在控制器中增加 `$file->getSize() > RagManager::MAX_UPLOAD_SIZE` 严格校验。

#### M10. `AdminController.php`：清空死信队列审计日志记录参数类型错误
- **定位：** `app/Controller/AdminController.php:1153-1154`
- **问题成因：** `AuditLogManager::record` 成功标志（第 4 个参数）传入了整数 `$cleared`。若死信队列本就为空，`$cleared === 0` 被隐式转为 `false` 误记为操作失败。
- **修复方案：** 成功状态固定为 `true`，将清理计数移入详情数组：`['cleared' => $cleared]`。

#### M11. `RagSearch.php`：知识库重建重命名文件前未显式释放旧 PDO 连接
- **定位：** `app/Rag/RagSearch.php:249-256`
- **问题成因：** 执行 `rename($tmpPath, $this->dbPath)` 前，旧数据库连接 `$this->pdo` 依然保持打开状态。
- **修复方案：** 在 `rename()` 之前显式将 `$this->pdo = null;` 释放，重命名后再重新打开。

#### M12. `tests/`：缺少 AdminController 的直接单元测试与 AI 控制器核心安全断言
- **定位：** `tests/Unit/ControllersUnitTest.php` 及 `tests/`
- **问题成因：** 单测未覆盖核心管理端点 `AdminController`，且缺少对 `checkIpBan()` 及 `$logId` 绑定的断言。
- **修复方案：** 补齐 `AdminControllerTest.php` 并加强安全回归断言。

---

### 三、优化建议（Minor）

1. **`LimitLinesFilter.php:16-18`：行数统计避免巨大内存分配**
   将 `count(explode("\n", $data))` 替换为 `substr_count($data, "\n") + 1`，底层 C 语言快速扫描，零额外数组内存分配。
2. **`UsernameFilter.php:18-19`：路径脱敏正则补全无尾随斜杠边界**
   优化正则为 `(?<!\w)\/(?:home|Users)\/([^\/\s"':]+)`，覆盖未带尾随斜杠的终端参数路径。
3. **`SecurityService.php:116-125`：`ipInCidr` 增加 IPv6 CIDR 支持**
   当前基于 `ip2long` 仅支持 IPv4。建议改用 `inet_pton` 结合位运算统一支持 IPv4 与 IPv6 网段。
4. **`SystemLogManager.php` / `AuditLogManager.php`：避免本地文件全量读入**
   当 Redis 失效回退读文件时，避免使用 `file()` 一次性将数百 MB 日志读入内存，改用 `fseek` 逆向指针扫描。
5. **`EventQueueConsumer.php#L123`：垃圾回收应细化至单任务处理之后**
   在每条任务的 `finally` 块中调用 `gc_collect_cycles()` 和 `gc_mem_caches()`，防止大体积日志堆积造成内存毛刺。
6. **`SecurityAuditHandler.php#L93`：审计自身异常时应阻断后续链路**
   在发生存储等非业务异常时调用 `$event->stopPropagation()`，遵循安全领域的 Fail-closed 原则。
7. **`AiQueueConsumer.php#L274`：重试计数避免锁冲突误递增**
   在遇到并发运行锁（活任务）时跳过 `INCR` 重试计数，防止耗时较长的大日志被误判为毒丸。
8. **`EventQueue.php#L267`：处理器支持容器化依赖注入**
   将 `new $handler()` 升级为 Hyperf 容器 `ApplicationContext::getContainer()->make($handler)`。
9. **`LogController.php:84-85`：提取 Bearer Token 增加 `trim()`**
   避免请求头细微空格导致鉴权失败，与 `AdminAuthMiddleware` 保持一致。
10. **`hyperf.Dockerfile:21`：扩展安装脚本增加校验和锁定**
    为 `install-php-extensions` 下载引入 BuildKit `--checksum=sha256:...` 校验。
11. **`RagSearch.php:619`：向量余弦扫描 JOIN 查询优化**
    在 `doc_embeddings` 冗余存储 `topic` 字段，避免对 FTS5 虚表执行耗时的无索引关联。

---

## 正面亮点（Strengths）

1. **LogAgent 思维链与因果约束重构出色：**
   将“除非极其孤立简单的报错，否则强制至少一次日志上下文线索查找”明确写入 System Prompt 与思维链，彻底扭转了大模型面对复杂日志草率收敛的缺陷。
2. **已知领域知识强行拼接机制：**
   运维配置的已知领域知识（≤200 字）直接拼接入 System Prompt，剥离了大模型的写权限与检索损耗，形成确定性的业务先验规则。
3. **Claim Check 模式实现超轻量 Stream 载荷解耦：**
   坚决避免将大体积日志原文直接塞入 Redis Stream，而是传递精简元数据或带 TTL 的缓存键，最大程度保护 Redis 内存。
4. **基于 VmRSS 物理常驻内存自回收机制：**
   针对 Swoole 常驻进程中 PHP Zend Arena 与 OS 堆分配器的碎片滞留机理，通过硬上限、空闲超时与 `/proc/self/status` 的真实物理常驻内存（`VmRSS`）检测，实现退出后由 Swoole Manager 重新拉起重置 RSS。
5. **Redis 7+ `deleted-ids` 幽灵条目自动清理：**
   深度适配 Redis 7+ 返回的 `deleted-ids`，对于已在 Stream 中被物理删除但仍在 PEL 中挂起的死 ID 主动执行 `XACK` 消除，杜绝游离泄漏。
6. **分级降级弹性保障：**
   `EventQueue::dispatch` 优先投递 Redis Stream；Redis 不可用时无缝降级至 Swoole 异步子协程；CLI/单测环境下同步降级执行，容灾弹性极佳。
7. **密码自动回填保护：**
   `Config::restoreMaskedSecrets` 充分考虑前端将脱敏字符串（`******`）原样提交的边界，自动还原真实密码，杜绝配置被意外清空。
8. **坚固的路径遍历防护：**
   `UploadParser::validateFileName` 过滤 NUL 字节、控制字符，并按 `/` 彻底切分路径段拒绝任何 `.` 或 `..`，文件名安全滴水不漏。
9. **脱敏过滤链 Fail-open 与可观测性兼备：**
   PCRE 执行异常（回溯超限）时捕获并平滑返回原文，同时显式记录 `Syslog::error`，保障系统可用性的同时提供完备的排查依据。
10. **全链路 Brotli/Gzip/Deflate 透明解压缩：**
    `RequestParser` 统一自适应解压并严格设防解压炸弹上限，兼顾网络带宽与计算安全。
11. **动态配置原子替换与版本协同：**
    `Config::saveDynamic` 采用临时文件加 rename 原子替换，配合 Redis 微秒级版本号实现跨 Worker 毫秒级热生效。
12. **高质量混合检索引擎：**
    FTS5 BM25 + LIKE 降级 + IEEE 754 float32 BLOB 向量打包，零外部扩展依赖纯 PHP + SQLite 高效运行。
13. **分层分级检索预算控制：**
    外部高成本调用（Web 搜索、GitHub）代码层硬拦截；本地 RAG 软提醒注入，兼顾成本与排障成功率。
14. **严格的时序安全校验：**
    `AdminAuthMiddleware` 采用 `hash_equals` 防时序攻击，未配置抛 500，未启用抛 404，拒绝信息泄露。
15. **先进的内存治理基础设施：**
    镜像内置 jemalloc 并激进配置 `dirty_decay_ms:1000`，彻底解决 Swoole 长连接与大分配后的内存碎片滞留。
16. **无构造反射单测设计：**
    HTTP 控制器单测采用反射实例化，完美规避 Hyperf AOP 代理干扰代码覆盖率的问题。
