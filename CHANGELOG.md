# Changelog

## 1.5.5 — 2026-07-28

### 🔧 改进

- **存储层重构** — MongoDB 和文件系统作为主存储二选一，Redis 仅作可配置缓存层（开关、TTL、大小限制），移除 RedisStorage
- **MongoDB TTL 索引修复** — 索引从无效的 `expires` 字段改为 `created` + `expireAfterSeconds`，滚动删除正确生效
- **FilesystemStorage 完整实现** — 修复 `StorageInterface` 兼容性，存储完整文档（含 token、metadata、source）
- **依赖版本校验** — aternos/codex v4.1.0、codex-minecraft v5.2.0、sherlock v1.1.3 均已最新

### 🐛 修复

- **AccessTokenFilter 引号格式** — 修复 pattern 中多余 `"` 导致的匹配失败
- **ClientIdFilter/SessionTokenFilter 模式修复** — 去除 pattern 中多余的引号，确保正确匹配
- **IPv6 地址过滤优化** — 正确处理 `::ffff:127.0.0.1` 等混合格式
- **各类过滤器 PHP 8.5 兼容** — 避免可变长后顾断言，改用简单模式

### 📖 文档

- README / API.md / AGENTS.md 全面更新
- 生成 Postman Collection（23 个请求）
- 新增 CHANGELOG.md 更新日志

### ⚠️ 弃用

- **`/1/` API 端点** — 标记为已弃用，推荐使用 `/v1/` 替代。`/1/log` 上传接口将在过渡期内保持可用

## 1.5.4 — 2026-07-28

### ✨ 新功能

- **新增 `/v1/` API 端点** — 全部接口新增 `/v1/` 版本路径，原有 `/1/` 端点保持向后兼容，便于生态逐步迁移
- **敏感数据过滤增强** — 新增 6 个过滤器：UUID、IPv6 短格式、Xbox XUID、会话令牌、客户端/设备 ID、Minecraft 坐标
- **Postman 文档生成** — 基于 OpenAPI 3.1 规范自动生成 Postman Collection，可直接导入测试

### 🔧 改进

- **OOP 架构重构** — 代码目录扁平化，文件命名规范化，提升可维护性
- **测试框架搭建** — 引入 PestPHP 测试框架，覆盖过滤器、Handler 和 API 集成测试（47 个测试通过）
- **`safePregReplace` 安全包装** — 所有过滤器统一使用安全正则替换，避免 PHP 8.5 兼容性问题
- **OpenAPI 规范更新至 1.5.4** — 完整描述全部 20+ 端点，标记 `/1/` 为已弃用

### 🐛 修复

- **AccessTokenFilter 引号格式** — 修复 pattern 中多余 `"` 导致的匹配失败
- **ClientIdFilter/SessionTokenFilter 模式修复** — 去除 pattern 中多余的引号，确保正确匹配
- **IPv6 地址过滤优化** — 正确处理 `::ffff:127.0.0.1` 等混合格式
- **各类过滤器 PHP 8.5 兼容** — 避免可变长后顾断言，改用简单模式

### ⚠️ 弃用

- **`/1/` API 端点** — 标记为已弃用，推荐使用 `/v1/` 替代。`/1/log` 上传接口将在过渡期内保持可用