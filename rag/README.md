# RAG MCP Server（SQLite FTS5 本地检索）

LogShare 的内置 RAG 实现：基于 SQLite FTS5（BM25）的纯本地知识库检索，零网络、零 embedding。

## 组成

```
rag/
├── RagSearch.php      核心检索类（FTS5 建表 / 切块 / BM25 检索 / LIKE 兜底）
├── build_index.php    CLI：扫描 knowledge/ 构建索引
├── server.php         Streamable HTTP MCP server（JSON-RPC 2.0）
├── index.db           SQLite 索引（构建生成，路径由 ai.mcp.rag.db 指定，勿提交）
├── knowledge/         知识库正文（Markdown / TXT，按主题分目录，见下）
└── public/            文档站静态资源（图片等，不参与索引）
```

## 知识库文档

`knowledge/` 直接存放检索正文（按主题分目录），`build_index.php` 递归索引其中所有 `.md` / `.txt` / `.log` 文件。

- 每个 `## ` 二级标题作为一个检索单元（切块），标题权重高于正文，标题尽量用检索关键词
- `public/` 为文档站静态资源（图片），位于 `knowledge/` 之外，不会被索引
- 文档来源为 Markdown（如 VitePress），索引前应保持纯 Markdown（不含 `:::` 容器、HTML 标签、图片链接等噪声）
- 引用具体错误类名、功能名、报错文本时检索命中率最高

## 使用

数据库路径由 `Config.inc.php` 的 `ai.mcp.rag.db` 指定（相对项目根，默认 `rag/index.db`）；`RAG_DB_PATH` 环境变量仅作开发/测试覆盖。

> **注意**：`server.php` / `build_index.php` 解析路径时会读取项目根 `Config.inc.php`（`ai.mcp.rag.db`）；若文件缺失则回退到默认 `rag/index.db`。RAG 服务与 LogShare 共用同一份配置。

### 1. 构建索引

```bash
php rag/build_index.php                 # 默认 knowledge/ → ai.mcp.rag.db
php rag/build_index.php /path/to/docs   # 指定知识库目录
```

### 2. 启动 server

```bash
php -S 127.0.0.1:8081 rag/server.php
```

仅监听本机即可；若需跨机访问请置于 nginx 反代并加访问控制。

### 3. Docker Compose（推荐）

Compose 已包含 `rag` 服务，随 `docker compose up -d` 一并启动，启动时自动重建索引：

```bash
docker compose -f docker/compose.yaml up -d
```

`Config.inc.php` 默认 `ai.mcp.rag.url = http://rag:8081`（compose 网络内服务名），开箱即用。

### 4. 接入 LogShare

```php
'ai' => [
    'agent' => ['enabled' => true],
    'mcp' => [
        'rag' => [
            'url' => 'http://rag:8081',   // 或本地 http://127.0.0.1:8081
            'db'  => 'rag/index.db',
        ],
    ],
],
```

LogAgent 即可通过 `rag_search(query, k)` 检索知识库。

## 检索策略

- 英文 / 代码 token（错误类名、mod 名）走 FTS5 前缀匹配，BM25 排序，标题权重 10、正文 1
- 中文短语与子串走 LIKE 兜底，与 FTS5 结果合并去重
- 单条结果正文截断至 400 字符返回给模型

## 测试

```bash
php vendor/bin/pest tests/Unit/RagSearchTest.php tests/Unit/RagServerTest.php
```
