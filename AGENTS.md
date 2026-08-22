# LogShare — AGENTS.md

## Overview

Minecraft / Hytale log analysis and sharing platform (v1.7.0-beta.1). Hyperf 3.2 (Swoole 6.2 resident + coroutine) app with `bin/hyperf.php` entrypoint, `app/` classes under the `App\` namespace, and `Config.inc.php` (business config) + `config/autoload/` (framework config) at root.

## Entrypoint & routing

`bin/hyperf.php` → Hyperf `Application` → annotation-based routing.  
Routes are declared on `App\Controller\*` classes via `#[Controller(prefix: ...)]` + `#[GetMapping]/#[PostMapping]/#[DeleteMapping]/#[RequestMapping]`. Register a new endpoint by adding a method to a Controller.

Both `/1/` (deprecated) and `/v1/` paths share one Controller via the `/{version:v?1}` prefix regex; `AbstractController::apiPrefix()` reads the request path to return the matching `raw` URL.

## Commands

```bash
composer install                  # vendor/ (gitignored)
composer test                     # Pest suite (php vendor/bin/pest on Termux)
composer test:architecture        # Pest architecture rules
composer stan                     # PHPStan level 5
php bin/hyperf.php start          # start the Swoole HTTP server (9501, RAG served on /rag)
php bin/hyperf.php rag:build      # (re)build the RAG SQLite FTS5 index
```

`composer stan` runs `phpstan analyse app --level=5`. `composer cs-fix` references `php-cs-fixer`, which is not installed and has no config; there is no enforced formatter.

`phpstan.neon` (and Pest coverage) **exclude** `app/Data/*`, `app/Cache/*`, `app/Client/*`, `app/Storage/*` — those dirs are not type-checked or coverage-measured. `composer test:coverage` writes `coverage/clover.xml`.

CI runs in `.github/workflows/ci.yaml`. Tests auto-create `Config.inc.php` from `Config.inc.example.php` if missing (`tests/bootstrap.php`).

**Release:** `.github/workflows/release.yaml` publishes a GitHub Release when a commit to `main` starts with `[Build]` (or via manual `workflow_dispatch`). The version + release notes are read from `CHANGELOG.md` — add a new `## x.y.z — date` entry at the top, bump the version in `README.md`/`AGENTS.md`/`MCPClient.php`, then commit with `[Build]` prefix. The tag is `v{x.y.z}`.

> **Termux note:** run `PHPSTAN_TURBO=0 composer stan` — PHPStan's turbo extension is not available on Termux (needs glibc). CI on Ubuntu is unaffected.
>
> **Local runtime:** Swoole is compiled locally (`extension=swoole.so` in `conf.d/swoole.ini`, patched with `patchelf --add-needed libc++_shared.so`). No ext-redis on Termux — Redis degrades gracefully (`class_exists` pre-check). MariaDB runs via `mariadbd`.

## Config

Two layers:

- **Business config** — `Config.inc.php` (gitignored, copy `Config.inc.example.php`), read by `App\Config::Get('section')`. Auto-loaded on first call via `core.php`.
- **Framework config** — `config/autoload/*.php` (Hyperf): `server.php` (ports), `databases.php` (MariaDB), `exceptions.php`, `middlewares.php`, `annotations.php`, etc.

Environment overrides in `App\Config::applyEnvironmentOverrides()`: `REDIS_HOST`, `REDIS_PORT`, `REDIS_TIMEOUT`, `AI_API_KEYS` (comma-separated), `AI_BASE_URL`, `AI_MODEL`. MariaDB connection is overridden via `DB_*` envs in `databases.php`.

**Do not change `id.characters`** — it will break all existing log IDs.

## Storage & cache

- MariaDB (`s` prefix, `App\Storage\MariaDbStorage` via `hyperf/database`) ↔ Filesystem (`f` prefix), selected via `storage.storageId`.
- MariaDB schema: `logs` / `log_files` / `log_metadata` (see `docker/mariadb-init.sql`). `Get()` uses `includeContent` projection to skip large file bodies.
- A log id may hold multiple files: primary content in `data` plus additional files in `files: [{name, data, size}]`.
- Multi-file upload via `POST /v1/log` JSON `files` array; `.zip` entries are expanded (`UploadParser`, path-traversal + zip-bomb protected). Limits under `storage.uploadFiles` (200 files / 12MB total).
- Redis is an optional cache layer (`cache.enabled`), with TTL and maxSize config. Multi-file logs exceeding `cache.maxSize` are skipped.
- TTL cleanup: both storages implement `CleanupExpired()`; `Log::renew()` triggers a probabilistic (1%) cleanup sweep.

## Deobfuscation (SpinYarn)

- Log deobfuscation uses the **SpinYarn PHP extension** (`App\Client\SpinYarnClient`), replacing the retired `aternos/sherlock` dependency.
- `Log::deobfuscateContent()` detects the log type (Vanilla → `vanilla`, Fabric → `yarn`) and version, then calls `SpinYarnClient::deobfuscate()`. When the extension is not loaded, it degrades to null and the log passes through unchanged.
- The extension handle is process-level (`static`), reused across requests — this is the whole point of the resident-process migration (avoids ~110ms mapping reload per request).
- Config under `spinyarn` (see `Config.inc.example.php`): `mappings_dir` (relative `mappings` = `./mappings`), `cache_max_entries/high_watermark/low_watermark`. SpinYarn v1.0.0-pre.2+ has **no `auto_download`** — it only parses; mapping files must already be present locally.
- The extension is built in `docker/hyperf.Dockerfile` (multi-stage: Rust C ABI lib + phpize-built `spinyarn.so`, cloned at tag `v1.0.0-pre.2`); mappings live in `./mappings` (Yarn `*.tiny.gz` + `vanilla/*.txt`, tracked via **Git LFS**). Generate/refresh them with `scripts/download_mappings.sh` + `scripts/download_vanilla_mappings.py`; Docker bind-mounts the host `./mappings` at `/app/mappings`.

## Namespaces

All source classes live under `App\` PSR-4 (`composer.json` maps `App\` → `app/`):

- `App\Controller\*` — HTTP controllers (extend `AbstractController`)
- `App\Storage\*` — `MariaDbStorage` / `FilesystemStorage`
- `App\Filter\*` — pre-filter redaction chain
- `App\Data\*` — `Token`, `MetadataEntry`
- `App\Client\*` — `AIClient`, `MCPClient`, `RedisClient`, `SpinYarnClient`
- `App\Agent\*` — `LogAgent` (tool loop)
- `App\Rag\*` — `RagSearch` (SQLite FTS5)
- `App\Cache\*` — `RedisCache` (optional cache layer) + `CacheInterface`
- `App\Command\*` — `RagBuildCommand` (`rag:build` Hyperf command)
- `App\Middleware\*` — `CorsMiddleware`, `RateLimitMiddleware` (global HTTP middleware)
- `App\Exception\Handler\*` — exception handlers
- Top-level `App\`: `Config`, `Log`, `Id`, `ApiError`, `ApiResponse`, `ContentParser`, `UploadParser`, `Detective`

Reference classes as `\App\Foo` / `App\Sub\Foo`; never prefix `LogShare\`.

## ID format

7 characters: 1 storage-prefix char (checksum-encoded, `s`=MariaDB / `f`=Filesystem) + 6 random chars from `id.characters`. See `app/Id.php` for encoding.

## Request handling conventions

Controllers extend `App\Controller\AbstractController`, which provides:
- `parseContent()`, `validateContentExists()`
- `respondSuccess()`, `respondJson()`, `respondError()`, `respondText()` (return PSR-7 responses)
- `apiPrefix()`, `authorizationHeader()`

**Controllers must not access `$_SERVER`, `$_GET`, or `$_POST` directly** (enforced by architecture test). Use `RequestInterface` (injected) and `ContentParser`.

`ApiError` is thrown for expected errors; `App\Exception\Handler\ApiExceptionHandler` renders it as JSON with the correct status code.

Global HTTP middleware (`config/autoload/middlewares.php`): `CorsMiddleware` then `RateLimitMiddleware`. Rate limiting is Redis `INCR`+`EXPIRE` keyed by `IP + method + path` (config `rateLimit`, default 36000/60s) and **fails open** when Redis is unavailable.

## Pre-filters

Applied before storage. Configured in `Config.inc.php` under `filter.pre`:
- Trim, LimitBytes (10MB), LimitLines (50K) — the two `Limit*` filters **reject** oversized input (400) rather than truncating
- IPv4, IPv6, IPv6Short, UUID, XUID, SessionToken, ClientId, Coordinate, Username, AccessToken redaction

## Content parsing

`ContentParser` accepts `application/x-www-form-urlencoded` and `application/json`. Supports gzip/deflate `Content-Encoding`. Extracts `content`, `metadata[]`, `source`, and `files[]` fields from JSON. Raw file lists are normalized/expanded by `UploadParser`.

## AI / LogAgent

When `ai.agent.enabled` is true, `/v1/ai/*` routes run the model-driven tool loop (`App\Agent\LogAgent`):

- `App\Client\MCPClient` — lightweight Streamable-HTTP MCP client (curl + JSON-RPC, zero deps). Used for `web_search_exa` (Exa hosted endpoint) and `rag_search`.
- `App\Rag\RagSearch` + `App\Controller\RagController` — built-in RAG MCP server (SQLite FTS5 BM25), hosted by Hyperf on the main `http` server under the `/rag` path, MCP JSON-RPC 2.0 protocol. DB path from `ai.mcp.rag.db` (default `rag/index.db`); `RAG_DB_PATH` env overrides. Build index via `php bin/hyperf.php rag:build`.
- `App\Client\AIClient::streamChat()` — streaming LLM request via curl (coroutine-hooked by `SWOOLE_HOOK_ALL`), multi-key rotation; parses `content`, `reasoning_content`, `tool_calls` deltas.
- SSE is written via `Hyperf\Engine\Http\EventStream`; the stream handle is stored in `Hyperf\Context\Context` (coroutine-scoped), **never** in a static property.
- Session-scoped file tools `list_log_files` / `read_log_file` operate only on the bound log id (`logId`), so the agent cannot read other logs.
- SSE contract: `event: status` with `type` = thinking / tool / tool_result / limit, plus legacy `data:` content deltas and `event: done`. See `API.md`.

## Docker

```bash
docker compose -f docker/compose.yaml up -d
```

- nginx reverse-proxies `9300` → Hyperf `9501` (SSE-friendly: `proxy_buffering off`, `proxy_read_timeout 300s`)
- `hyperf` service — resident process built by `docker/hyperf.Dockerfile` (Swoole 6.2 + SpinYarn + pdo_mysql + redis; project code + vendor baked into the image via `composer install`); serves RAG on the main HTTP server under `/rag`
- `mariadb:11` (schema auto-created by `docker/mariadb-init.sql`) + `redis:7-alpine`
- named volumes: `mariadb-data`, `redis-data`; host `./mappings` is bind-mounted into `hyperf` at `/app/mappings`

## Constraints

- Requires PHP 8.4+, ext-json, ext-zlib, ext-mbstring, ext-pdo_mysql; Swoole 6.2 (resident server). SpinYarn deobfuscation requires the optional `spinyarn` extension (degrades gracefully when absent).
- MariaDB + Redis hostnames come from `DB_*` / `REDIS_*` env (framework config) — see `config/autoload/databases.php`.
- Max upload: nginx 210MB, app 10MB (`maxLength`).
