# LogShare 内置 RAG 技术报告

- **版本基线：** 2026-08，v1.7.x
- **核心代码：** `app/Rag/RagSearch.php`、`app/Rag/SemanticClient.php`、`app/Controller/RagController.php`、`app/Command/RagBuildCommand.php`、`app/Config.php`
- **数据位置：** `rag/index.db`（默认路径，可用 `ai.mcp.rag.db` 覆盖）
- **知识库源：** `rag/knowledge/`

## 1. 系统定位

LogShare 内置 RAG 提供纯本地、无需额外服务的知识库检索能力，并作为 MCP JSON-RPC 服务随 Hyperf 主进程在 `/rag` 路径承载，供 AI Agent（`App\Agent\LogAgent`）调用。

检索分为两阶段：

1. **词法召回**：SQLite FTS5 BM25 + LIKE 子串（CJK 二元切分）；
2. **语义增强**（可选）：bge-m3 向量召回为主排序，词法结果去重后补充；任何语义环节失败自动回退纯词法，检索绝不为零。

> 2026-08 起：**已移除 bge-reranker 精排**。当前仅保留 bge-m3 向量召回，向量结果按余弦相似度优先，词法结果补齐 Top-K。

## 2. 知识库结构与分块

### 2.1 知识来源

- Fabric / Forge / NeoForge 开发文档、PaperMC 家族服务端与代理文档、Purpur、Glowstone、Geyser、Quilt 文档；
- 手工整理的 Android 启动器问题知识（`*-issues/`、`patterns/`、`renderers/`）、日志报错知识卡片（`日志分析/`，KB-* 编号）、ZalithLauncher 控制层帮助等；
- 上游文档由 `scripts/download_*.sh` 下载，经 `scripts/clean_knowledge_docs.php` 清洗（frontmatter/MDX/admonition/HTML 剔除）。

### 2.2 分块规则（`RagSearch::chunkMarkdown`）

- 以 `# ` 提取文档标题（H1）作为文档标题；
- 以 `## ` 切分章节，形成独立分块；每块标题为 `H1 > H2`，保留搜索上下文；
- `# ` 之前的前言区单独成块；
- 无 `## ` 的整篇文档作为一个分块；
- 分块结果需非空文本。

### 2.3 当前索引规模

| 指标 | 数值 |
|---|---|
| 知识库文件数 | 632 |
| 分块数 | 2250 |
| FTS5 去重词元 | 22043 |
| FTS5 词元出现次数 | 475227 |
| 已向量化分块 | 2250（全部） |
| 向量维度 | 1024（bge-m3） |

## 3. 存储设计（SQLite）

单文件 `rag/index.db`，两个核心表：

```sql
-- FTS5 虚拟表：词法检索
CREATE VIRTUAL TABLE docs USING fts5(
  title, body, source,
  tokenize = 'porter unicode61'
);

-- 向量存储：rowid 对齐 docs.rowid，vec 为 packed float32
CREATE TABLE doc_embeddings(
  rowid INTEGER PRIMARY KEY,
  vec BLOB NOT NULL
);
```

- 向量以 `pack('g*', ...)` 打包大端 float32 存入 BLOB；查询时按 `unpack('g*')` 还原并比对维度。
- 维度不一致的历史向量会被安全忽略（记录一次性 Syslog 警告），提示重新执行 `rag:build`。
- 语义未开启或嵌入失败时 `doc_embeddings` 为空，检索透明退回纯词法。

## 4. 构建流程

入口：`php bin/hyperf.php rag:build`（`RagBuildCommand` → `RagSearch::buildIndex`）。

**原子构建保证：**

1. 写入临时 SQLite 文件 `{dbPath}.tmp.{随机}`，成功后再 `rename` 原子替换正式索引；失败清理临时文件并抛错，旧索引不受影响。
2. 全部文档与分块写入临时库（单事务）后提交。

**向量化（可选，受 `ai.rag.*` 控制）：**

- `SemanticClient::embed()` 以 16 条为一批调用 `POST {baseUrl}/embeddings`；
- 批量失败时逐条重试；仍失败的分块跳过，保留词法索引；
- 单条文本经 `mb_strcut(…, 0, 4000)` 截断后嵌入；
- provider 按配置顺序故障切换，无 API Key 的 provider 跳过；
- 构建完成输出 `完成: N 个文件, M 个分块，已向量化 K 条`。

**构建语义：**

- 复用当前 `Config.inc.php` / `.env` 中的 provider；不同模型产生不同维度或语义分布，**切换 embedding 模型后必须重建索引**，避免混合向量残留导致排序混乱（本轮已完成同 provider 全量重建）。

## 5. 查询流程

入口：`RagSearch::search(query, k)`（`k` 限制在 1–20），结果统一附带 `snippet` 片段与 `score` 说明。

### 5.1 词法召回

1. **FTS5 BM25（英文/代码 token 前缀匹配）**：
   - 提取 `[0-9A-Za-z_]+` 词元，构造 `AND ...*`，空结果降级为 `OR ...*`；
   - 标题权重 10、正文权重 1；
   - 候选池 `max(20, k*4)`。
2. **LIKE 子串（CJK / 部分匹配）**：
   - `splitTerms` 切词后，CJK 连续串爆破为重叠二元组（如 `数据包导致失败` → `数据/据包/包导/...`），解决无分词问题；
   - 先 AND 后 OR；标题命中计 2 分、正文计 1 分，`length(body) ASC` 作为同分排序；
   - LIKE 通配符 `% _ \` 均已转义。

### 5.2 语义增强（向量优先）

`RagSearch::applySemanticEnhancement` → `runSemanticPipeline`：

1. 查询文本经同一 `embeddingModel` 向量化；
2. `topByCosine`：全库 `doc_embeddings` 与查询向量做余弦相似度全扫描，取 `max(20, k*4)`；
3. **合并与排序**：
   - 向量结果按相似度降序为主体；
   - 词法结果按 `source#title` 去重后**追加**补齐；
   - 截取 Top-K 返回；
4. 任意语义环节异常（连接失败、空响应、维度不一致等）→ 记录 Syslog，返回纯词法 Top-K。

**性能特征：**

- 索引构建 O(全库一次)，查询向量扫描 O(N×D)；N=2250、D=1024 时全扫描开销在毫秒级；
- 相同 `query+k` 检索结果进程内缓存 60 秒（FIFO，上限 64 条 / 1 MiB），避免 Agent 反复试探重复支付 embed 往返。

## 6. MCP 接口

- 协议：MCP JSON-RPC 2.0，端点 `/rag`（GET/POST）；
- 工具：
  - `rag_search(query, k=5)`：检索相关片段；
  - `list_topics()`：按目录汇总主题与文件分布，帮助 Agent 选择检索方向；
- **访问控制**：默认仅允许本机回环请求；经反向代理公网暴露时必须在 `ai.mcp.rag.authToken` 配置强随机 token，并携带 `Authorization: Bearer <token>`。请求体无应用层大小限制（MCP transport 有意设计），但 `query` 受服务端长度限制，`k` 有上下限。

## 7. 配置

语义 RAG 由 `.env` 的 JSON 提供（`AI_RAG_PROVIDERS`）：

```env
AI_RAG_ENABLED=true
AI_RAG_PROVIDERS=[{"name":"provider-a","baseUrl":"https://.../v1","apiKey":"...","embeddingModel":"BAAI/bge-m3"}]
```

| 字段 | 说明 |
|---|---|
| `name` | 供应商标识（仅用于日志/故障切换） |
| `baseUrl` | 兼容 OpenAI `/embeddings` 语义的网关地址 |
| `apiKey` | 供应商密钥 |
| `embeddingModel` | 向量模型名（如 `BAAI/bge-m3`） |

- provider 按数组顺序故障切换；
- 配置校验：RAG 启用时，每项必须含 `name`、有效 `baseUrl`、非空 `apiKey`、非空 `embeddingModel`，否则启动即失败；
- 主推理模型与 RAG 相互独立，各自从 `.env` 读取，互不写死。

## 8. 降级与容错矩阵

| 场景 | 行为 |
|---|---|
| 语义 RAG 未配置 / 未启用 | 纯词法检索 |
| embed 服务不可达 / 超时 / 错误响应 | 记录 Syslog，回退词法，结果非空 |
| 批量嵌入部分失败 | 失败分块无向量，词法路径仍可命中 |
| 向量维度与当前模型不一致 | 忽略该向量 + 一次性警告，建议重建 |
| LIKE 通配符注入 | `%`/`_`/`\` 转义，防子串逃逸 |
| rerank 相关请求 | 不再发起（已移除，无该网络调用） |

## 9. 已知边界与演进建议

- **构建数据一致性**：词法与向量在同一临时库写入，但向量化在分块落库事务提交之后批量进行；构建中断只可能停留在“有词法、少向量”的中间态，正式索引不受影响（旧库保留）。
- **查询资源**：向量全扫描在库规模扩大后应引入 ANN/分批索引；单查询 embed 仍需 1 次往返。
- **跨模型迁移**：更换 embedding 模型必须重建索引并用同模型回填，避免维度与语义混用。
- **观测**：可增加每 provider 调用统计、向量扫描耗时、命中率指标，辅助调参。