<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Plugin;

use Pmsrapi\V2\Core\Container;

/**
 * The contract every plugin's entry class implements.
 *
 * A plugin is a self-contained developer extension living under `v2/plugins/`.
 * It contributes services and routes to the running core WITHOUT editing any
 * core file — the whole point of the plugin system. Most authors extend
 * {@see AbstractPlugin} and override only what they need.
 *
 * Discovery is by convention: a directory `v2/plugins/Foo/` must define
 * `Plugins\Foo\FooPlugin` at `v2/plugins/Foo/src/FooPlugin.php`. The entry class
 * takes NO constructor arguments — dependencies are pulled from the container
 * inside register()/routes().
 *
 * DO NOT MODIFY (core). Build plugins under v2/plugins/, not here.
 */
interface Plugin
{
    /**
     * Register this plugin's own services in the container.
     *
     * Use $registrar->singleton()/bind() to add NEW service ids. Resolve core
     * services (Connection, Repository, Logger, …) inside your factory closures
     * via the passed Container — never open your own DB/Redis handle. Replacing
     * an existing (core or other-plugin) id is refused.
     */
    public function register(PluginRegistrar $registrar): void;

    /**
     * Register this plugin's HTTP routes.
     *
     * Every route is automatically prefixed with the plugin's slug, so a plugin
     * "Billing" registering get('/invoices') is served at GET /v2/billing/invoices.
     * That prefix is what keeps two developers' plugins from colliding.
     */
    public function routes(PluginRouter $router, Container $container): void;
}
