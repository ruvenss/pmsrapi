<?php

declare(strict_types=1);

namespace Plugins\Example;

use Plugins\Example\Controllers\GreetingController;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Plugin\AbstractPlugin;
use Pmsrapi\V2\Plugin\PluginRegistrar;
use Pmsrapi\V2\Plugin\PluginRouter;

/**
 * Example plugin — the smallest thing that proves the wiring works.
 *
 * It registers one service (GreetingController) and one route. Because the
 * PluginRouter auto-prefixes with this plugin's slug ("example"), the route is
 * served at:
 *
 *   GET /v2/example/hello/{name}
 *
 * This file is a template you can copy. Delete this whole directory (or set
 * "enabled": false in plugin.json) once you've seen how it works.
 */
final class ExamplePlugin extends AbstractPlugin
{
    public function register(PluginRegistrar $registrar): void
    {
        // Register YOUR OWN services here. Resolve core services (Connection,
        // Repository, Logger, …) inside the closure via $c — never build your
        // own DB/Redis handle.
        $registrar->singleton(
            GreetingController::class,
            static fn(Container $c): GreetingController => new GreetingController(),
        );
    }

    public function routes(PluginRouter $router, Container $container): void
    {
        $router->get('/hello/{name}', static fn(Request $request, array $params): Response
            => $container->get(GreetingController::class)->hello($params['name']));
    }
}
