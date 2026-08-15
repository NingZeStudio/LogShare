# RAG MCP Server（SQLite FTS5 本地检索）

LogShare 的内置 RAG 实现：基于 SQLite FTS5（BM25）的纯本地知识库检索，零网络、零 embedding。

## 组成

```
rag/
├── RagSearch.php      核心检索类（FTS5 建表 / 切块 / BM25 检索 / LIKE 兜底）
├── build_index.php    CLI：扫描 knowledge/ 构建索引
├── server.php         Streamable HTTP MCP server（JSON-RPC 2.0）
├── index.db           SQLite 索引（构建生成，勿提交）
└── knowledge/         静态知识库文档（Markdown / TXT）
```

## 使用

### 1. 构建索引

```bash
php rag/build_index.php                 # 默认 knowledge/ → index.db
php rag/build_index.php /path/to/docs   # 指定知识库目录
RAG_DB_PATH=/var/rag/index.db php rag/build_index.php
```

### 2. 启动 server

```bash
php -S 127.0.0.1:8081 rag/server.php
```

仅监听本机即可；若需跨机访问请置于 nginx 反代并加访问控制。

### 3. 接入 LogShare

在 `Config.inc.php` 中启用 RAG 工具：

```php
'ai' => [
    'agent' => ['enabled' => true],
    'mcp' => [
        'rag' => ['url' => 'http://127.0.0.1:8081'],
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
