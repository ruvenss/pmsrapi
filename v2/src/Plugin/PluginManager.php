<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Plugin;

use Pmsrapi\V2\Core\Autoloader;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Core\Router;
use Pmsrapi\V2\Exception\PluginException;
use Pmsrapi\V2\Support\Logger;

/**
 * Discovers developer plugins under `v2/plugins/` and wires them into the
 * running core — the single mechanism that lets a plugin add services, models,
 * controllers and routes WITHOUT editing any core file.
 *
 * A plugin is a directory `v2/plugins/<Name>/` that:
 *   - registers the PSR-4 namespace `Plugins\<Name>\` → `<dir>/src`, and
 *   - defines the entry class `Plugins\<Name>\<Name>Plugin` implementing
 *     {@see Plugin} (usually by extending {@see AbstractPlugin}).
 *
 * Load order is deterministic (alphabetical). Because container bindings and
 * route handlers are all lazy closures, order does not affect the dependency
 * graph — a plugin may freely reference services another plugin registers.
 *
 * DO NOT MODIFY (core). This is invoked from bootstrap.php and routes.php; a
 * plugin never calls it directly.
 */
final class PluginManager
{
    /**
     * Core URL prefixes a plugin slug must never collide with (a plugin slug is
     * its lowercased directory name). Configured `resources` names are added to
     * this set at route-registration time. Keep in sync with routes.php.
     */
    private const RESERVED_PREFIXES = [
        '',
        'info',
        'health',
        'webhooks',
        '_debug',
        'capabilities',
        'stream',
        'hive',
    ];

    /** @var list<LoadedPlugin> discovered, enabled plugins in load order */
    private array $plugins = [];

    private bool $discovered = false;

    public function __construct(
        private readonly string $pluginsDir,
        private readonly Autoloader $autoloader,
        private readonly Logger $logger,
    ) {}

    /**
     * Scan the plugins directory once, registering each enabled plugin's
     * namespace and instantiating its entry class. Idempotent.
     *
     * @throws PluginException when a plugin directory is malformed
     */
    public function discover(): void
    {
        if ($this->discovered) {
            return;
        }
        $this->discovered = true;

        if (!is_dir($this->pluginsDir)) {
            return;
        }

        $entries = scandir($this->pluginsDir);
        if ($entries === false) {
            return;
        }
        sort($entries, SORT_STRING); // deterministic, alphabetical load order

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $dir = $this->pluginsDir . '/' . $entry;
            if (!is_dir($dir)) {
                continue;
            }

            $loaded = $this->load($entry, $dir);
            if ($loaded !== null) {
                $this->plugins[] = $loaded;
            }
        }

        $this->assertNoDuplicateSlugsAndDependencies();

        if ($this->plugins !== []) {
            $this->logger->info('Plugins loaded', [
                'count' => count($this->plugins),
                'plugins' => array_map(static fn(LoadedPlugin $p): string => $p->name, $this->plugins),
            ]);
        }
    }

    /**
     * Let every plugin register its services. Runs at bootstrap time (once per
     * process), so plugin services are available to HTTP requests, the CLI
     * worker, and any other entry point.
     */
    public function registerServices(Container $container): void
    {
        $this->discover();

        foreach ($this->plugins as $plugin) {
            $registrar = new PluginRegistrar($container, $plugin->name);
            $plugin->instance->register($registrar);
        }
    }

    /**
     * Mount every plugin's routes under its slug prefix. Runs when routes.php is
     * built (HTTP only). Fails loudly if a slug collides with a core prefix or a
     * configured resource, which would otherwise let a core route silently
     * swallow the plugin's endpoints.
     */
    public function registerRoutes(Router $router, Container $container): void
    {
        $this->discover();

        $reserved = $this->reservedPrefixes($container);

        foreach ($this->plugins as $plugin) {
            if (in_array($plugin->slug, $reserved, true)) {
                throw new PluginException(sprintf(
                    "Plugin '%s' maps to the URL prefix '/%s', which is reserved by the core or an "
                    . 'existing resource. Rename the plugin directory to something unique.',
                    $plugin->name,
                    $plugin->slug,
                ));
            }

            $pluginRouter = new PluginRouter($router, $plugin->slug);
            $plugin->instance->routes($pluginRouter, $container);
        }
    }

    /**
     * Diagnostics for /info-style reporting or the CLI.
     *
     * @return list<array{name: string, slug: string, version: string}>
     */
    public function manifest(): array
    {
        $this->discover();

        return array_map(
            static fn(LoadedPlugin $p): array => [
                'name' => $p->name,
                'slug' => $p->slug,
                'version' => $p->version,
            ],
            $this->plugins,
        );
    }

    /**
     * Resolve a single plugin directory into a LoadedPlugin, or null when the
     * directory is not a plugin (no manifest and no entry class) or is disabled.
     */
    private function load(string $name, string $dir): ?LoadedPlugin
    {
        $hasManifest = is_file($dir . '/plugin.json');
        $hasEntry = is_file($dir . '/src/' . $name . 'Plugin.php');

        // Not a plugin directory — ignore quietly (tooling, .git, assets, …).
        if (!$hasManifest && !$hasEntry) {
            return null;
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new PluginException(sprintf(
                "Plugin directory '%s' is not a valid name. Use a PascalCase identifier "
                . "(letters and digits, starting with a letter) — e.g. 'Billing'.",
                $name,
            ));
        }

        $manifest = $this->readManifest($dir);

        if (array_key_exists('enabled', $manifest) && $manifest['enabled'] === false) {
            $this->logger->info('Plugin disabled, skipping', ['plugin' => $name]);
            return null;
        }

        // Register the plugin's namespace so its classes autoload.
        $this->autoloader->addNamespace('Plugins\\' . $name, $dir . '/src');

        $class = 'Plugins\\' . $name . '\\' . $name . 'Plugin';
        if (!class_exists($class)) {
            throw new PluginException(sprintf(
                "Plugin '%s' must define its entry class %s at %s/src/%sPlugin.php.",
                $name,
                $class,
                $dir,
                $name,
            ));
        }

        $instance = new $class();
        if (!$instance instanceof Plugin) {
            throw new PluginException(sprintf(
                'Plugin entry class %s must implement %s (extend %s).',
                $class,
                Plugin::class,
                AbstractPlugin::class,
            ));
        }

        /** @var list<string> $dependencies */
        $dependencies = array_values(array_filter(
            (array) ($manifest['dependencies'] ?? []),
            'is_string',
        ));

        return new LoadedPlugin(
            name: $name,
            slug: strtolower($name),
            directory: $dir,
            instance: $instance,
            version: (string) ($manifest['version'] ?? '0.0.0'),
            dependencies: $dependencies,
        );
    }

    /**
     * @return array<string, mixed>
     * @throws PluginException when plugin.json exists but is not valid JSON
     */
    private function readManifest(string $dir): array
    {
        $file = $dir . '/plugin.json';
        if (!is_file($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        if ($raw === false || !json_validate($raw)) {
            throw new PluginException("Invalid plugin.json (not readable or not valid JSON): {$file}");
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * @return list<string> reserved prefixes = core prefixes + configured resource names
     */
    private function reservedPrefixes(Container $container): array
    {
        $reserved = self::RESERVED_PREFIXES;

        foreach (array_keys($container->get(Config::class)->resources()) as $resource) {
            $reserved[] = strtolower((string) $resource);
        }

        return $reserved;
    }

    private function assertNoDuplicateSlugsAndDependencies(): void
    {
        /** @var array<string, string> $slugs slug => owning plugin name */
        $slugs = [];
        foreach ($this->plugins as $plugin) {
            if (isset($slugs[$plugin->slug])) {
                throw new PluginException(sprintf(
                    "Plugins '%s' and '%s' both map to the URL prefix '/%s'. Plugin names must be unique "
                    . 'once lowercased.',
                    $slugs[$plugin->slug],
                    $plugin->name,
                    $plugin->slug,
                ));
            }
            $slugs[$plugin->slug] = $plugin->name;
        }

        $names = array_map(static fn(LoadedPlugin $p): string => $p->name, $this->plugins);
        foreach ($this->plugins as $plugin) {
            foreach ($plugin->dependencies as $dependency) {
                if (!in_array($dependency, $names, true)) {
                    throw new PluginException(sprintf(
                        "Plugin '%s' requires plugin '%s', which is not installed or is disabled.",
                        $plugin->name,
                        $dependency,
                    ));
                }
            }
        }
    }
}
