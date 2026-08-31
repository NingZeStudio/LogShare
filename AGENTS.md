# LogShare — Agent Guide

## Stack and entrypoints

- PHP 8.4+, Hyperf 3.2, Swoole 6.2 resident/coroutine server; `bin/hyperf.php` is the CLI entrypoint and `core.php` bootstraps configuration.
- PSR-4 maps `App\` to `app/`; controllers use Hyperf annotation routes. `App\Controller\AbstractController` provides request parsing and response helpers.
- HTTP listens on `0.0.0.0:9501`; both deprecated `/1/` and current `/v1/` API routes are supported. The `/rag` MCP endpoint is served by the same process.
- Storage is selected by `storage.storageId`: MariaDB (`s`) or filesystem (`f`). Redis is an optional cache/rate-limit dependency.

## Setup and verification

```bash
git submodule update --init --recursive   # OpenLiteWaf（边缘 WAF）以 submodule 引入，pull 部署后必须执行
composer install
cp Config.inc.example.php Config.inc.php
composer test
composer test:architecture
PHPSTAN_TURBO=0 composer stan  # Termux; omit the prefix on CI/Ubuntu
php bin/hyperf.php list
php bin/hyperf.php rag:build
php bin/hyperf.php start
```

- Tests use Pest and bootstrap through `tests/bootstrap.php`; that bootstrap creates `Config.inc.php` when absent and supplies a Redis mock when ext-redis is unavailable. Run one file with `vendor/bin/pest tests/Unit/FilterTest.php` or filter by name with `vendor/bin/pest --filter=...`; architecture tests are the `architecture` Pest group (`composer test:architecture`).
- Integration tests need MariaDB and Redis. CI initializes MariaDB with `docker/mariadb-init.sql`; local Docker services are started with `docker compose -f docker/compose.yaml up -d`. CI (`.github/workflows/ci.yaml`) also smoke-tests the booted server on `9501` (`/v1/limits`, `POST /v1/log`, `/rag` MCP JSON-RPC) and builds the Docker image.
- PHPStan analyzes `app/` at level 5. The two existing `ignoreErrors` entries in `phpstan.neon` are intentional (Codex type hierarchy in `app/Log.php`, Hyperf Response `getConnection()` in `app/Sse/SseWriter.php`), and `app/Client/SpinYarnClient.php` is excluded because it depends on the spinyarn PHP extension that is absent in the CI static-analysis environment; do not add suppressions or exclusions casually.
- There is no configured formatter. Before finishing code changes, run the relevant Pest tests, `composer test:architecture`, and `PHPSTAN_TURBO=0 composer stan` on Termux.

## Configuration and operational constraints

- Copy `Config.inc.example.php` to the gitignored `Config.inc.php`; never commit it or expose its API keys. Database and Redis connection settings can be overridden with `DB_*` and `REDIS_*`; AI settings in `.env` include `AI_ENABLED`, `AI_API_KEYS`, `AI_BASE_URL`, `AI_MODEL`, and JSON `AI_RAG_PROVIDERS`.
- Do not change `id.characters` or the ID length: existing log IDs depend on them. IDs are seven characters, with `s`/`f` identifying the storage backend.
- Upload limits are enforced before storage: 10 MB / 50,000 lines, plus at most 200 files and 12 MB total. ZIP uploads must remain protected against traversal and excessive expansion.
- SpinYarn is optional and only parses mappings already present under `mappings/`; it has no automatic download. The extension and mapping files are primarily handled by the Docker build/bind mount.
- Swoole is a resident process: process-level caches and extension handles survive requests. Restart the server after changing parsing/deobfuscation behavior or other process-level state.
- The Docker MariaDB init script runs only for a new database volume. MariaDB runs Event Scheduler and `mariadb-events` applies the generated `cleanup_expired_logs` SQL; `scripts/sync_mariadb_events.php` reads `Config.inc.php` as the sole TTL source, including on existing volumes. MariaDB healthcheck verifies the event exists and is enabled. Production Compose reads secrets from the gitignored `.env`; `MARIADB_PASSWORD`, `MARIADB_ROOT_PASSWORD`, and `REDIS_PASSWORD` must be supplied consistently to Hyperf, MariaDB, Redis, and MariaDB Event services. Non-secret application settings remain in `Config.inc.php`. AI is disabled by default and configuration validation requires `AI_ENABLED=true`, `AI_API_KEYS`, `AI_BASE_URL`, and `AI_MODEL`; semantic RAG additionally requires valid JSON `AI_RAG_PROVIDERS` entries with embedding configuration; vector recall is primary and lexical results supplement it.
- Application-layer rate limiting is intentionally disabled; public traffic must be rate-limited by Nginx/CDN/WAF before it reaches Hyperf. Do not re-enable trust of `X-Real-IP` in application code without an explicit trusted-proxy design.
- Docker build versions are pinned in `docker/hyperf.Dockerfile`; the standard Compose deployment exposes Nginx on ports 80/443, its config is `docker/nginx/default.conf`, TLS certificates are mounted read-only from the gitignored `docker/certs/` directory, and ACME HTTP-01 challenges use `docker/acme/`.
- Edge WAF: `OpenLiteWaf/` is a standalone OpenResty-Lua project running inside the nginx container (image `openresty/openresty:1.27.1.2-alpine`, not stock nginx). Entry points are `access_by_lua_file` / `content_by_lua_file` in `docker/nginx/default.conf`; http-level lua directives live in the mounted `OpenLiteWaf/nginx/nginx.conf` (`lua_shared_dict openlitewaf`, `lua_package_path`). Public stats page: `/security` (HTML), `/security/stats` (JSON summary) and `/security/logs` (JSON, attack logs paginated 50/page, ring buffer of 500). Signatures match raw request_uri, fully decoded request_uri (incl. query), uri, User-Agent, and request body (POST/PUT/PATCH; requests with Content-Length >2MB are skipped, chunked bodies are always scanned; first 64KB only; log-content endpoints `/v1/log` and `/1/log` are exempt in `body_exempt_prefixes` to avoid false positives on user log text). Categories: sqli/xss/traversal/rce/probe. Public log entries mask IPs and blank `token=` params; "banned IP count" is an approximation via ring slots (shared dict cannot enumerate keys). All data is in-memory only, reset on process restart. Rules and CC thresholds are in `OpenLiteWaf/lua/openlitewaf.lua`; changes take effect only after the nginx Lua VM re-reads them — `git pull` alone does NOT apply, and in the current deployment `docker exec logshare-nginx nginx -s reload` is UNRELIABLE (the container's nginx pid file points at a stale master PID, so the HUP never reaches the running master; verified 2026-08-30). Use `docker restart logshare-nginx` instead — note this clears all in-memory counters/bans/log data. Signature matching requires `ngx.re.compile` (a lua-resty-core/FFI API, NOT native); the module loads `resty.core.re` itself and falls back to plain string matching over `ngx.re.find` when unavailable. The CC threshold must stay below the nginx `limit_req` rate (30r/s), or requests get 503-dropped by limit_req before OpenLiteWaf can ban. `OpenLiteWaf/tests/openlitewaf_regex_test.php` and `OpenLiteWaf/tests/openlitewaf_logic_test.lua` are the regression tests for rules and logic.

## Implementation rules that are easy to miss

- Controllers must use injected PSR-7 requests, `ContentParser`, storage abstractions, and `AbstractController` helpers; do not read `$_SERVER`, `$_GET`, or `$_POST`, and do not issue raw SQL from controllers. Architecture tests enforce this.
- Throw `App\ApiError` for expected API failures; `ApiExceptionHandler` renders the API error response.
- Apply configured pre-filters before storage. Deletion tokens are stored hashed; plaintext tokens are returned only by the upload response.
- Use `App\Syslog::error()` for diagnostics, not raw `error_log()`.
- SSE output must go through `App\Sse\SseWriter`; request-scoped stream state belongs in Hyperf context rather than a plain static.
- AI tool calls remain model-controlled; do not force a RAG call in `LogAgent` when the model returns no tools. AI routes are 404 when `ai.enabled` is false.
- If an API route or response changes, update `API.md`, `openapi.yaml`, and `postman_collection.json` together.

## 与用户的协作约定（不可省略）

- 使用简体中文与用户交流与推理；术语可保留英文，但表述须以中文为准。
- 开发环境是 Termux（Android）：系统 `/tmp` 只读，临时文件一律使用 `/data/data/com.termux/files/usr/tmp/` 或项目根 `tmp/`；遇兼容性问题先用 WebSearch 检索，仍无法确定时向用户确认，不得直接执行未经验证的操作。
- 编写后端或前后端交互代码时，对 SQL 注入、XSS、CSRF、路径遍历、敏感信息泄露保持警惕；发现潜在风险须明确告知用户并征询处理意见，不得擅自忽略或掩盖。
- 严禁破坏用户全局环境；任何可能影响系统稳定性、数据完整性或安全性的危险操作，必须事先征得用户明确授权。
- 不懂就问、不妄加揣测：与文档或用户意图有出入时先确认再动手。
- 本文档是项目上下文的唯一约定来源：修改内容与之不符时，同步更新本文档。
