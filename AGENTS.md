# LogShare — AGENTS.md

## Overview

Minecraft / Hytale log analysis and sharing platform (v1.7.2). Hyperf 3.2 (Swoole 6.2 resident + coroutine) app with `bin/hyperf.php` entrypoint, `app/` classes under the `App\` namespace, and `Config.inc.php` (business config) + `config/autoload/` (framework config) at root.

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

`composer stan` runs `phpstan analyse app --level=5`. No formatter is configured.

`phpstan.neon` (and Pest coverage) **exclude** `app/Data/*`, `app/Cache/*`, `app/Client/*`, `app/Storage/*` — those dirs are not type-checked or coverage-measured. `composer test:coverage` writes `coverage/clover.xml`.

CI runs in `.github/workflows/ci.yaml`. Tests auto-create `Config.inc.php` from `Config.inc.example.php` if missing (`tests/bootstrap.php`).

**Release:** `.github/workflows/release.yaml` publishes a GitHub Release when a commit to `main` starts with `[Build]` (or via manual `workflow_dispatch`). The version + release notes are read from `CHANGELOG.md` — add a new `## x.y.z — date` entry at the top, bump the version in `app/Version.php` (`App\Version::VERSION` is the single source; `MCPClient.php`/`RagController.php` read it) and `README.md`, then commit with `[Build]` prefix. The tag is `v{x.y.z}`.

> **Termux note:** run `PHPSTAN_TURBO=0 composer stan` — PHPStan's turbo extension is not available on Termux (needs glibc). CI on Ubuntu is unaffected.
>
> **Local runtime:** Swoole is compiled locally (`extension=swoole.so` in `conf.d/swoole.ini`, patched with `patchelf --add-needed libc++_shared.so`). No ext-redis on Termux — Redis degrades gracefully (`class_exists` pre-check). MariaDB runs via `mariadbd`.

## Config

Two layers:

- **Business config** — `Config.inc.php` (gitignored, copy `Config.inc.example.php`), read by `App\Config::Get('section')`. Auto-loaded on first call via `core.php`.
- **Framework config** — `config/autoload/*.php` (Hyperf): `server.php` (ports), `databases.php` (MariaDB), `exceptions.php`, `middlewares.php`, `annotations.php`, etc.

Environment overrides in `App\Config::applyEnvironmentOverrides()`: `REDIS_HOST`, `REDIS_PORT`, `REDIS_TIMEOUT`, `REDIS_PASSWORD`, `AI_ENABLED`, `AI_API_KEYS` (comma-separated), `AI_BASE_URL`, `AI_MODEL`. MariaDB connection is overridden via `DB_*` envs in `databases.php`.

**Do not change `id.characters`** — it will break all existing log IDs.

## Storage & cache

- MariaDB (`s` prefix, `App\Storage\MariaDbStorage` via `hyperf/database`) ↔ Filesystem (`f` prefix), selected via `storage.storageId`.
- MariaDB schema: `logs` / `log_files` / `log_metadata` (see `docker/mariadb-init.sql`). `Get()` uses `includeContent` projection to skip large file bodies.
- A log id may hold multiple files: primary content in `data` plus additional files in `files: [{name, data, size}]`.
- Multi-file upload via `POST /v1/log` JSON `files` array; `.zip` entries are expanded (`UploadParser`, path-traversal + zip-bomb protected). Limits under `storage.uploadFiles` (200 files / 12MB total).
- Redis is an optional cache layer (`cache.enabled`), with TTL and maxSize config. Multi-file logs exceeding `cache.maxSize` (main content + files combined) are skipped.
- **Deletion tokens are stored as SHA-256 hashes** (MariaDB / filesystem / Redis cache alike); the plaintext token is only ever returned in the upload response. `Token::matches()` compares request tokens by hash with a legacy fallback to plaintext for pre-existing rows.
- Filesystem storage writes a lightweight `{id}.meta.json` alongside each log so metadata queries skip reading the full document; both storages honour `includeContent=false`.
- TTL cleanup: both storages implement `CleanupExpired()`; `Log::renew()` triggers a probabilistic (1%) cleanup sweep.

## Log analysis (Codex)

- Parsing/detection uses **Aternos Codex** (`aternos/codex-minecraft` + `aternos/codex-hytale`). `App\Detective` extends `Aternos\Codex\Detective\Detective`, registering both Minecraft + Hytale detectives; `Log::analyse()` runs Codex on the stored text.
- Analysis results are cached **process-level** in `Log::$analysisJsonCache` (static, size-capped) — first `/insights` hit parses (~seconds), repeats are ~ms. Invalidation is manual; changing Codex parsing logic requires a server restart to take effect.
- `phpstan.neon` carries two deliberate `ignoreErrors`: Codex's `analyse()` lives on `AnalysableLogInterface` while `detect()` returns base `LogInterface` (runtime classes implement both), and `Hyperf\Response::getConnection()` dispatches via `__call`. Don't "fix" these by adding wrappers.

## Deobfuscation (SpinYarn)

- Log deobfuscation uses the **SpinYarn PHP extension** (`App\Client\SpinYarnClient`), replacing the retired `aternos/sherlock` dependency.
- `Log::deobfuscateForStorage()` detects the log type (Vanilla → `vanilla`, Fabric → `yarn`) and version, then calls `SpinYarnClient::deobfuscate()` before storing, so the DB holds deobfuscated text and reads need no further deobfuscation. When the extension is not loaded it degrades to null and the log passes through unchanged.
- The extension handle is process-level (`static`), reused across requests — this is the whole point of the resident-process migration (avoids ~110ms mapping reload per request).
- Config under `spinyarn` (see `Config.inc.example.php`): `mappings_dir` (relative `mappings` = `./mappings`), `cache_max_entries/high_watermark/low_watermark`. SpinYarn v1.0.0+ has **no `auto_download`** — it only parses; mapping files must already be present locally.
- The extension is built in `docker/hyperf.Dockerfile` (multi-stage: Rust C ABI lib + phpize-built `spinyarn.so`, cloned at tag `v1.0.0`); mappings live in `./mappings` (Yarn `*.tiny.gz` + `vanilla/*.txt`, tracked via **Git LFS**). Generate/refresh them with `scripts/download_mappings.sh` + `scripts/download_vanilla_mappings.py`; Docker bind-mounts the host `./mappings` at `/app/mappings`.

## Namespaces

All source classes live under `App\` PSR-4 (`composer.json` maps `App\` → `app/`):

- `App\Controller\*` — HTTP controllers (extend `AbstractController`)
- `App\Storage\*` — `MariaDbStorage` / `FilesystemStorage`
- `App\Filter\*` — pre-filter redaction chain (incl. `Pattern/`)
- `App\Data\*` — `Token`, `MetadataEntry`
- `App\Client\*` — `AIClient`, `MCPClient`, `RedisClient`, `SpinYarnClient`
- `App\Agent\*` — `LogAgent` (tool loop)
- `App\Rag\*` — `RagSearch` (SQLite FTS5)
- `App\Sse\*` — `SseWriter`
- `App\Cache\*` — `RedisCache` (optional cache layer) + `CacheInterface`
- `App\Command\*` — `RagBuildCommand` (`rag:build` Hyperf command)
- `App\Middleware\*` — `CorsMiddleware`, `RateLimitMiddleware` (global HTTP middleware)
- `App\Exception\Handler\*` — exception handlers
- `App\Syslog` — uniform diagnostic logging (`Syslog::error()`); do not use raw `error_log()`
- Top-level `App\`: `Config`, `Log`, `Id`, `Version`, `ApiError`, `ApiResponse`, `ContentParser`, `UploadParser`, `Detective`

Reference classes as `\App\Foo` / `App\Sub\Foo`; never prefix `LogShare\`.

## ID format

7 characters: 1 storage-prefix char (checksum-encoded, `s`=MariaDB / `f`=Filesystem) + 6 random chars from `id.characters`. See `app/Id.php` for encoding.

## Request handling conventions

Controllers extend `App\Controller\AbstractController`, which provides:
- `parseContent()`, `validateContentExists()`
- `respondSuccess()`, `respondJson()`, `respondError()`, `respondText()` (return PSR-7 responses)
- `apiPrefix()`, `authorizationHeader()`

**Controllers must not access `$_SERVER`, `$_GET`, or `$_POST` directly, and must not run raw SQL** (both enforced by architecture tests). Use `RequestInterface` (injected), `ContentParser`, and the Storage classes.

`ApiError` is thrown for expected errors; `App\Exception\Handler\ApiExceptionHandler` renders it as JSON with the correct status code.

Global HTTP middleware (`config/autoload/middlewares.php`): `CorsMiddleware` then `RateLimitMiddleware`. Rate limiting is Redis `INCR`+`EXPIRE` keyed by `IP + method + normalized path` (dynamic resource segments like `/v1/raw/{id}` collapse to `/v1/raw/*`; config `rateLimit`, default 36000/60s) and **fails open** when Redis is unavailable. When behind a reverse proxy, list trusted-proxy IPs in `rateLimit.trustedProxies` so the `X-Real-IP` header is used instead of `remote_addr`.

API changes must be reflected in the maintained API docs: `openapi.yaml`, `postman_collection.json`, and `API.md` — all three are referenced from `README.md`.

## Pre-filters

Applied before storage. Configured in `Config.inc.php` under `filter.pre`:
- Trim, LimitBytes (10MB), LimitLines (50K) — the two `Limit*` filters **reject** oversized input (400) rather than truncating
- Encoding, IPv4, IPv6, IPv6Short, UUID, XUID, SessionToken, ClientId, Coordinate, Username, AccessToken redaction

## Content parsing

`ContentParser` accepts `application/x-www-form-urlencoded` and `application/json`. Supports gzip/deflate `Content-Encoding`. Extracts `content`, `metadata[]`, `source`, and `files[]` fields from JSON. Raw file lists are normalized/expanded by `UploadParser`.

## AI / LogAgent

When `ai.enabled` is false (`AI_ENABLED` env overrides), all `/v1/ai/*` routes return 404 — check this first when debugging missing AI endpoints. When `ai.agent.enabled` is true, those routes run the model-driven tool loop (`App\Agent\LogAgent`):

- `App\Client\MCPClient` — lightweight Streamable-HTTP MCP client (curl + JSON-RPC, zero deps). Used for `web_search_exa` (Exa hosted endpoint) and `rag_search`.
- `App\Rag\RagSearch` + `App\Controller\RagController` — built-in RAG MCP server (SQLite FTS5 BM25), hosted by Hyperf on the main `http` server under the `/rag` path, MCP JSON-RPC 2.0 protocol. DB path from `ai.mcp.rag.db` (default `rag/index.db`); `RAG_DB_PATH` env overrides. Build index via `php bin/hyperf.php rag:build`. Access control: by default the `/rag` endpoint only accepts loopback connections; to expose it via a reverse proxy, set `ai.mcp.rag.authToken` and require `Authorization: Bearer <token>` (LogAgent automatically forwards it). The RAG JSON-RPC request body intentionally has no application-level size limit; this is by design for the MCP transport. Limit individual `rag_search.query` values instead. Knowledge base (`rag/knowledge/`) includes Fabric developer docs, Forge & NeoForge docs (`scripts/download_modloader_docs.sh`), server/proxy admin docs for PaperMC family (Paper/Velocity/Waterfall/Folia/Adventure), Purpur, Glowstone, Geyser and Quilt (`scripts/download_server_docs.sh`), plus hand-distilled Android-launcher issue KBs (`*-issues`, `patterns`, `renderers`); both download scripts run `scripts/clean_knowledge_docs.php` afterwards (frontmatter/MDX/admonition/HTML stripping; meta-file deletion applies to upstream dirs only). Bukkit/Spigot/BungeeCord are NOT indexed — no markdown source exists upstream. Search semantics: strict AND first, degrades to OR (bm25 / matched-term ranking); CJK runs are exploded into bigrams so Chinese multi-word queries never return empty. Semantic enhancement (`ai.rag` section, off by default): when enabled with an independent gateway/key, `rag:build` embeds chunks via bge-m3 and search adds vector recall + bge-reranker-v2-m3 reordering (`App\Rag\SemanticClient`); any API failure degrades silently to lexical-only. Env: `AI_RAG_ENABLED` / `AI_RAG_BASE_URL` / `AI_RAG_API_KEY`.
- `App\Client\AIClient::streamChat()` — streaming LLM request via curl (coroutine-hooked by `SWOOLE_HOOK_ALL`), multi-key rotation; parses `content`, `reasoning_content`, `tool_calls` deltas. Robustness against misbehaving gateways: in-stream `data: {"error":...}` frames (HTTP 200) are surfaced as failures so the next key is tried; SSE lines without the space separator (`data:{...}`) are accepted; if the body parses as a one-shot non-streaming JSON completion (gateway ignored `stream=true`), it is consumed as a single delta instead of failing with "empty stream"; a truly empty stream logs the response-body head to Syslog (`Empty stream diagnostics, body head: ...`) before throwing.
- SSE is written through `App\Sse\SseWriter` (shared by AIClient + LogAgent); under Swoole the stream handle lives in `Hyperf\Context\Context` (coroutine-scoped) with a static fallback only for CLI/tests — don't hold it in a plain static for request handling.
- Diagnostic logging goes through `App\Syslog::error(component, message)` (uniform `[Component] message` format over `error_log`), not raw `error_log()` calls.
- Session-scoped file tools `list_log_files` / `read_log_file` operate only on the bound log id (`logId`), so the agent cannot read other logs.
- SSE contract: `event: status` with `type` = thinking / tool / tool_result / limit, plus legacy `data:` content deltas and `event: done`. See `API.md`.

## Docker

```bash
docker compose -f docker/compose.yaml up -d
```

- `hyperf` service — resident process built by `docker/hyperf.Dockerfile` (Swoole 6.2 (pinned via `SWOOLE_VERSION` build arg) + SpinYarn + pdo_mysql + pdo_sqlite (RAG) + redis; project code + vendor baked into the image via `composer install`); serves RAG on the main HTTP server under `/rag`; ships a PHP-based healthcheck probing `/v1/limits`; listens on `9501`
- `mariadb:11` (schema auto-created by `docker/mariadb-init.sql`) + `redis:7-alpine`
- named volumes: `mariadb-data`, `redis-data`; host `./mappings` is bind-mounted into `hyperf` at `/app/mappings`
- **Deployment notes:** `.dockerignore` excludes `Config.inc.php` (secrets never enter the image — the app falls back to `Config.inc.example.php`); configure via compose envs (`AI_ENABLED`, `AI_API_KEYS`, `REDIS_PASSWORD`, `MARIADB_PASSWORD`). `REDIS_PASSWORD` is applied to **both** hyperf and redis (redis starts with conditional `--requirepass`), so setting it on one side only will break cache/rate-limit. `mariadb-init.sql` runs only when the `mariadb-data` volume is empty (first init); pre-existing volumes from older deployments need manual schema creation.

## Constraints

- Requires PHP 8.4+, ext-json, ext-zlib, ext-mbstring, ext-pdo_mysql; Swoole 6.2 (resident server). SpinYarn deobfuscation requires the optional `spinyarn` extension (degrades gracefully when absent).
- MariaDB + Redis hostnames come from `DB_*` / `REDIS_*` env (framework config) — see `config/autoload/databases.php`.
- Max upload: app 10MB (`maxLength`); external reverse proxies should allow a larger request body.
