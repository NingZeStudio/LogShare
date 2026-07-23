# LogShare — AGENTS.md

## Overview

Minecraft / Hytale log analysis and sharing platform (v1.5.3). Fork-like evolution of Aternos Codex / mclogs.  
Monolithic PHP 8.4+ app: `index.php` HTTP entrypoint, `src/` + `Config.inc.php` at root.

## Project structure

```
index.php                HTTP entrypoint
core.php                 Bootstrap + PSR-4-style autoloader
src/                     Application classes (namespaced + global)
Config.inc.php           All configuration in one file (gitignored; use Config.inc.example.php as template)
Config.inc.example.php   Config template with safe defaults
docker/                  compose.yaml + nginx/php configs
storage/logs/            Filesystem storage (disabled by default)
```

`CORE_PATH` is the project root (parent of `index.php`, `src/`, `Config.inc.php`).

## Entrypoint & routing

`index.php` requires `core.php`, then calls `Router::dispatch()`.  
All routes defined in `Router::getRoutes()` — add a row to register a new endpoint and create its `Handler\*` class in `src/Handler/`.

The `Router` class (`src/Router.php`) matches `{method} {path}` patterns, supports `{id}` placeholders, and enforces per-route Redis-based rate limiting. Disable a route by adding its `"METHOD /path"` to the `disabled` array in `getRoutes()`.

`/1/log` handles both POST (create) and DELETE (delete with token auth via `Bearer <token>`). The old separate `/1/delete/` route was removed — delete is now at `DELETE /1/log/{id}`.

## Autoloader

Single PSR-4-style autoloader in `core.php` maps namespace\Class → `src/Namespace/Class.php`.  
Global classes (`Log`, `Id`, `Config`, `Router`, `Detective`, `ContentParser`, `ApiResponse`, `ApiError`, `RequestValidator`, `Handler`) live in `src/` and are loaded the same way.

## Key features that differ from defaults

- **Config system**: `Config::Get('name')` reads from `Config.inc.php`. The file returns a single array keyed by name. No .env, no framework config. Loaded once at boot in `core.php`.
- **ID format**: 7 chars — 1 storage-prefix char (encoded via checksum) + 6 random chars. See `src/Id.php:97` for encoding logic.
- **Storage chain**: MongoDB primary (ID prefix `m`), Redis as read cache (TTL 30 min, max 600KB, logs larger bypass Redis). Filesystem and Redis-only storage exist but disabled.
- **Pre-filters**: Applied on input before storage — Trim, LimitBytes (10MB), LimitLines (50K), IPv4/IPv6/Username/AccessToken redaction. Configurable in `Config.inc.php`.
- **Content parsing**: Accepts `application/x-www-form-urlencoded` or `application/json`. Supports gzip/deflate `Content-Encoding`. Extracts `content`, `metadata[]`, and `source` fields from JSON.
- **API response**: Custom `ApiResponse` helper — `::success()`, `::error()`, `::json()`, `::text()`. Always JSON.
- **Docker**: compose.yaml with nginx (port 9300), php-fpm (8.5), mongo, redis. Nginx roots at `/web/mclogs`.
- **Handlers**: Endpoint logic in `src/Handler/` classes extending base `Handler`. Each class has a `handle()` method called by Router.

## Commands

```bash
composer install                  # vendor/ (gitignored)
```

No test/lint/typecheck tooling is configured. The project has no `composer scripts` section, no CI.

## Important constraints

- Requires PHP 8.4+, ext-json, ext-zlib, ext-mbstring, mongodb extension, redis extension.
- MongoDB + Redis must be reachable (hostnames set in config files).
- `Config.inc.php` is gitignored — copy `Config.inc.example.php` to `Config.inc.php` and fill in your values.
- Do not change ID character set (`Config.inc.php` key `id.characters`) — it will break all existing log IDs.
- Max upload: nginx `client_max_body_size 210m`, PHP `post_max_size 16M`, app `maxLength` 10MB.
- README explicitly states: not recommended for private deployment without customization.
