<?php

declare(strict_types=1);

/**
 * v2 bootstrap — the single composition root.
 *
 * Wires the autoloader, loads config, and registers every service in the DI
 * container as a lazy singleton. Both the HTTP front controller (index.php) and
 * the CLI worker (worker.php) start here, so wiring lives in exactly one place.
 *
 * DO NOT MODIFY (core). Add routes in routes.php; add your own services by
 * dropping namespaced classes under v2/src/ and registering them here or
 * resolving them where needed.
 */

use Pmsrapi\V2\Cache\QueryCache;
use Pmsrapi\V2\Cache\RateLimiter;
use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Core\Autoloader;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Database\Connection;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Database\Schema;
use Pmsrapi\V2\Http\Controllers\CrudController;
use Pmsrapi\V2\Http\Controllers\SystemController;
use Pmsrapi\V2\Http\Middleware\AuthMiddleware;
use Pmsrapi\V2\Http\Middleware\RateLimitMiddleware;
use Pmsrapi\V2\Queue\RedisQueue;
use Pmsrapi\V2\Queue\WebhookDispatcher;
use Pmsrapi\V2\Security\TokenStore;
use Pmsrapi\V2\Support\Logger;

define('V2_BASE', __DIR__);

require V2_BASE . '/src/Core/Autoloader.php';

$autoloader = new Autoloader();
$autoloader->addNamespace('Pmsrapi\\V2', V2_BASE . '/src');
$autoloader->register();

$config = Config::load(V2_BASE . '/config.php');

// Fail loud in dev/test, quiet in prod — but never silence errors (.claude/rules/error-never-suppress).
error_reporting(E_ALL);
ini_set('display_errors', $config->isProduction() ? '0' : '1');

$container = new Container();
$container->instance(Config::class, $config);

$container->singleton(Logger::class, static fn(Container $c): Logger => new Logger($c->get(Config::class)));

$container->singleton(RedisClient::class, static fn(Container $c): RedisClient => new RedisClient($c->get(Config::class)));

$container->singleton(Connection::class, static fn(Container $c): Connection => new Connection($c->get(Config::class)));

$container->singleton(QueryCache::class, static fn(Container $c): QueryCache => new QueryCache(
    $c->get(RedisClient::class),
    $c->get(Logger::class),
    (int) $c->get(Config::class)->secret('cache.ttl', 30),
));

$container->singleton(RateLimiter::class, static fn(Container $c): RateLimiter => new RateLimiter(
    $c->get(RedisClient::class),
    $c->get(Logger::class),
    (bool) $c->get(Config::class)->secret('rate_limit.fail_open', true),
));

$container->singleton(TokenStore::class, static fn(Container $c): TokenStore => new TokenStore(
    $c->get(RedisClient::class),
    $c->get(Logger::class),
));

$container->singleton(Schema::class, static fn(Container $c): Schema => new Schema(
    $c->get(Connection::class),
    $c->get(QueryCache::class),
));

$container->singleton(Repository::class, static fn(Container $c): Repository => new Repository(
    $c->get(Connection::class),
    $c->get(Schema::class),
));

$container->singleton(RedisQueue::class, static fn(Container $c): RedisQueue => new RedisQueue($c->get(RedisClient::class)));

$container->singleton(WebhookDispatcher::class, static fn(Container $c): WebhookDispatcher => new WebhookDispatcher(
    $c->get(RedisQueue::class),
    $c->get(Config::class),
    $c->get(Logger::class),
));

$container->singleton(CrudController::class, static fn(Container $c): CrudController => new CrudController(
    $c->get(Repository::class),
    $c->get(Schema::class),
    $c->get(QueryCache::class),
    $c->get(WebhookDispatcher::class),
));

$container->singleton(SystemController::class, static fn(Container $c): SystemController => new SystemController(
    $c->get(Config::class),
    $c->get(Connection::class),
    $c->get(RedisClient::class),
));

$container->singleton(AuthMiddleware::class, static fn(Container $c): AuthMiddleware => new AuthMiddleware(
    $c->get(Config::class),
    $c->get(TokenStore::class),
));

$container->singleton(RateLimitMiddleware::class, static fn(Container $c): RateLimitMiddleware => new RateLimitMiddleware(
    $c->get(Config::class),
    $c->get(RateLimiter::class),
));

return $container;
