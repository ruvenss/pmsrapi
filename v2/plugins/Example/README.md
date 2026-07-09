# Example plugin

The smallest plugin that works — read it, run it, then copy it.

## Try it

With the service running (`php -S 0.0.0.0:8000 v2/server.php`):

```bash
curl -H 'Authorization: Bearer <ms_server_token>' \
     http://localhost:8000/v2/example/hello/World
# → {"success":true,"data":{"greeting":"Hello, World!","from":"example plugin"}}
```

The route is `/hello/{name}` declared in [src/ExamplePlugin.php](src/ExamplePlugin.php),
served at `/v2/example/hello/{name}` — the `example` prefix is this plugin's slug
(its lowercased directory name), added automatically.

## What each file does

| File | Role |
|---|---|
| `plugin.json` | Metadata + `enabled` flag. `slug` here is informational; the real slug is the lowercased directory name. |
| `src/ExamplePlugin.php` | Entry class `Plugins\Example\ExamplePlugin`. `register()` adds services, `routes()` adds endpoints. |
| `src/Controllers/GreetingController.php` | A normal controller returning a `Response`. |

## Make your own

Don't hand-copy this — run the wizard from the repo root:

```bash
bash v2/plugin.sh new
```

## Remove it

Delete this directory, or set `"enabled": false` in `plugin.json`. Nothing in
the core references it.
