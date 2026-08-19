# LogShare — AGENTS.md

## Overview

Minecraft / Hytale log analysis and sharing platform (v1.6.0). Monolithic PHP 8.4+ app with `index.php` entrypoint, `src/` classes, and `Config.inc.php` at root.

## Entrypoint & routing

`index.php` → `core.php` bootstrap → `Router::dispatch()`.  
Routes live in `src/Router.php::getRoutes()` as `[METHOD, path, HandlerClass, [rate_limit, window]]`.  
Register new endpoints by adding a route row and creating a `src/Handler/` class.

Both `/1/` (deprecated) and `/v1/` paths coexist. `LogHandler` detects the request prefix to return the correct `raw` URL.

## Commands

```bash
composer install                  # vendor/ (gitignored)
composer test                     # Pest suite
composer test:architecture        # Pest architecture rules
composer stan                     # PHPStan level 5
```

`composer stan` runs `phpstan analyse src --level=5`, which **overrides** `phpstan.neon`'s `rag` path — `rag/` is only analysed by running `vendor/bin/phpstan` directly. `composer cs-fix` is defined but references `php-cs-fixer`, which is not installed and has no config; there is no enforced formatter.

CI runs in `.github/workflows/ci.yaml` (tests on PHP 8.4/8.5, PHPStan, architecture tests, docker build). Tests auto-create `Config.inc.php` from `Config.inc.example.php` if missing (`tests/bootstrap.php`).

**Release:** `.github/workflows/release.yaml` publishes a GitHub Release when a commit to `main` starts with `[Build]` (or via manual `workflow_dispatch`). The version + release notes are read from `CHANGELOG.md` — add a new `## x.y.z — date` entry at the top, bump the version in `README.md`/`AGENTS.md`/`MCPClient.php`, then commit with `[Build]` prefix. The tag is `v{x.y.z}`.

> **Termux note:** run `PHPSTAN_TURBO=0 composer stan` — PHPStan's turbo extension is not available on Termux (needs glibc). CI on Ubuntu is unaffected.
>
> **Local tests:** `tests/bootstrap.php` class-aliases `Tests\Mocks\RedisMock` / `Tests\Mocks\MongoDBMock` when the `redis`/`mongodb` extensions are absent, so the suite runs without real services (Termux has neither extension). CI instead runs against real `mongo:7` + `redis:7-alpine` services.

## Config

`Config::Get('name')` auto-loads `Config.inc.php` on first call. The file returns a single array keyed by section. No `.env`, no framework config.

Environment overrides applied in `Config::load()` (see `src/Config.php`): `MONGODB_URI`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_TIMEOUT`, `AI_API_KEYS` (comma-separated), `AI_BASE_URL`, `AI_MODEL`.

`Config.inc.php` is gitignored. Copy `Config.inc.example.php` to create it.

**Do not change `id.characters`** — it will break all existing log IDs.

## Storage & cache

- MongoDB (`m` prefix) ↔ Filesystem (`f` prefix), selected via `storage.storageId`.
- A log id may hold multiple files: primary content in `data` plus additional files in `files: [{name, data, size}]`.
- Multi-file upload via `POST /v1/log` JSON `files` array; `.zip` entries are expanded (`UploadParser`, path-traversal protected). Limits under `storage.uploadFiles` (200 files / 12MB total).
- Redis is an optional cache layer (`cache.enabled`), with TTL and maxSize config. Multi-file logs exceeding `cache.maxSize` are skipped.
- MongoDB TTL index on `created`, `expireAfterSeconds` = `storageTime` (default 7 days). `renew()` resets TTL.
- Filesystem storage (`f`) has no TTL index; `FilesystemStorage::Renew()` updates the stored `created` field, and `CleanupExpired()` deletes expired files. `Log::renew()` triggers a probabilistic (1%) cleanup sweep.

## Deobfuscation (SpinYarn)

- Log deobfuscation uses the **SpinYarn PHP extension** (`Client\SpinYarnClient`), replacing the retired `aternos/sherlock` dependency.
- `Log::deobfuscateContent()` detects the log type (Vanilla → `vanilla`, Fabric → `yarn`) and version, then calls `SpinYarnClient::deobfuscate()`. When the extension is not loaded, it degrades to null and the log passes through unchanged.
- Config under `spinyarn` (see `Config.inc.example.php`): `mappings_dir`, `auto_download`, `cache_max_entries/high_watermark/low_watermark`.
- The extension is built in `docker/php-fpm.Dockerfile` (multi-stage: Rust C ABI lib + phpize-built `spinyarn.so`); mappings live in `/opt/spinyarn/mappings` (named volume `spinyarn-mappings`), backfilled on demand by `auto_download`.

## Namespaces

Source classes use **legacy global namespaces**, not `LogShare\` (despite `composer.json` mapping both `LogShare\` and `""` to `src/`). Subdirectory classes declare `namespace Handler;`, `namespace Storage;`, `namespace Filter;`, `namespace Data;`, `namespace Client;`, `namespace Agent;`, `namespace Cache;`. Top-level classes (`Config`, `Log`, `Router`, `Id`, `Handler`, `RequestValidator`, `ApiResponse`, …) declare no namespace. Reference classes as `\Handler\Foo` / `\Log`; do not prefix `LogShare\`.

## ID format

7 characters: 1 storage-prefix char (checksum-encoded) + 6 random chars from `id.characters`. See `src/Id.php:97` for encoding.

## Request handling conventions

Handlers extend `\Handler` base class, which provides:
- `validateMethod()`, `extractId()`, `extractIds()`
- `parseContent()`, `validateContentExists()`
- `respondSuccess()`, `respondJson()`, `respondError()`, `respondText()`

**Handlers must not access `$_SERVER`, `$_GET`, or `$_POST` directly** (enforced by architecture test). Use the base class helpers and `ContentParser` instead.

`RequestValidator` is a global utility that does use `$_SERVER` — this is allowed.

## Pre-filters

Applied before storage. Configured in `Config.inc.php` under `filter.pre`:
- Trim, LimitBytes (10MB), LimitLines (50K) — the two `Limit*` filters **reject** oversized input (400) rather than truncating
- IPv4, IPv6, IPv6Short, UUID, XUID, SessionToken, ClientId, Coordinate, Username, AccessToken redaction

## Content parsing

`ContentParser` accepts `application/x-www-form-urlencoded` and `application/json`. Supports gzip/deflate `Content-Encoding`. Extracts `content`, `metadata[]`, `source`, and `files[]` fields from JSON. Raw file lists are normalized/expanded by `UploadParser`.

## AI / LogAgent

When `ai.agent.enabled` is true, `/v1/ai/*` routes run the model-driven tool loop (`Agent\LogAgent`):

- `Client\MCPClient` — lightweight Streamable-HTTP MCP client (curl + JSON-RPC, zero deps). Used for `web_search_exa` (Exa hosted endpoint) and `rag_search`.
- `rag/` — built-in RAG MCP server (`server.php`), pure local SQLite FTS5 (BM25) retrieval over static knowledge docs (`rag/knowledge/`). DB path comes from `ai.mcp.rag.db` (default `rag/index.db`); `RAG_DB_PATH` env overrides for dev/tests. Build index via `php rag/build_index.php`. Docker Compose starts it automatically (re-indexes on boot), reachable at `http://rag:8081`.
- `Client\AIClient::streamChat()` — low-level streaming LLM request with multi-key rotation; parses `content`, `reasoning_content` and `tool_calls` from stream deltas.
- Session-scoped file tools `list_log_files` / `read_log_file` operate only on the bound log id (`logId`), so the agent cannot read other logs.
- SSE contract: `event: status` with `type` = thinking / tool / tool_result / limit, plus legacy `data:` content deltas and `event: done`. See `API.md`.

## Docker

```bash
docker compose -f docker/compose.yaml up -d
```

- nginx listens on `9300`, root at `/web/mclogs`, `client_max_body_size 210m`
- php-fpm 8.5 with `post_max_size = 16M`, built by `docker/php-fpm.Dockerfile` (mongodb, redis, spinyarn extensions)
- mongo:latest, redis:7-alpine
- rag (SQLite FTS5) + spinyarn-mappings named volumes

## Constraints

- Requires PHP 8.4+, ext-json, ext-zlib, ext-mbstring, mongodb, redis. SpinYarn deobfuscation requires the optional `spinyarn` extension (degrades gracefully when absent).
- MongoDB + Redis hostnames set in `Config.inc.php`.
- Max upload: nginx 210MB, PHP 16MB, app 10MB (`maxLength`).
