# PMSRAPI v2

A **structured, industry-standard rewrite** of the PMSRAPI microservice core.
Same one-service-one-database philosophy and the **same secret config file** as
v1 — but a modern, secure, Redis-accelerated internal design.

| | v1 | v2 |
|---|---|---|
| Style | Procedural, `define()` constants | Namespaced classes, DI container |
| Autoloading | Manual `include` | Hand-rolled **PSR-4** (no Composer) |
| Routing | `function` name in JSON body | Real **REST** URLs (`GET /v2/users/42`) |
| SQL | mysqli string concatenation | mysqli **prepared statements** everywhere |
| Identifiers | Trusted from client | **Whitelisted** against live schema |
| Caching / limits / queue | File-based / none | **Redis**: cache, rate-limit, queue, tokens |
| Response | `http_response()` + `die()` | `Response` returned once by front controller |

> v2 lives beside v1 — it does not replace or modify it. Run whichever you mount.

---

## Requirements

- PHP **8.3+** (CLI + a web SAPI). No Composer, no build step.
- Extensions: `mysqli`, `redis` (phpredis), `curl`, `json`, `mbstring`.
- MySQL / MariaDB.
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
| `GET /v2/info` | Service metadata |
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
  bootstrap.php      composition root (DI wiring)            [core]
  routes.php         route table (system + config CRUD)      [core, extend at bottom]
  config.php         PUBLIC config (edit for your service)
  .htaccess          Apache rewrites
  src/
    Core/            Autoloader, Config, Container, Router, Route, Kernel
    Http/            Request, Response, HttpMethod, Middleware, ResourceDefinition
      Middleware/    AuthMiddleware, RateLimitMiddleware
      Controllers/   CrudController, SystemController
    Database/        Connection, Schema, Repository   (mysqli prepared statements)
    Cache/           RedisClient, QueryCache, RateLimiter, RateLimitResult
    Queue/           RedisQueue, WebhookDispatcher
    Security/        TokenStore
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
