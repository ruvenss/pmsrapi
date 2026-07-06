# v2 test environment

A disposable, containerized environment for running the **v2** integration
suite against **real MariaDB + Redis** — the pieces that can't be tested on a
bare machine without those services.

> ⚠ **This is DEV/TEST ONLY. It must never be shipped to production.** How
> production is built and deployed is intentionally out of scope here — nothing
> in this folder is a production artifact. The bundled token, passwords, and
> seed data are throwaway fixtures.

## Requirements

- Docker + Docker Compose v2 (`docker compose`).

## Run the whole suite (one command)

```bash
./run.sh        # build image → start db+redis → run tests → tear down
```

Exit code is `0` if every assertion passed, non-zero otherwise. Under the hood:

```bash
docker compose build
docker compose run --rm app test    # runs test-env/tests/run.php in the container
docker compose down -v
```

## Poke at it interactively

```bash
docker compose up --build           # serves at http://localhost:8080/v2
# then, from your host:
curl -s localhost:8080/v2/health
curl -s -H 'Authorization: Bearer test-token-please-change' localhost:8080/v2/info
open  localhost:8080/v2/_debug      # live debug dashboard
open  localhost:8080/v2/hive        # VueFlow hive map (role = hive_mind here)
docker compose down -v
```

The Bearer token is in [`weather.test.json`](weather.test.json).

## What it verifies

The suite ([`tests/run.php`](tests/run.php)) covers everything that needs live
backends:

| Area | What's asserted |
|---|---|
| Health / info | DB + Redis probes report `up`; MySQL connected over the persistent `p:` connection |
| CRUD | create/read/list(+pagination)/update/delete over mysqli **prepared** statements + schema whitelist |
| Query cache | a read writes a Redis cache key; a write bumps the table cache version (invalidation) |
| Debug dashboard | a real 5xx **auto-arms** capture; the failed INSERT + 500 response are in the Redis stream; the `Authorization` header is **redacted** |
| Rate limiting | exceeding the window returns **429** (Redis counters) |
| Hive registry | two manifests register in Redis; a duplicate `get_client` is flagged as a **collision**; `export` hands a worker the others' functions |
| Capabilities | `/capabilities` lists owned functions + role |
| Streaming | `/stream/_demo` and `/stream/{resource}` emit NDJSON; `ServiceClient::stream()` consumes it as a Generator over HTTP |

## How it fits together

- [`Dockerfile`](Dockerfile) — PHP 8.3 CLI + `mysqli` + `redis` (phpredis). Nothing else.
- [`docker-compose.yml`](docker-compose.yml) — `app` + official `mariadb:11` + `redis:7`. The repo is bind-mounted at `/opt/pmsrapi`, so edits are picked up without rebuilding.
- [`entrypoint.sh`](entrypoint.sh) — writes the test secret config to `/opt/weather.json` (the path `v2/config.php` resolves to), waits for the backends, then `serve`s or runs `test`.
- [`seed.sql`](seed.sql) — a `clients` table (with a `NOT NULL email` so the suite can force a real 5xx) + rows.

To reset the DB, `docker compose down -v` (the `-v` drops the MariaDB volume).
