# LogShare — Agent Guide

## Stack and entrypoints

- PHP 8.4+, Hyperf 3.2, Swoole 6.2 resident/coroutine server; `bin/hyperf.php` is the CLI entrypoint and `core.php` bootstraps configuration.
- PSR-4 maps `App\` to `app/`; controllers use Hyperf annotation routes. `App\Controller\AbstractController` provides request parsing and response helpers.
- HTTP listens on `0.0.0.0:9501`; both deprecated `/1/` and current `/v1/` API routes are supported. The `/rag` MCP endpoint is served by the same process.
- Storage is selected by `storage.storageId`: MariaDB (`s`) or filesystem (`f`). Redis is an optional cache/rate-limit dependency.

## Setup and verification

```bash
composer install
cp Config.inc.example.php Config.inc.php
composer test
composer test:architecture
PHPSTAN_TURBO=0 composer stan  # Termux; omit the prefix on CI/Ubuntu
php bin/hyperf.php list
php bin/hyperf.php rag:build
php bin/hyperf.php start
```

- Tests use Pest and bootstrap through `tests/bootstrap.php`; that bootstrap creates `Config.inc.php` when absent and supplies a Redis mock when ext-redis is unavailable.
- Integration tests need MariaDB and Redis. CI initializes MariaDB with `docker/mariadb-init.sql`; local Docker services are started with `docker compose -f docker/compose.yaml up -d`.
- PHPStan analyzes `app/` at level 5. `app/Client/SpinYarnClient.php` is excluded, and the two existing `ignoreErrors` entries in `phpstan.neon` are intentional.
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

## Implementation rules that are easy to miss

- Controllers must use injected PSR-7 requests, `ContentParser`, storage abstractions, and `AbstractController` helpers; do not read `$_SERVER`, `$_GET`, or `$_POST`, and do not issue raw SQL from controllers. Architecture tests enforce this.
- Throw `App\ApiError` for expected API failures; `ApiExceptionHandler` renders the API error response.
- Apply configured pre-filters before storage. Deletion tokens are stored hashed; plaintext tokens are returned only by the upload response.
- Use `App\Syslog::error()` for diagnostics, not raw `error_log()`.
- SSE output must go through `App\Sse\SseWriter`; request-scoped stream state belongs in Hyperf context rather than a plain static.
- AI tool calls remain model-controlled; do not force a RAG call in `LogAgent` when the model returns no tools. AI routes are 404 when `ai.enabled` is false.
- If an API route or response changes, update `API.md`, `openapi.yaml`, and `postman_collection.json` together.
