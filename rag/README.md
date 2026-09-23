# RAG 检索（SQLite FTS5 + 向量混合检索本地知识库）

LogShare 内置工业级混合检索 RAG（Retrieval-Augmented Generation）：基于 SQLite FTS5（BM25）本地全文索引与可选向量模型（OpenAI / FastEmbed / Ollama / TEI 等）的混合语义召回。整合于 Hyperf 进程内，作为 MCP 服务在主服务的 `/rag` 路径承载（JSON-RPC 2.0 协议）。该端点默认仅允许本机回环调用；通过反向代理或外部 MCP 客户端访问时需配置 `ai.mcp.rag.authToken` 并携带 Bearer Token。

## 核心架构与特性

- **Agentic RAG Plus 架构**：内置 `RagSearch::TOPIC_DESCRIPTIONS` 知识库主题地图，LogAgent 可通过 `list_topics` 自主理解各知识目录涵盖范畴；支持通过 `rag_search(query, k, topic)` 进行主题约束的精准定向召回。
- **混合检索（Hybrid Search）**：向量语义检索（优先）+ 词法全文检索（FTS5 BM25 + 中文 LIKE 兜底补充），兼顾同义意图理解与精确错误代码/类名匹配。
- **双库原子切换与在线热更新**：`rag:build` 编译先写入临时 SQLite 数据库，校验通过后原子替换 `rag/index.db`；Web 进程自动感知 `filemtime` 毫秒级热生效，构建失败不影响线上检索。
- **管理后台全生命周期 CMS**：支持在 Admin 控制台进行文档在线浏览、检索、编辑保存、文件上传（5MB 限制与白名单过滤）、异步触发重建及构建进度实时查看。

## 组成

```
app/Rag/
├── RagSearch.php              核心检索类（FTS5 建表 / 切块 / BM25 / LIKE / 向量召回）
app/Controller/
├── RagController.php          Streamable HTTP MCP server（JSON-RPC 2.0，主 server /rag 路径）
├── AdminController.php        Admin 控制台 CMS 与构建监控端点
app/Command/
└── RagBuildCommand.php        建索引命令（php bin/hyperf.php rag:build）
rag/
├── index.db                   SQLite 索引（构建生成，路径由 ai.mcp.rag.db 指定，勿提交）
└── knowledge/                 知识库正文（Markdown / TXT / LOG，按主题分目录，手工维护）
    ├── 日志分析/              KB 编号报错条目库（现象-原因-解决方案），按异常类型归类
    ├── patterns/              崩溃与故障模式诊断卡（签名-含义-常见触发-修复步骤-置信度）
    ├── format/                crash-report / hs_err_pid / latest.log 三大日志格式速查
    ├── android-native-lib/    Android 原生库（lib 型 mod）加载问题
    └── mobile_launcher/       手机启动器渲染器体系与 Minecraft 版本对应关系
```

知识库为手工维护：易过期的启动器实战与各 ModLoader/服务端开发文档已移除，改由 LogAgent 的 GitHub 实时排障工具链提供动态支撑（见 AGENTS.md）。运营文案（隐私政策/服务条款/站点公告）与测试素材不参与索引——前者在 `docs/site/`，后者在 `tests/Fixtures/rag_tools/`。

## 知识库文档规范

`knowledge/` 直接存放检索正文（按主题分目录），`rag:build` 递归索引其中所有 `.md` / `.txt` / `.log` 文件。

- **检索切块单元**：每个 `## ` 二级标题作为一个检索单元（切块），标题权重高于正文，标题应精准包含检索关键词。**硬约束**：没有 `## ` 标题的文件整体成为一个 chunk——大表格/长清单类文件（如版本对应表）必须手工按主题段加 `## ` 分段，否则一个平均向量要代表全文，语义召回与命中后的上下文截取同时失效（2026-09 治理前 `Minecraft版本.txt` 17KB 仅 1 块即此成因）。
- **内容净化**：文档来源于 Markdown，索引前应保持纯净，剔除非标准容器（如 VitePress `::: info`）、HTML 标签及大图链接等干扰字符。
- **排错优化**：引用具体错误类名、Mod ID、配置键名或崩溃堆栈关键字时检索命中率最高。
- **目录注册**：所有新增的一级知识目录必须在 `RagSearch::TOPIC_DESCRIPTIONS` 中登记定性描述（禁写会漂移的数量），否则被 `topic descriptions cover all knowledge directories` 双向一致性测试拦截。
- **内容取舍**：只收模型不具备的增量事实（驱动/芯片与渲染器的具体搭配、具体版本号门槛、反直觉的行为校正）；模型已知的通用常识（OOM 加 -Xmx、NoSuchMethodError 换前置等）与 `patterns/` 诊断卡重复的条目不单独收录，需要补签名时并入对应诊断卡。

## 使用与配置

数据库路径由 `Config.inc.php` 的 `ai.mcp.rag.db` 指定（相对项目根，默认 `rag/index.db`）；`RAG_DB_PATH` 环境变量仅作开发/测试覆盖。

### 1. 配置向量与知识库

在 `.env` 或 `Config.inc.php` 中配置：

```php
'ai' => [
    'agent' => ['enabled' => true],
    'mcp' => [
        'rag' => [
            'url'       => 'http://127.0.0.1:9501/rag',
            'db'        => 'rag/index.db',
            'authToken' => '',  // 设置后需携带 Bearer token
        ],
    ],
    // 可选：启用向量检索（配置 AI_RAG_PROVIDERS 环境变量或在配置中指定）
],
```

### 2. 构建与重建索引

```bash
# 命令行全量构建
php bin/hyperf.php rag:build

# 管理后台异步触发重建
curl -X POST https://api.logshare.cn/v1/admin/rag/build \
  -H "Authorization: Bearer <ADMIN_TOKEN>"

# 查询构建状态与进度
curl https://api.logshare.cn/v1/admin/rag/build/status \
  -H "Authorization: Bearer <ADMIN_TOKEN>"
```

### 3. 检索策略

双路召回 → 融合 → （可选）精排 → 截断到 k：

- **向量语义召回**：Embedding 模型计算 Query 向量，对 SQLite `doc_embeddings` 全库 packed float32 算余弦取 top-pool；topic 约束下推到召回 SQL。
- **词法全文召回**：FTS5 BM25（标题权重 10、正文 1）；未命中的自然语言关键词走 LIKE 兜底。
- **RRF 融合（`ai.rag.rerank.enabled=true` 时）**：`score = Σ 路权重 / (16 + rank)`，语义路权重 1.2；平局按「余弦降序 → BM25 升序 → 键名字典序」三级裁决（不依赖插入顺序）；候选池按 `rerank.maxCandidates` 扩量，进入精排池前施加同源配额 3（防单文件多小节垄断）。
- **精排（可选）**：`type=http` 走专用 cross-encoder 端点，`type=llm` 复用主分析模型 listwise；任何失败回退 RRF 顺序。
- **rerank 关闭时**：保持既有的「向量优先、词法补足」截断合并——该分支与历史实现逐字节一致。
- **上下文截取与平滑**：短正文（≤ 1600 字符）完整返回；长正文围绕命中核心按句子边界向前向后截取上下文窗口（约 800 字符）。

## 管理端点（CMS）

- `GET /v1/admin/rag/stats`：知识库统计（块数、向量数、各主题文件统计）。
- `GET /v1/admin/rag/topics`：获取所有主题目录。
- `GET /v1/admin/rag/docs`：按主题或关键词列出文档文件。
- `GET /v1/admin/rag/docs/content`：读取指定文档原始文本。
- `POST /v1/admin/rag/docs/save`：新增或保存文档正文。
- `POST /v1/admin/rag/docs/upload`：上传文档附件（支持 `.md` / `.txt`，严格防路径穿越）。
- `DELETE /v1/admin/rag/docs`：删除指定文档。
- `POST /v1/admin/rag/search`：管理端召回诊断工具（可对比纯 FTS 与混合检索打分）。

## 测试

```bash
php vendor/bin/pest tests/Unit/RagSearchTest.php   # 词法/topic 契约
php vendor/bin/pest tests/Unit/RagFusionTest.php   # RRF 融合与精排接线（纯计算，进 CI）
```

召回质量验收口径为金标集离线评测（不进 CI，依赖在线 embedding）：

```bash
php scripts/rag_eval.php                    # base（改动前）vs k16w12q3（现码）A/B
php scripts/rag_eval.php --variant=all      # 全变体阶梯；--offline 只吃查询向量缓存
```

指标含分档 hit@1/3/5、MRR、单源垄断度与向量 top1 存活率；金标集在 `tests/Fixtures/rag_gold_set.json`（signature = 报错摘要原文防回退，paraphrase = 口语化描述考验语义通道）。知识库内容或排序逻辑改动后须复跑，paraphrase hit@5 回退即失败（exit 1）。

