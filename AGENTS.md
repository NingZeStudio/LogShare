# LogShare — AGENTS.md

## Overview

Minecraft / Hytale log analysis and sharing platform (v1.5.4). Fork-like evolution of Aternos Codex / mclogs.  
Monolithic PHP 8.4+ app: `index.php` HTTP entrypoint, `src/` + `Config.inc.php` at root.

## Project structure

```
index.php                HTTP entrypoint
core.php                 Bootstrap + PSR-4-style autoloader
src/                     Application classes (namespaced + global)
Config.inc.php           All configuration in one file (gitignored; use Config.inc.example.php as template)
Config.inc.example.php   Config template with safe defaults
openapi.yaml             OpenAPI 3.1 specification
postman_collection.json  Postman collection
tests/                   Pest test suite
docker/                  compose.yaml + nginx/php configs
storage/logs/            Filesystem storage (disabled by default)
```

`CORE_PATH` is the project root (parent of `index.php`, `src/`, `Config.inc.php`).

## Entrypoint & routing

`index.php` requires `core.php`, then calls `Router::dispatch()`.  
All routes defined in `Router::getRoutes()` — add a row to register a new endpoint and create its `Handler\*` class in `src/Handler/`.

The `Router` class (`src/Router.php`) matches `{method} {path}` patterns, supports `{id}` placeholders, and enforces per-route Redis-based rate limiting. Disable a route by adding its `"METHOD /path"` to the `disabled` array in `getRoutes()`.

Both `/1/log` and `/v1/log` handle POST (create) and DELETE (delete with token auth via `Bearer <token>`). `/1/` endpoints are deprecated but kept for ecosystem migration.

## Autoloader

Single PSR-4-style autoloader in `core.php` maps namespace\Class → `src/Namespace/Class.php`.  
Global classes (`Log`, `Id`, `Config`, `Router`, `Detective`, `ContentParser`, `ApiResponse`, `ApiError`, `RequestValidator`, `Handler`) live in `src/` and are loaded the same way.

## Key features that differ from defaults

- **Config system**: `Config::Get('name')` reads from `Config.inc.php`. The file returns a single array keyed by name. No .env, no framework config. Loaded once at boot in `core.php`.
- **ID format**: 7 chars — 1 storage-prefix char (encoded via checksum) + 6 random chars. See `src/Id.php:97` for encoding logic.
- **Storage**: MongoDB ↔ Filesystem 二选一（prefix `m`/`f`）。Redis 仅为可选缓存层（可开关，配置 TTL/maxSize）。`RedisStorage` 已删除。
- **MongoDB TTL**: 索引建在 `created` 字段上，`expireAfterSeconds` = `storageTime`（默认 7 天）。`renew()` 更新 `created` 重置 TTL。
- **Pre-filters**: Applied on input before storage — Trim, LimitBytes (10MB), LimitLines (50K), IPv4/IPv6/IPv6Short/UUID/XUID/SessionToken/ClientId/Coordinate/Username/AccessToken redaction. Configurable in `Config.inc.php`.
- **Content parsing**: Accepts `application/x-www-form-urlencoded` or `application/json`. Supports gzip/deflate `Content-Encoding`. Extracts `content`, `metadata[]`, and `source` fields from JSON.
- **API response**: Custom `ApiResponse` helper — `::success()`, `::error()`, `::json()`, `::text()`. Always JSON.
- **API versioning**: Simultaneous `/1/` (deprecated) and `/v1/` endpoints. `LogHandler::handleCreate()` detects request path to return appropriate `raw` URL (`/1/raw/` vs `/v1/raw/`). `RequestValidator::extractIds()` supports both prefixes.
- **Docker**: compose.yaml with nginx (port 9300), php-fpm (8.5), mongo, redis. Nginx roots at `/web/mclogs`.
- **Handlers**: Endpoint logic in `src/Handler/` classes extending base `Handler`. Each class has a `handle()` method called by Router.

## Commands

```bash
composer install                  # vendor/ (gitignored)
composer test                     # Pest 测试套件
composer stan                     # PHPStan 静态分析 (level 5)
```

No test/lint/typecheck tooling is configured. The project has no `composer scripts` section, no CI.

## Important constraints

- Requires PHP 8.4+, ext-json, ext-zlib, ext-mbstring, mongodb extension, redis extension.
- MongoDB + Redis must be reachable (hostnames set in config files).
- `Config.inc.php` is gitignored — copy `Config.inc.example.php` to `Config.inc.php` and fill in your values.
- Do not change ID character set (`Config.inc.php` key `id.characters`) — it will break all existing log IDs.
- Max upload: nginx `client_max_body_size 210m`, PHP `post_max_size 16M`, app `maxLength` 10MB.
- README explicitly states: not recommended for private deployment without customization.
