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
├── knowledge/                 知识库正文（Markdown / TXT，按主题分目录，见下）
│   ├── forge/                 Forge 官方文档与常见崩溃解析
│   ├── neoforge/              NeoForge 模组开发与排错文档
│   ├── papermc/               PaperMC / Purpur 服务端配置与优化
│   ├── fabric/                Fabric 模组生态与报错指引
│   ├── mobile_launcher/       移动端启动器（PojavLauncher / FCL 等）特性与渲染排错
│   └── vanilla/               原版崩溃报告与 JVM 异常指引
└── public/                    文档站静态资源（图片等，不参与索引）
```

## 知识库文档规范

`knowledge/` 直接存放检索正文（按主题分目录），`rag:build` 递归索引其中所有 `.md` / `.txt` / `.log` 文件。

- **检索切块单元**：每个 `## ` 二级标题作为一个检索单元（切块），标题权重高于正文，标题应精准包含检索关键词。
- **内容净化**：文档来源于 Markdown，索引前应保持纯净，剔除非标准容器（如 VitePress `::: info`）、HTML 标签及大图链接等干扰字符。
- **排错优化**：引用具体错误类名、Mod ID、配置键名或崩溃堆栈关键字时检索命中率最高。
- **目录注册**：所有新增的一级知识目录必须在 `RagSearch::TOPIC_DESCRIPTIONS` 中登记对应描述，否则会被架构一致性测试拦截。

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

- **向量语义召回（优先）**：若配置了 Embedding 模型，先计算 Query 向量，在 SQLite `embeddings` 表中计算余弦相似度召回 Top K 相关片段。
- **词法全文匹配（FTS5 BM25）**：针对英文 Token、崩溃类名、Mod ID 进行 BM25 打分检索，标题权重 10，正文权重 1。
- **中文子串兜底（LIKE）**：对未命中的自然语言关键词进行 LIKE 兜底，与 FTS5/向量结果合并去重并保持相关性排序。
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
php vendor/bin/pest tests/Unit/RagSearchTest.php
```

