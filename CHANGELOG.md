# Changelog

## v1.5.3

### OOP 重构

- 所有端点改为 `src/Handler/` 下 OOP 类，继承 `Handler` 基类，统一 `handle()` 方法
- `Router` 改为 `class_exists()` 后 `new $class()->handle()` 分发，废弃 `require` 加载
- 路由定义内聚到 `Router::getRoutes()` 私有方法，移除 `api/routes.php`
- 入口从 `api/public/index.php` 移至根目录 `index.php`，`nginx root` 指向项目根

### 目录清理

- 删除 `api/` 目录（`public/`、`endpoints/`、`routes.php` 全部移除或合并）
- 删除 `api/endpoints/` 下 9 个过程式 PHP 文件

### 文件命名规范化

- Storage 实现类统一加 `Storage` 后缀：`FilesystemStorage`、`MongoStorage`、`RedisStorage`
- AI 相关类统一全大写：`AIClient`、`AIHandler`、`AIAnalyseHandler`
- IP 相关类统一全大写：`Filter\Pre\IP`

## v1.5.2

### 架构重构

- 替换手写 switch+preg_match 路由为 `Router` 类，支持 `{id}` 路径参数、Redis 限速、路由停用
- 所有路由统一注册在 `api/routes.php`，每行一条 `[method, path, handler, rate_limit]`
- 配置系统重构：移除 `config/` 目录下 10 个分散的 PHP 文件，合并为根目录 `Config.inc.php`
- `Config` 类改为启动时一次性加载，不再每次调用读文件
- 目录结构扁平化：`core/src/` → `src/`，`core/config/` → `Config.inc.php`，`core/core.php` → 根目录 `core.php`

### API 变更

- 删除端点合并：`DELETE /1/delete/{id}` 移除，统一走 `DELETE /1/log/{id}`
- `api/endpoints/delete.php` 移除，删除逻辑合并到 `log.php`

### AI 分析优化

- 移除 System Prompt，直接发送用户消息 `"这怎么回事" + 日志内容`
- AI 输出不再限制 JSON 格式，自由输出 Markdown
- 取消日志截断，发送完整内容
- 新增 CURL 连接超时 15 秒，缩短总超时到 120 秒
- HTTP 429 提前检测，快速切换 API Key
- 缓存 TTL 调整为 30 分钟，缓存命中时通过 SSE 回放

### 限速

- 所有路由统一限速为 36000 次/分钟，保留限速框架但不再成为瓶颈

### 文档

- 新增 `AGENTS.md`，记录项目架构、命令、约束，帮助 AI 工具快速上手
- 重写 `README.md`，更新为完整的项目文档
- 新增 `Config.inc.example.php` 配置模板

### 其他

- 更新 `.gitignore`，移除已删除路径
- 清理无用文件：`API.md`、`APIDocs.md`、`test-api.sh`
