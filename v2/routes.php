<?php

declare(strict_types=1);

/**
 * Route table. Returns a configured Router.
 *
 * System routes are declared explicitly; CRUD routes are generated from the
 * "resources" block of the secret config, so exposing a table as a REST
 * resource is a config change, not a code change.
 *
 * $container is in scope (index.php / worker.php require this file). Add your
 * own custom endpoints below the generated block.
 */

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Core\Router;
use Pmsrapi\V2\Http\Controllers\CrudController;
use Pmsrapi\V2\Http\Controllers\SystemController;
use Pmsrapi\V2\Http\HttpMethod;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\ResourceDefinition;
use Pmsrapi\V2\Http\Response;

/** @var Container $container */
$router = new Router();
$config = $container->get(Config::class);

// --- System endpoints ---
$router->get('/', static fn(Request $r, array $p): Response => $container->get(SystemController::class)->info($r));
$router->get('/info', static fn(Request $r, array $p): Response => $container->get(SystemController::class)->info($r));
$router->get('/health', static fn(Request $r, array $p): Response => $container->get(SystemController::class)->health($r));

// --- Config-driven CRUD resources ---
foreach ($config->resources() as $name => $resourceConfig) {
    $def = ResourceDefinition::fromConfig((string) $name, (array) $resourceConfig);

    if ($def->allows(HttpMethod::GET)) {
        $router->get("/{$def->name}", static fn(Request $r, array $p): Response
            => $container->get(CrudController::class)->index($def, $r));
        $router->get("/{$def->name}/{id}", static fn(Request $r, array $p): Response
            => $container->get(CrudController::class)->show($def, $r, $p['id']));
    }

    if ($def->allows(HttpMethod::POST)) {
        $router->post("/{$def->name}", static fn(Request $r, array $p): Response
            => $container->get(CrudController::class)->store($def, $r));
    }

    if ($def->allows(HttpMethod::PUT)) {
        $router->put("/{$def->name}/{id}", static fn(Request $r, array $p): Response
            => $container->get(CrudController::class)->update($def, $r, $p['id']));
        $router->patch("/{$def->name}/{id}", static fn(Request $r, array $p): Response
            => $container->get(CrudController::class)->update($def, $r, $p['id']));
    }

    if ($def->allows(HttpMethod::DELETE)) {
        $router->delete("/{$def->name}/{id}", static fn(Request $r, array $p): Response
            => $container->get(CrudController::class)->destroy($def, $r, $p['id']));
    }
}

// --- Add your own custom routes here (they survive core updates) ---
// $router->get('/forecast/{city}', static fn(Request $r, array $p): Response => ...);

return $router;
