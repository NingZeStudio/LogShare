# LogShare 静态知识库

本目录存放供 `rag_search` 检索的静态文档（Markdown / TXT）。

## 检索机制

- 索引由 `php rag/build_index.php` 构建（SQLite FTS5，BM25 排序）
- 按 `## ` 标题切块，标题权重高于正文
- 英文/代码词走 FTS5 前缀匹配；中文短语走 LIKE 兜底，均可命中
- 零网络、零 embedding、单文件数据库

## 内容约定

- 每个 `## ` 小节作为一个检索单元，标题尽量用检索关键词
- 建议按主题拆分文档（如 `oom.md`、`mod-compat.md`、`crash-causes.md`）
- 文档引用具体错误类名、mod 名称、报错文本时检索命中率最高

## 示例

下面是一个示例章节，构建索引后可用 `rag_search("OutOfMemoryError")` 命中：

## java.lang.OutOfMemoryError 排查

OutOfMemoryError 表示 Java 堆内存耗尽。常见原因：

- 服务器分配内存不足：检查启动脚本 `-Xmx` 参数（如 `-Xmx2G`）
- 内存泄漏：大量未释放的 TileEntity 或实体引用
- 加载过多大型 mod 或世界区块未卸载

排查步骤：
1. 查看启动参数确认 `-Xmx` 上限
2. 观察 TPS 与 GC 日志，确认是否为持续增长型泄漏
3. 使用 `-XX:+HeapDumpOnOutOfMemoryError` 导出堆转储分析

## 服务器启动缓慢

服务器启动慢通常由以下因素引起：

- 加载大量 mod 与数据包
- 世界存档过大（区块、实体）
- 磁盘 I/O 瓶颈

优化建议：分配更高 `-Xmx`、关闭不需要的 mod、使用 SSD 存储。
