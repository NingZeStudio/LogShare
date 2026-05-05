# LogShare-v1 Agent 指南

## 项目定位

PHP 8.4+ API 服务，用于 Minecraft/Hytale 日志分析、存储与分享。基于 Aternos Codex 做日志解析，主存储为 MongoDB，Redis 做缓存。不是框架项目，使用自定义自动加载和简单 switch 路由。

## 关键路径

- **HTTP 入口**：`api/public/index.php`
- **核心启动**：`core/core.php`（注册 Composer autoload + 自定义 `spl_autoload_register`）
- **类文件根目录**：`core/src/`
- **配置目录**：`core/config/*.php`（每个文件定义 `$config` 数组，通过 `Config::Get($name)` 读取）
- **API 端点**：`api/endpoints/*.php`
- **Docker 编排**：`docker/compose.yaml`

## 命名空间与自动加载

自定义 autoloader 将以下命名空间映射到 `core/src/` 下的对应路径：

- `Data\*` → `core/src/Data/`
- `Filter\*` → `core/src/Filter/`
- `Client\*` → `core/src/Client/`
- `Storage\*` → `core/src/Storage/`
- `Printer\*` → `core/src/Printer/`
- `Cache\*` → `core/src/Cache/`

新增顶层命名空间需要在 `core/core.php` 的 autoloader 中注册。

## 开发环境启动

```bash
cd docker && docker compose up -d
```

- 监听端口：`9300`
- 服务：nginx、php-fpm (PHP 8.5 + mongodb/redis 扩展)、mongo、redis
- nginx root：`/web/mclogs/api/public`

## 测试

```bash
./test-api.sh [API_BASE_URL]   # 默认 http://localhost:9300
```

- 这是集成测试脚本，依赖 `jq` 和 `curl`
- 脚本会创建日志并在退出时自动清理
- 没有 PHPUnit 或其他单元测试框架

## 配置注意事项

- `core/config/mongo.php` 被 `.gitignore` 排除，包含 MongoDB 连接信息。新环境需要手动创建或从备份恢复。
- `core/config/urls.php` 中硬编码了生产域名 `logshare.cn` / `api.logshare.cn`，本地开发通常不影响 API 逻辑，但生成短链接/二维码时会用到。
- `core/config/storage.php` 中 `storageId` 当前为 `"m"`（MongoDB），`redisCacheTTL` 为 30 分钟。

## 存储与缓存架构

- **主存储**：MongoDB（`Storage\Mongo`），当前唯一启用存储
- **缓存层**：Redis（`Cache\RedisCache`），新日志会缓存 30 分钟，超过 600KB 的日志跳过 Redis 直接存 MongoDB
- **备用存储**：Filesystem 和 Redis Storage 已实现但配置为禁用

## 路由规则

`api/public/index.php` 使用 `switch ($_SERVER['REQUEST_URI'])` 做简单路由，不是正则框架路由。动态路由（如 `/1/raw/{id}`）用 `preg_match` 捕获后 `require_once` 对应端点文件。

新增端点需在 `index.php` 的 switch 中显式注册。

## 端点列表（当前）

| 端点 | 文件 |
|------|------|
| `POST /1/log` | `api/endpoints/log.php` |
| `POST /1/analyse` | `api/endpoints/analyse.php` |
| `GET /1/errors/rate` | `api/endpoints/rate-error.php` |
| `GET /1/limits` | `api/endpoints/limits.php` |
| `GET /1/filters` | `api/endpoints/filters.php` |
| `GET /1/raw/{id}` | `api/endpoints/raw.php` |
| `GET /1/insights/{id}` | `api/endpoints/insights.php` |
| `GET /1/ai/{id}` | `api/endpoints/ai.php` |
| `POST /1/ai/analyse` | `api/endpoints/ai-analyse.php` |
| `DELETE /1/delete/{id}` | `api/endpoints/delete.php` |

## 约束与惯例

- PHP 最低版本 8.4
- 代码和注释混合中文，保持现有风格即可
- 日志提交前会经过 `core/config/filter.php` 中定义的前置过滤器（Trim、LimitBytes、LimitLines、IPv4/IPv6、Username、AccessToken）
- 响应统一使用 `ApiResponse` 或抛出 `ApiError` 后 `$e->output()`
- 项目 README 声明"不建议私有部署，如需使用请先进行定制化修改"
