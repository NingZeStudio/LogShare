# RAG 检索（SQLite FTS5 本地知识库）

LogShare 的内置 RAG 实现：基于 SQLite FTS5（BM25）的纯本地知识库检索，可选的 bge-m3 向量召回 + bge-reranker-v2-m3 精排增强。已整合进 Hyperf 进程，作为 MCP 服务在主 server 的 `/rag` 路径承载（JSON-RPC 2.0 协议）。该端点默认仅允许本机回环调用；通过反向代理或外部 MCP 客户端访问时需配置 `ai.mcp.rag.authToken` 并携带 Bearer Token。

## 组成

```
app/Rag/
└── RagSearch.php              核心检索类（FTS5 建表 / 切块 / BM25 检索 / LIKE 兜底）
app/Controller/
└── RagController.php          Streamable HTTP MCP server（JSON-RPC 2.0，主 server /rag 路径）
app/Command/
└── RagBuildCommand.php        建索引命令（php bin/hyperf.php rag:build）
rag/
├── index.db                   SQLite 索引（构建生成，路径由 ai.mcp.rag.db 指定，勿提交）
├── knowledge/                 知识库正文（Markdown / TXT，按主题分目录，见下）
└── public/                    文档站静态资源（图片等，不参与索引）
```

## 知识库文档

`knowledge/` 直接存放检索正文（按主题分目录），`rag:build` 递归索引其中所有 `.md` / `.txt` / `.log` 文件。

- 每个 `## ` 二级标题作为一个检索单元（切块），标题权重高于正文，标题尽量用检索关键词
- `public/` 为文档站静态资源（图片），位于 `knowledge/` 之外，不会被索引
- 文档来源为 Markdown（如 VitePress），索引前应保持纯 Markdown（不含 `:::` 容器、HTML 标签、图片链接等噪声）
- 引用具体错误类名、功能名、报错文本时检索命中率最高

## 使用

数据库路径由 `Config.inc.php` 的 `ai.mcp.rag.db` 指定（相对项目根，默认 `rag/index.db`）；`RAG_DB_PATH` 环境变量仅作开发/测试覆盖。

### 1. 构建索引

```bash
php bin/hyperf.php rag:build
```

### 2. 启动 RAG 服务

RAG 已整合进 Hyperf 主进程，随 `php bin/hyperf.php start` 一并启动，MCP 端点在主 server 的 `/rag` 路径。无需单独启动。

### 3. 接入 LogShare

```php
'ai' => [
    'agent' => ['enabled' => true],
    'mcp' => [
        'rag' => [
            'url' => 'http://127.0.0.1:9501/rag',
            'db'  => 'rag/index.db',
            'authToken' => '',  // 设置后需携带 Bearer token
        ],
    ],
],
```

LogAgent 即可通过 `rag_search(query, k)` 检索知识库。当 `authToken` 不为空时，Agent 会自动携带 `Authorization: Bearer <authToken>` 头。

## 检索策略

- 英文 / 代码 token（错误类名、mod 名）走 FTS5 前缀匹配，BM25 排序，标题权重 10、正文 1
- 中文短语与子串走 LIKE 兜底，与 FTS5 结果合并去重
- 短正文（≤ 1600 字符）整段返回；长正文围绕命中词按句子边界提取片段（向前/后各约 800 字符），命中词居中，无命中时返回开头片段

## 测试

```bash
php vendor/bin/pest tests/Unit/RagSearchTest.php
```
