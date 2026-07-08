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
use Pmsrapi\V2\Http\Controllers\CapabilitiesController;
use Pmsrapi\V2\Http\Controllers\CrudController;
use Pmsrapi\V2\Http\Controllers\DebugController;
use Pmsrapi\V2\Http\Controllers\HiveController;
use Pmsrapi\V2\Http\Controllers\StreamController;
use Pmsrapi\V2\Http\Controllers\SystemController;
use Pmsrapi\V2\Http\Controllers\WebhookController;
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
        // Advanced read (GROUP BY / aggregates / GROUP_CONCAT / CONCAT / HAVING).
        $router->post("/{$def->name}/query", static fn(Request $r, array $p): Response
            => $container->get(CrudController::class)->query($def, $r));
    }

    if ($def->allows(HttpMethod::POST)) {
        $router->post("/{$def->name}", static fn(Request $r, array $p): Response
            => $container->get(CrudController::class)->store($def, $r));
        // Upsert — insert or "IF EXISTS THEN UPDATE".
        $router->post("/{$def->name}/upsert", static fn(Request $r, array $p): Response
            => $container->get(CrudController::class)->upsert($def, $r));
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

// --- Webhook registry management (runtime-managed external JSON store) ---
$router->get('/webhooks', static fn(Request $r, array $p): Response
    => $container->get(WebhookController::class)->index($r));
$router->post('/webhooks', static fn(Request $r, array $p): Response
    => $container->get(WebhookController::class)->store($r));
// Static subpath BEFORE the {id} routes so it is not swallowed by {id}.
$router->post('/webhooks/rebuild', static fn(Request $r, array $p): Response
    => $container->get(WebhookController::class)->rebuild($r));
$router->get('/webhooks/{id}', static fn(Request $r, array $p): Response
    => $container->get(WebhookController::class)->show($r, $p['id']));
$router->put('/webhooks/{id}', static fn(Request $r, array $p): Response
    => $container->get(WebhookController::class)->update($r, $p['id']));
$router->patch('/webhooks/{id}', static fn(Request $r, array $p): Response
    => $container->get(WebhookController::class)->update($r, $p['id']));
$router->delete('/webhooks/{id}', static fn(Request $r, array $p): Response
    => $container->get(WebhookController::class)->destroy($r, $p['id']));
$router->post('/webhooks/{id}/enable', static fn(Request $r, array $p): Response
    => $container->get(WebhookController::class)->enable($r, $p['id']));
$router->post('/webhooks/{id}/disable', static fn(Request $r, array $p): Response
    => $container->get(WebhookController::class)->disable($r, $p['id']));

// --- Live debug dashboard (only when debug.enabled) ---
if ((bool) $config->secret('debug.enabled', false)) {
    $router->get('/_debug', static fn(Request $r, array $p): Response
        => $container->get(DebugController::class)->dashboard($r));
    $router->get('/_debug/status', static fn(Request $r, array $p): Response
        => $container->get(DebugController::class)->status($r));
    $router->get('/_debug/events', static fn(Request $r, array $p): Response
        => $container->get(DebugController::class)->events($r));
    $router->post('/_debug/arm', static fn(Request $r, array $p): Response
        => $container->get(DebugController::class)->arm($r));
    $router->post('/_debug/disarm', static fn(Request $r, array $p): Response
        => $container->get(DebugController::class)->disarm($r));
    $router->delete('/_debug/events', static fn(Request $r, array $p): Response
        => $container->get(DebugController::class)->clear($r));
}

// --- Cluster: capability manifest + inter-service NDJSON streaming ---
$router->get('/capabilities', static fn(Request $r, array $p): Response
    => $container->get(CapabilitiesController::class)->show($r));
$router->get('/stream/_demo', static fn(Request $r, array $p): Response
    => $container->get(StreamController::class)->demo($r));
$router->get('/stream/{resource}', static fn(Request $r, array $p): Response
    => $container->get(StreamController::class)->export($r, $p['resource']));

// --- Hive coordinator (DEVELOPMENT only; registered only when role = hive_mind) ---
if ($config->isHiveMind()) {
    $router->get('/hive', static fn(Request $r, array $p): Response
        => $container->get(HiveController::class)->graph($r));
    $router->get('/hive/map', static fn(Request $r, array $p): Response
        => $container->get(HiveController::class)->map($r));
    $router->get('/hive/collisions', static fn(Request $r, array $p): Response
        => $container->get(HiveController::class)->collisions($r));
    $router->post('/hive/refresh', static fn(Request $r, array $p): Response
        => $container->get(HiveController::class)->refresh($r));
    $router->post('/hive/register', static fn(Request $r, array $p): Response
        => $container->get(HiveController::class)->register($r));
    $router->get('/hive/export/{service}', static fn(Request $r, array $p): Response
        => $container->get(HiveController::class)->export($r, $p['service']));
}

// --- Add your own custom routes here (they survive core updates) ---
// $router->get('/forecast/{city}', static fn(Request $r, array $p): Response => ...);

return $router;
