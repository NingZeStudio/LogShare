# CRCLASH — LogShare 全面代码审查报告（第九轮 · 语义 RAG 上线后）

- **审查日期：** 2026-08-25
- **审查范围：** 项目全部源码 —— `app/` 61 文件约 6,800 行、`scripts/`（含新增清洗器与两个下载脚本）、`docker/`、`config/autoload/`、`tests/`、`composer.json/lock`；排除 `vendor/`、`.git/`、`runtime/`、`tmp/`、`rag/knowledge/` 内容文件与 `mappings/`
- **技术栈：** PHP 8.4+ · Hyperf 3.2（Swoole 6.2 常驻）· MariaDB · Redis（可选）· SpinYarn v1.0.0 · SQLite FTS5 + bge-m3/bge-reranker-v2-m3 语义管线 · Aternos Codex · Pest 3 + PHPStan level 5
- **本轮背景：** 第八轮问题全部修复后，项目完成了三批大变更：① 语义 RAG 管线（`SemanticClient` 多供应商 failover、向量召回 + rerank 精排、`doc_embeddings` 表、CJK bigram 检索、知识库清洗器）；② Agent 展示层与思维链优化（reasoning_content 回传、按工具定制的 tool_result summary）；③ Docker 与配置补全。当前基线：143 passed / 3 skipped、PHPStan 零错误、语义检索在线实测通过。

## 概览

本轮重点复审语义管线与 Agent 改动。整体架构演进方向正确：检索从纯词法升级为「词法召回 ∪ 向量召回 → rerank 精排」三级管线，降级路径完整；多供应商 failover、嵌入批处理的容错设计都达到了生产水准。

但发现一个**语义开启场景下的真实缺陷**：reranker 返回空结果集时（网关偶发行为），整个搜索会返回空——把已经召回的词法结果也丢掉了，违背「语义增强绝不能破坏检索」的自设约束。另有一个 Docker 部署缺口：本轮新增的 `AI_RAG_*` 环境变量未透传到 compose，容器化部署无法配置语义增强。

发现 **2 处「一般」**、**4 处「建议」**。无「严重」级问题。

---

## 问题清单

### 🔴 严重

无。

### 🟠 一般

#### M1. rerank 返回空结果集时丢弃全部词法召回 — `app/Rag/RagSearch.php:439-449`

- **问题：** `applySemanticEnhancement()` 的 try 块覆盖 embed → cosine → rerank 全链路。若 rerank 调用**成功但返回空 `results`**（网关限流时的静默空响应、异常 query 触发的空排序等），`$ranked = []` → `$out = []` 直接返回——此时词法召回可能已有 5 条完整结果，却被整体替换为空数组。
- **影响：** 语义开启时，任何一次 rerank 空响应都会让 `/rag` MCP 与 Agent 的知识库检索"凭空归零"，且无任何错误日志（没有抛异常）。这直接违背了代码注释自设的约束 "semantic search must never break retrieval"。
- **修复：** 循环结束后加兜底：`if ($out === []) { return array_slice($lexical, 0, $k); }`（一行）；可同时记一条 warning 日志便于观测网关质量。

#### M2. compose 未透传 AI_RAG_* 环境变量 — `docker/compose.yaml`（hyperf.environment）

- **问题：** 本轮为语义 RAG 新增的 `AI_RAG_ENABLED` / `AI_RAG_BASE_URL` / `AI_RAG_API_KEY` 环境变量覆盖已在 `Config::applyEnvironmentOverrides()` 实现，但 compose 的 hyperf 服务未透传这三个变量。
- **影响：** Docker Compose 部署时语义 RAG 无法通过环境变量开启或配置——镜像内 fallback 配置的 `ai.rag.enabled = false`，语义管线在容器化生产环境中永远关闭；想开启只能挂载自定义 Config.inc.php，与第八轮建立的「env 优先」部署模式相悖。
- **修复：** hyperf environment 补齐：
  ```yaml
  AI_RAG_ENABLED: ${AI_RAG_ENABLED:-false}
  AI_RAG_BASE_URL: ${AI_RAG_BASE_URL:-}
  AI_RAG_API_KEY: ${AI_RAG_API_KEY:-}
  ```
  （providers 多供应商列表仍需挂载配置文件支持，env 仅覆盖单主供应商场景——在 README 注明。）

### 🟡 建议

- **R1. topByCosine 全表加载正文 — `app/Rag/RagSearch.php:465-468`**：余弦扫描 SELECT 了 `d.body`（2250 分块 ≈ 3.4MB）进内存，但算相似度只需要 vec；命中 limit 后才需要 title/body。改为先 `SELECT e.rowid, e.vec` 打分，取 top 后再按 rowid 二次查询元数据，每次搜索省一次全表正文物化。
- **R2. 向量维度不匹配静默跳过 — `topByCosine():474`**：用户切换 embedding 模型后（如 1024 维 → 其他维度），`count($vec) !== count($queryVec)` 会让**所有**历史向量被静默跳过，语义增强悄悄退化为纯词法而无任何提示。建议：检测到维度不一致时记录一次 warning（提示重跑 rag:build）。
- **R3. 语义查询无短 TTL 缓存**：每次 `rag_search` 固定产生 embed + rerank 两次串行 API 往返（约 200–500ms 延迟与双份 token 成本）。对相同 query 做 60s 进程级缓存即可显著降低重复查询开销（Agent 循环中模型换词重查时尤其明显）。
- **R4. cache.enabled=false 时限流完全失效缺警示 — `RateLimitMiddleware.php:37`**：跳过逻辑本身正确（本地开发体验优化），但生产若误关全局缓存会连带失去限流而毫无征兆。建议 worker 启动时检测到该组合打一条显式警告日志。
- **R5. 清洗脚本对 forge/neoforge 的 admonition 处理依赖下载脚本串联执行**：单独手跑 `php scripts/clean_knowledge_docs.php` 无害（幂等已验证），但若未来新增知识库目录需记得同步 `UPSTREAM_DIRS` 白名单，否则上游 README 会被当内容索引。可在 README 的知识库维护节注明。

---

## 改进建议（按优先级）

1. **P0 — M1**：rerank 空结果兜底回退词法。一行修复 + 一条日志，消除语义管线的"归零"风险。
2. **P1 — M2**：compose 补齐 AI_RAG_* 透传（与第八轮 AI_ENABLED 同模式），并在 README 说明 providers 列表的 env 边界。
3. **P2 — R1/R2**：cosine 扫描瘦身 + 维度失配告警，语义开启后的每次搜索都受益。
4. **P3 — R3-R5**：按需排期。

## 正面亮点

- **语义管线容错设计成体系**：多供应商顺序 failover（per-provider 模型 ID 处理了 BAAI/ 前缀差异）、`relevance_score/score` 字段名兼容、嵌入批处理失败自动降级逐条并预截断超长文本（实测 2250/2250 全覆盖）、词法/向量双路召回合并去重——除 M1 一处外，降级链路完整。
- **检索降级三级体系闭环**：FTS AND → OR → LIKE AND → OR → CJK bigram 切分，配合语义增强共五层，任何形态的查询（英文签名 / 中文口语 / 混合）都有出路；测试覆盖到每一层的契约。
- **reasoning_content 回传修复彻底**：AIClient 累积完整思维链经 onToolCalls 传出，LogAgent 按轮回填 assistant 消息——DeepSeek 思维模式的 400 问题根治，且 mock server 测试覆盖了多轮场景。
- **tool_result summary 按工具定制**：read_log_file 只报「文件 + 行数」（原文是给模型的）、rag_search 输出命中清单、list_topics 折叠为主题目录——展示层第一次做到了"用户视角"而非"数据视角"。
- **知识库清洗器工程质量高**：单遍行状态机、fenced code 完整保护（MiniMessage 标签零误伤）、幂等验证通过、元文件删除规则限定在上游目录保护了手写蒸馏库的 README。
- **运维脚本健壮性**：下载脚本 fetch 失败保留旧版不误删、tarball/git 双通道、mdx 统一改名；compose 四服务 healthcheck 全覆盖。
- **第八轮遗留零残留**：M1-M4、R1-R7 全部落地且修复质量经本轮复核确认（含 compose env、summary 定制、bigram 检索等新需求的自然延伸）。

---

## 修复状态

第九轮问题已全部修复并验证：

- **M1**：`applySemanticEnhancement` 拆分为缓存壳 + `runSemanticPipeline`；rerank 返回空 results 时记录日志并回退词法排序，检索不再「归零」。
- **M2**：compose 补齐 `AI_RAG_ENABLED / AI_RAG_BASE_URL / AI_RAG_API_KEY` 透传（注释说明 providers 多供应商列表需挂载配置）。
- **R1**：cosine 扫描只物化 vec，命中 limit 后按 rowid 二次取元数据，省去每次查询的全表正文物化。
- **R2**：向量维度失配时进程内一次性 warning，提示重跑 rag:build。
- **R3**：语义结果 60s 进程级 FIFO 缓存（64 条），重复 query 免双次 API 往返。
- **R4**：限流跳过时输出一次性显式警告（cache.enabled=false 会一并失去限流）。
- **R5**：README 知识库维护节注明清洗白名单与复刷流程。

测试隔离同步修正：RagSearchTest 在 beforeEach 强制关闭语义开关并与外部网关解耦，新增「网关不可达 → 回退词法」回归用例。

## 验证结果

- Pest：**144 passed, 3 skipped**（新增语义降级回归用例）。
- PHPStan level 5：**No errors**。
- `/rag` MCP 在线验证：语义管线返回正常（1505 字节含结构化正文）。

*第九轮问题全部关闭。*
