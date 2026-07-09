# Plugins

A **plugin** is a self-contained folder of your own controllers, models,
helpers and routes that plugs into the running v2 core. It is how you extend a
microservice **without editing a single core file** — no `bootstrap.php`, no
`routes.php`, nothing under `v2/src/`.

Two developers can each own a plugin in the same service and never step on each
other: every plugin gets its own namespace and its own URL prefix.

> A plugin runs *inside* this service's process. It is a code-organisation tool,
> not a sandbox — a fatal error in a plugin takes the service down. If you need
> true runtime isolation, split it into a separate microservice (see the
> Cluster/universe docs). Plugins are the easy, in-process extension path.

## Quick start

From the repo root:

```bash
bash v2/plugin.sh          # interactive wizard — scaffolds a working plugin
```

Answer a couple of prompts and you get a plugin that already responds at
`GET /v2/<yourplugin>/ping`. That's it — it's live on the next request.

Other commands:

```bash
bash v2/plugin.sh list             # what's installed + enabled
bash v2/plugin.sh check <Name>     # lint & validate a plugin
bash v2/plugin.sh remove <Name>    # delete one (asks to confirm)
```

The shipped [`Example/`](Example/) plugin is the smallest working reference —
read it, hit `GET /v2/example/hello/World`, then delete it.

## Anatomy

```
v2/plugins/
  Billing/                         ← the plugin (PascalCase = namespace + prefix)
    plugin.json                    ← metadata + enabled flag
    src/                           ← autoloaded as  Plugins\Billing\…
      BillingPlugin.php            ← REQUIRED entry class: Plugins\Billing\BillingPlugin
      Controllers/
        InvoiceController.php
      Models/  Support/  …         ← anything you like
    assets/                        ← read-only static files (optional)
    README.md
```

Discovery is **by convention**: a directory `Billing/` must define the entry
class `Plugins\Billing\BillingPlugin` at `src/BillingPlugin.php`, implementing
[`Plugin`](../src/Plugin/Plugin.php) (extend
[`AbstractPlugin`](../src/Plugin/AbstractPlugin.php)). The entry class takes **no
constructor arguments** — dependencies come from the container.

## The two hooks

```php
final class BillingPlugin extends AbstractPlugin
{
    // 1. Add YOUR services. New ids only — you cannot replace a core service.
    public function register(PluginRegistrar $registrar): void
    {
        $registrar->singleton(
            InvoiceController::class,
            static fn(Container $c): InvoiceController
                => new InvoiceController($c->get(Repository::class)), // inject the SHARED core service
        );
    }

    // 2. Add routes. Auto-prefixed with your slug → these live at /v2/billing/…
    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/invoices/{id}', static fn(Request $r, array $p): Response
            => $container->get(InvoiceController::class)->show($p['id']));
    }
}
```

Because bindings and route handlers are **lazy closures**, load order never
matters — a plugin can freely reference a service another plugin registers.

## The URL prefix (slug) rule

Every route you register is automatically prefixed with your plugin's **slug**
— the lowercased directory name. `Billing`'s `get('/invoices')` is served at
`GET /v2/billing/invoices`. This is what guarantees two plugins can't collide.

At boot the manager refuses a slug that clashes with a core prefix
(`info`, `health`, `webhooks`, `_debug`, `capabilities`, `stream`, `hive`) or
with a configured `resources` name — it fails loudly rather than let a core
route silently swallow your endpoints.

## Rules (same discipline as the core)

These are enforced or checked, not just suggested:

- **Database access goes through the core.** Inject `Connection` / `Repository`
  in your factory closures — never open your own mysqli/PDO/Redis handle. You
  get the shared, persistent, prepared-statement, schema-whitelisted layer for
  free.
- **You may add services, never replace them.** `PluginRegistrar` throws if you
  register an id the core (or another plugin) already owns.
- **No writable state in the code tree.** Caches → Redis, logs → the configured
  `local_log.path`, uploads/queues → outside the tree. See
  [sec-writable-state-outside-code](../../.claude/rules/sec-writable-state-outside-code.md).
- **PHP 8.3, strict types, typed everything.** Plugins follow the same
  [`.claude/rules/`](../../.claude/rules/) as `v2/src/` (this is modern code,
  unlike v1's core). Validate with `bash v2/plugin.sh check <Name>`.

## `plugin.json`

Optional but recommended. All fields have defaults.

```json
{
    "name": "Billing",
    "slug": "billing",
    "description": "Invoicing endpoints.",
    "version": "1.0.0",
    "author": "You",
    "enabled": true,
    "dependencies": []
}
```

- `enabled: false` → the plugin is skipped entirely (a clean off switch that
  keeps the code in place).
- `dependencies: ["Other"]` → boot fails loudly if `Other` isn't installed and
  enabled. (It does **not** reorder loading — thanks to lazy resolution it
  doesn't need to.)
- `slug` here is informational; the authoritative slug is always the lowercased
  directory name.

## How loading works

`PluginManager` (invoked once from `bootstrap.php`, and for routes from
`routes.php`) scans this directory alphabetically. For each enabled plugin it
registers the `Plugins\<Name>\` namespace, instantiates the entry class, calls
`register()` at bootstrap and `routes()` when the HTTP route table is built.
A malformed or broken plugin throws a `PluginException` and stops the service —
by design (fail loud, don't run half-loaded).

## Surviving core updates

This directory is **yours**. The auto-updater replaces core files under
`v2/src/` (and the two integration hooks in `bootstrap.php`/`routes.php`) but
never touches `v2/plugins/`. Keep your plugins here and updates won't disturb
them. (You can safely delete the shipped `Example/`.)
