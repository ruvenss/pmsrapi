# CLAUDE.md — v2

Guidance for Claude Code (and any contributor) working in the `v2/` tree. This
travels with the repo, so a fresh clone inherits these conventions. Read it
before editing v2. For v1, see the repo-root `CLAUDE.md`; **v2 is a separate,
parallel core — do not blend v1's idioms into it.**

## What v2 is

A framework-less-but-**structured** PHP 8.3 microservice core. Same "one
service = one database" model and the **same secret JSON config** as v1, but
rebuilt to be modern, secure, and Redis-accelerated. No Composer, no build step:
a hand-rolled PSR-4 autoloader ([src/Core/Autoloader.php](src/Core/Autoloader.php))
maps `Pmsrapi\V2\` → `v2/src/`.

## Golden rules

1. **Follow `.claude/rules/` here.** Unlike v1's core (which intentionally
   violates them), v2 IS the modern code those rules describe: `declare(strict_types=1)`
   in every file, typed everything, readonly value objects, enums, constructor
   promotion, custom exceptions, no globals, no `@`, no raw SQL.
2. **Never concatenate values into SQL.** All values are bound parameters
   (`$stmt->execute($params)`). All **identifiers** (table/column/order-by) are
   whitelisted via [`Database\Schema`](src/Database/Schema.php) then backtick-quoted.
   If you catch yourself interpolating a client string into SQL, stop.
3. **Constructor injection only.** Resolve dependencies from the
   [`Container`](src/Core/Container.php); register new services in
   [bootstrap.php](bootstrap.php). No `global`, no singletons-via-static, no `new`
   of a service inside another service.
4. **Redis must degrade, never crash.** Every Redis call site catches
   `RedisException` and continues (cache→miss, rate-limit→open, queue→logged).
   Preserve that when adding Redis-backed features.
5. **One response, returned not `die()`d.** Handlers return a
   [`Response`](src/Http/Response.php); the front controller sends it once. Do
   not `echo`/`die()` mid-request (that was v1).

## Request lifecycle

`index.php` → `bootstrap.php` (DI) → `Kernel` builds the pipeline
**RateLimit → Auth → Router.dispatch** → controller returns `Response`. Any
`ApiException` is converted to an envelope by the Kernel; unexpected `Throwable`
is caught at the `index.php` boundary and logged. CORS headers are applied to
every response.

## Routing

Real REST, not v1's function-name dispatch. Routes live in
[routes.php](routes.php): system routes are explicit; CRUD routes are generated
from the `resources` block of the secret config. Templates use `{param}`
placeholders (`/users/{id}`) matched one segment at a time.

## Adding things — put work in the right place

| Task | Where | Notes |
|---|---|---|
| **A developer feature (default)** | a **plugin** under [plugins/](plugins/) — scaffold with `bash v2/plugin.sh` | Own controllers/models/routes, **zero core edits**. See [plugins/README.md](plugins/README.md) and the **Plugins** section below. |
| Expose a table over REST | `resources` in the **secret JSON** | No code. This is the security whitelist. |
| Custom endpoint (core-level) | a plugin's `routes()` — or, for core maintainers, [routes.php](routes.php) | Prefer a plugin. Editing routes.php is a core change and is overwritten on update. |
| New service/helper | a plugin's `register()` — or, for the core itself, a class under `src/` wired in [bootstrap.php](bootstrap.php) | Both constructor-injected. Plugin services can inject core ones; not vice-versa. |
| Data access | extend [`Database\Repository`](src/Database/Repository.php) | Keep the bound-params + Schema-whitelist discipline. |
| Side effect on write | `WebhookDispatcher::emit()` or a service the controller calls | Events are async via the Redis queue. |
| New error type | subclass [`Exception\ApiException`](src/Exception/ApiException.php) | Carry a status + machine `code`; the envelope renders it. |

The generic [`CrudController`](src/Http/Controllers/CrudController.php) already
handles list/read/create/update/delete for any configured resource — prefer
config over a new controller unless you need custom behavior.

## Plugins (`plugins/`, `src/Plugin/`)

The supported way to add application features. A plugin is a folder under
[plugins/](plugins/) that contributes services + routes to the running core
**without editing any core file** — this is how the core stays frozen and
auto-updatable while developers keep building. Full guide:
[plugins/README.md](plugins/README.md).

- **Contract:** `plugins/<Name>/` defines `Plugins\<Name>\<Name>Plugin` (at
  `src/<Name>Plugin.php`) implementing [`Plugin`](src/Plugin/Plugin.php) — extend
  [`AbstractPlugin`](src/Plugin/AbstractPlugin.php). Two hooks: `register(PluginRegistrar)`
  adds services; `routes(PluginRouter, Container)` adds endpoints. Entry class takes
  no constructor args.
- **Namespace:** `Plugins\<Name>\` → `plugins/<Name>/src` (registered by
  [`PluginManager`](src/Plugin/PluginManager.php), NOT under `Pmsrapi\V2\`).
- **URL isolation:** routes are auto-prefixed with the plugin slug (lowercased
  dir name), so `Billing` → `/v2/billing/…`. Slug collisions with a core prefix
  or a configured resource fail loudly at boot.
- **Governance (keep these when touching the plugin core):**
  [`PluginRegistrar`](src/Plugin/PluginRegistrar.php) refuses to re-bind an
  existing service id (plugins ADD, never replace); plugins get the shared
  `Connection`/`Repository` injected and must not open their own DB/Redis handle;
  a broken plugin throws [`PluginException`](src/Exception/PluginException.php)
  and stops the service by design (fail loud, not half-loaded).
- **Two core hooks only:** [bootstrap.php](bootstrap.php) calls
  `registerServices()`; [routes.php](routes.php) calls `registerRoutes()`. These
  are the *entire* integration surface — do not scatter plugin logic elsewhere.
- **Wizard:** `bash v2/plugin.sh` (`new`/`list`/`check`/`remove`) scaffolds and
  validates plugins. The shipped [`Example`](plugins/Example) plugin is the
  reference; it is deletable.
- `plugins/` is user-owned and survives core updates; do not put core code there.

## Data layer specifics

- Driver is **mysqli** (kept from v1) in exception mode, but **always prepared**.
  `Connection` exposes `select/selectOne/scalar/insert/affect` — use those.
- Connections are **persistent** (mysqli `p:` host prefix) and reused across
  requests — never open a non-persistent mysqli connection. A DB is **optional**
  (`isConfigured()`); `isAlive()` is the non-throwing liveness probe that
  `/info` and `/health` report. Since connections persist, size MySQL
  `max_connections` for `workers × instances`.
- `Schema` caches `information_schema` lookups in Redis; call `assertTable()` /
  `assertColumns()` before trusting any client-supplied identifier.
- `Repository` returns plain associative arrays (rows), not entities — keep it
  that way for now; the CRUD contract is array-in/array-out.
- Advanced reads (GROUP BY / aggregates / GROUP_CONCAT / CONCAT / HAVING /
  DISTINCT) go through `AggregateQuery` — a **structured spec, never raw SQL** —
  at `POST /{resource}/query`. Upsert ("if exists then update") is
  `POST /{resource}/upsert` via `INSERT … ON DUPLICATE KEY UPDATE`. Same
  discipline as everything else: whitelist identifiers via `Schema`, bind every
  value, allowlist the aggregate functions/operators. Do NOT add raw-SQL passthrough.

## Debug dashboard (`Debug/`)

On-demand production recorder + live UI at `GET /v2/_debug`. Off unless
`debug.enabled` in the secret config. Flow: every request is buffered **in
memory** by `DebugRecorder` (`index.php` calls `beginRequest`/`endRequest`;
`Connection` records each query); on a 5xx or manual **arm** the buffer flushes
to a **capped Redis Stream** with a TTL and a capture window opens; the dashboard
polls it (~1s). Rules when touching this subsystem:

- Capture must **never break a request** — every Redis call is best-effort and
  try/caught, and every method no-ops when `debug.enabled` is false or Redis is
  down. Keep it that way.
- Everything captured passes through `Redactor` first. If you capture a **new
  field**, make sure secrets can't leak (extend the redactor / `debug.redact`).
- Captured data lives **only in Redis** (TTL'd) — never write it to the code tree
  ([sec-writable-state-outside-code](../.claude/rules/sec-writable-state-outside-code.md)).
- `/_debug` (HTML shell) is public; all `/_debug/*` data endpoints require the
  token and bypass rate limiting. The recorder ignores `/_debug` and `/health`.
- Requires phpredis + Redis; without them the whole thing silently no-ops.

## Cluster: roles, streaming, Hive (`Cluster/`)

- `role` in config: `worker` (default) or `hive_mind`. The Hive is DEV-only; its
  `/hive` routes register only when `Config::isHiveMind()`.
- Workers declare owned functions in the `functions` config block. A function
  name must be owned by **exactly one** service; the Hive flags duplicates via
  `HiveRegistry::buildMap()` (a pure function — unit-test it, don't mock Redis).
- Cross-service calls go through `ServiceClient` — never hand-roll curl.
  `call()` for request/response; `stream()` returns a Generator of NDJSON
  records. It resolves targets from the LOCAL `function_map` + `universe`, so a
  worker needs no Hive at runtime (that's the whole point).
- Stream from any endpoint with `Response::stream($generator)` (NDJSON,
  `application/x-ndjson`). Keep producers as Generators (constant memory) — never
  buffer a whole table. `Connection` has no streaming query helper yet; page in a
  loop (see `StreamController::rows`).
- `/hive` (shell) + `/_debug` are public; their data endpoints are token-gated,
  skip rate limiting, and are ignored by the recorder. Preserve that for new
  hive/debug endpoints.
- Baking prod config is `hive-sync.php` (CLI, dev-only, atomic write to
  `secrets_path`). Do NOT add an HTTP endpoint that rewrites the secret config.

## Config

- Public: [config.php](config.php) returns an array (identity, headers,
  `secrets_path`). Edit this for your service.
- Secret: the JSON at `secrets_path` — the **same file v1 uses**, with v2-only
  blocks (`redis`, `cache`, `rate_limit`, `resources`, `webhooks`). v1 ignores
  the extra keys, so never fork the file.
- Access via `Config::secret('dot.path', default)` and `Config::public(...)`.

## Gotchas

- Stay within **PHP 8.3** features (no property hooks / pipe operator). Verify
  with `find v2 -name '*.php' -print0 | xargs -0 -n1 php -l`.
- The `resources` map is the ONLY thing exposing tables — an unlisted table is a
  404, by design. Adding a resource ≠ writing a migration; the table must exist.
- `server.php` is dev-only (built-in-server router). Production routing is via
  `.htaccess` / nginx to `index.php`.
- Bearer auth accepts the static `ms_server_token` **or** a live token in
  `TokenStore`. Do not weaken `hash_equals` on the static path.

## Verifying a change

No test suite. Smoke-test by running `php -S 0.0.0.0:8000 v2/server.php` against
a secret JSON and curling `/v2/health`, `/v2/info`, and a configured resource.
Watch `local_log.path` for structured error lines.
