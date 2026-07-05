# PMSRAPI v2

A **structured, industry-standard rewrite** of the PMSRAPI microservice core.
Same one-service-one-database philosophy and the **same secret config file** as
v1 — but a modern, secure, Redis-accelerated internal design.

| | v1 | v2 |
|---|---|---|
| Style | Procedural, `define()` constants | Namespaced classes, DI container |
| Autoloading | Manual `include` | Hand-rolled **PSR-4** (no Composer) |
| Routing | `function` name in JSON body | Real **REST** URLs (`GET /v2/users/42`) |
| SQL | mysqli string concatenation | mysqli **prepared + persistent** everywhere |
| Identifiers | Trusted from client | **Whitelisted** against live schema |
| Caching / limits / queue | File-based / none | **Redis**: cache, rate-limit, queue, tokens |
| Debugging | tail log files | **live dashboard**, auto-armed on 5xx |
| Inter-service | `universe` + `http_rest` | `function_map` + **NDJSON streaming** + Hive map |
| Response | `http_response()` + `die()` | `Response` returned once by front controller |

> v2 lives beside v1 — it does not replace or modify it. Run whichever you mount.

📖 **Step-by-step deploy &amp; use guide** (HTML, copy-paste examples for every CRUD case,
multi-service setups, and single/multi deployment): open
[`v2/docs/index.html`](docs/index.html) in a browser. Its examples run as-is against
the [`test-env/`](../test-env/) stack.

---

## Requirements

- PHP **8.3+** (CLI + a web SAPI). No Composer, no build step.
- Extensions: `mysqli`, `redis` (phpredis), `curl`, `json`, `mbstring`.
- MySQL / MariaDB — connections are **persistent** (`p:` host prefix), so size
  the server's `max_connections` for `workers × instances`. A DB is **optional**:
  a service with no `db` block runs fine, and `/info` reports it as
  `not_configured`.
- Redis 6+ (optional but strongly recommended — the service degrades gracefully
  without it, losing caching/rate-limiting/queueing, not correctness).

---

## Quick start (local dev)

```bash
# 1. Point v2/config.php 'secrets_path' at your secret JSON (see below),
#    or copy your v1 <ms_name>.json and add the v2 blocks.

# 2. Run with the built-in server + the dev router script.
php -S 0.0.0.0:8000 v2/server.php

# 3. Call it (note: REST URLs now, Bearer token still required).
curl -s http://localhost:8000/v2/health            # public, no auth
curl -s http://localhost:8000/v2/info \
  -H 'Authorization: Bearer <ms_server_token>'
curl -s http://localhost:8000/v2/users?page=1&per_page=20 \
  -H 'Authorization: Bearer <ms_server_token>'
```

The `server.php` router is **dev-only**. In production use Apache/nginx
rewrites (below) pointing at `v2/index.php`.

---

## Configuration

v2 splits config exactly like v1:

- **Public** — [`v2/config.php`](config.php), committed, returns a plain array
  (identity, CORS headers, reason phrases, and `secrets_path`).
- **Secret** — a JSON file kept **outside the web root** (the same file v1 uses).
  v2 reads new optional blocks; v1 ignores them, so **one file serves both.**

### Secret JSON — v2 additions

```jsonc
{
  // --- shared with v1 ---
  "db":   { "host": "127.0.0.1", "port": 3306, "name": "weather",
            "username": "app", "password": "secret" },
  "ms_server_token": "the-master-bearer-token",
  "env": "prod",
  "local_log": { "path": "/var/log/weather.log", "level": "errors" },

  // --- new in v2 ---
  "redis": {
    "host": "127.0.0.1", "port": 6379, "password": null,
    "db": 0, "prefix": "ms:weather:", "timeout": 1.5
  },
  "cache":      { "ttl": 30 },
  "rate_limit": { "enabled": true, "max": 120, "window": 60, "fail_open": true },

  // On-demand production debugger (see "Live debug dashboard"):
  "debug": { "enabled": true, "auto_arm_on_error": true, "window": 300,
             "max_events": 5000, "capture_bodies": true, "ttl": 3600 },

  // Cluster role + the functions THIS service owns (see "Cluster"):
  "role": "worker",
  "functions": {
    "get_client": { "method": "GET", "path": "/clients/{id}" }
  },
  // Baked in by `php v2/hive-sync.php` so the worker runs standalone in prod:
  "function_map": {
    "get_invoice": { "service": "billing", "method": "GET", "path": "/invoices/{id}" }
  },

  // Expose tables as REST resources WITHOUT writing code:
  "resources": {
    "users": {
      "table": "users",
      "methods": ["GET", "POST", "PUT", "DELETE"],
      "cache_ttl": 30,
      "per_page": 25
    }
  },

  // Webhook subscribers (delivered by the CLI worker, HMAC-signed):
  "webhooks": [
    { "event": "users.created", "url": "https://example.com/hook", "secret": "shh" }
  ]
}
```

`resources` is the v2 analogue of v1's `allowed_functions` whitelist: **a table
is only reachable over REST if it is listed here.**

---

## REST API

Every route is mounted under `/v2` and (except `/health`) requires
`Authorization: Bearer <token>`.

| Method & path | Action |
|---|---|
| `GET /v2/health` | Liveness + DB/Redis probe (**public**) |
| `GET /v2/info` | Service metadata + MySQL/Redis connection state |
| `GET /v2/{resource}` | List (filter, sort, paginate) |
| `GET /v2/{resource}/{id}` | Read one |
| `POST /v2/{resource}` | Create (JSON body = column ⇒ value) |
| `PUT` / `PATCH /v2/{resource}/{id}` | Update |
| `DELETE /v2/{resource}/{id}` | Delete |

**List query params:** `?page=`, `?per_page=` (max 200), `?order=column` or
`?order=column:desc`, and any column name as an equality filter
(`?status=active`). Unknown/illegal columns are rejected, not interpolated.

### Response envelope

Preserves v1's shape and adds structured errors + pagination meta:

```jsonc
// success
{ "success": true, "data": [ ... ], "meta": { "page": 1, "per_page": 25, "total": 91, "total_pages": 4 } }
// failure
{ "success": false, "error": { "code": "validation_failed", "message": "...", "details": { ... } } }
```

---

## Redis, and what happens without it

| Concern | Class | Degradation if Redis is down |
|---|---|---|
| Query/response cache | `Cache\QueryCache` | Cache miss → query still runs |
| Rate limiting | `Cache\RateLimiter` | Fails **open** (allows), logs a warning |
| Webhook/job queue | `Queue\RedisQueue` | Enqueue logs an error; the write still succeeds |
| Dynamic API tokens | `Security\TokenStore` | Only the static master token works |
| Schema whitelist cache | `Database\Schema` | Falls back to per-request `information_schema` reads |
| Debug capture | `Debug\DebugRecorder` | Recording off; requests unaffected |

Cache invalidation is **O(1) per table** via a version counter embedded in cache
keys — a write bumps the version, orphaning old keys (they expire by TTL). No
key bookkeeping, no race.

---

## Webhook worker

```bash
php v2/worker.php          # daemon (systemd Restart=always)
php v2/worker.php --once   # drain then exit (cron/testing)
```

Writes emit `{resource}.created|updated|deleted` events onto the Redis queue;
the worker delivers each to matching subscribers with an
`X-Signature: sha256=<hmac>` header. Request latency never waits on subscribers.

---

## Live debug dashboard

An **on-demand production debugger**. It sits idle, then starts capturing the
moment something breaks — so you can watch the failing traffic in real time
without leaving verbose logging on in production.

**How it works**

- Every request is buffered **in memory** as it runs — the request, the response,
  and every DB query (SQL + bound params + timing). No I/O on the hot path.
- On a **5xx or uncaught exception** the buffer is flushed to a capped Redis
  Stream and a capture **window** opens (default 300s): the triggering
  transaction is saved in full, and everything for the next few minutes is
  captured too. You can also **Arm** it manually to watch proactively.
- The dashboard polls the stream (~1s) and renders transactions, responses, and
  DB interactions live, colour-grouped by request id, each row expandable.
- Captured data lives **only in Redis** with a TTL — never on disk (the code tree
  is read-only). Secrets (`Authorization`/`Cookie` headers, and
  `password`/`token`/`secret`/… fields) are **redacted at capture time**.

**Open it:** `https://<host>/v2/_debug` — paste the Bearer token when prompted.

| Method & path | Purpose |
|---|---|
| `GET /v2/_debug` | Dashboard UI (public shell — no data) |
| `GET /v2/_debug/status` | Armed state, window TTL, event count |
| `GET /v2/_debug/events?since=<id>` | Poll events newer than a cursor |
| `POST /v2/_debug/arm` | Manually open a window `{ "ttl": 300, "reason": "..." }` |
| `POST /v2/_debug/disarm` | Close the window |
| `DELETE /v2/_debug/events` | Clear the captured stream |

**Config** — secret JSON `debug` block:

| key | default | meaning |
|---|---|---|
| `enabled` | `false` | master switch; off = no capture, routes disabled |
| `auto_arm_on_error` | `true` | open a window automatically on a 5xx |
| `window` | `300` | capture window, seconds |
| `capture_bodies` | `true` | include request/response bodies (redacted) |
| `max_events` | `5000` | Redis Stream cap (MAXLEN ~) |
| `ttl` | `3600` | stream self-expiry, seconds |
| `redact` | `[]` | extra body/param key fragments to mask |
| `max_buffer` | `500` | max events buffered per request |

**Security & requirements**: the dashboard exposes redacted **production traffic**
— treat the Bearer token as admin and only enable it where that token is trusted.
The data endpoints are gated by the token and excluded from rate limiting; the
HTML shell is the only public part. Requires the **phpredis** extension + Redis;
if either is missing the whole subsystem silently no-ops.

---

## Cluster: roles, streaming & the Hive

### Roles

Each service declares a `role` in its secret config:

- **`worker`** (default) — a normal service. Owns functions, serves traffic, and
  in **production runs standalone** from a baked-in map — no Hive required.
- **`hive_mind`** — a **development-only** coordinator. It aggregates every
  worker's function manifest, flags duplicates, and renders the map. Its `/hive`
  routes exist only when `role` is `hive_mind`.

### Functions & the one-owner rule

A worker lists the functions it **owns** in config — each mapped to how it is
invoked on that service:

```jsonc
"functions": {
  "get_client":     { "method": "GET",  "path": "/clients/{id}" },
  "export_clients": { "method": "POST", "path": "/stream/clients", "stream": true }
}
```

A function name must belong to **exactly one** service: `get_client`'s logic
lives in one place and every other service *calls* it instead of re-implementing
it. The Hive enforces this by detecting collisions. `GET /v2/capabilities`
returns a worker's manifest — this is what the Hive polls.

### Inter-service streaming (NDJSON)

Services exchange data with `ServiceClient`, which resolves the target from the
**local** `function_map` + `universe` (so a worker needs no Hive at runtime):

```php
$client = $container->get(ServiceClient::class);

// request/response
$client->call('get_client', ['id' => 42]);

// streaming — records arrive lazily, constant memory (a PHP Generator)
foreach ($client->stream('export_clients', ['since' => '2026-01-01']) as $row) {
    process($row);
}
```

The wire format is **NDJSON over chunked HTTP** (`application/x-ndjson`, one JSON
object per line). Produce a stream from any endpoint with `Response::stream($gen)`
where `$gen` is a Generator; `GET /v2/stream/{resource}` streams a whole table
that way (paged internally, flat memory), and `GET /v2/stream/_demo` is a
DB-free example.

### The Hive map (VueFlow)

When `role` is `hive_mind`, the coordinator exposes:

| endpoint | purpose |
|---|---|
| `GET /v2/hive` | VueFlow graph UI (public shell) |
| `GET /v2/hive/map` | services, per-function owners, collisions, function_map |
| `GET /v2/hive/collisions` | just the duplicate-ownership problems |
| `POST /v2/hive/refresh` | poll every universe node's `/capabilities` |
| `POST /v2/hive/register` | a worker pushes its own manifest |
| `GET /v2/hive/export/{service}` | the map to bake into a given worker |

Open `https://<hive>/v2/hive` to see every service and its functions as a graph,
**duplicated functions highlighted in red**. (The graph loads VueFlow from a CDN
— dev-only, needs internet; if it can't load it falls back to a list view. The
`/hive/map` JSON is always available regardless.)

### Dev → prod: baking the map in

In development a worker syncs its map from the Hive and writes it into its own
config, then runs standalone forever after:

```bash
php v2/hive-sync.php   # pulls /hive/export/<service>, bakes function_map + universe
```

Point a worker at the Hive with a `"hive": { "ip", "port", "token", "ssl" }`
block (or a universe node named `hive`). `hive-sync` refuses to run in `prod` —
sync in dev, then ship the baked config.

---

## Production web server

**nginx** (reverse-proxy to `php -S`, or php-fpm):

```nginx
location /v2/ {
    try_files $uri $uri/ /v2/index.php$is_args$args;
}
location ~ ^/v2/index\.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/v2/index.php;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}
# never serve secrets or logs
location ~* \.(json|log)$ { deny all; }
```

**Apache** — [`v2/.htaccess`](.htaccess) already rewrites everything to
`index.php`; just ensure `AllowOverride All` and `mod_rewrite`.

Keep the secret JSON **outside** the document root regardless of server.

---

## Directory layout

```
v2/
  index.php          front controller (HTTP entry point)     [core]
  server.php         dev-only built-in-server router         [core]
  worker.php         CLI webhook/queue worker                [core]
  hive-sync.php      CLI: bake the Hive map into worker config [core, dev-only]
  bootstrap.php      composition root (DI wiring)            [core]
  routes.php         route table (system + config CRUD)      [core, extend at bottom]
  config.php         PUBLIC config (edit for your service)
  .htaccess          Apache rewrites
  src/
    Core/            Autoloader, Config, Container, Router, Route, Kernel
    Http/            Request, Response, HttpMethod, Middleware, ResourceDefinition
      Middleware/    AuthMiddleware, RateLimitMiddleware
      Controllers/   Crud, System, Debug, Capabilities, Stream, Hive (…Controller)
    Database/        Connection, Schema, Repository   (mysqli prepared statements)
    Cache/           RedisClient, QueryCache, RateLimiter, RateLimitResult
    Queue/           RedisQueue, WebhookDispatcher
    Security/        TokenStore
    Cluster/         Role, Capabilities, ServiceClient, HiveRegistry, hive-map.html
    Debug/           DebugRecorder, Redactor, DebugEventType, dashboard.html
    Support/         Logger, Paginator
    Exception/       ApiException + typed subclasses
```

## Extending v2 (without breaking updates)

- **New table over REST** → add it to `resources` in the secret JSON. No code.
- **Custom endpoint** → add a `$router->...` line at the bottom of `routes.php`.
- **New service** → drop a namespaced class under `src/`, register it in
  `bootstrap.php`, inject it where needed.
- **Business rules / side effects** → a controller or a service the controller
  calls; emit webhooks via `WebhookDispatcher`.

See [`v2/CLAUDE.md`](CLAUDE.md) for the conventions Claude Code follows in this
tree.
