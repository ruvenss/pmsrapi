<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Plugin;

use Closure;
use Pmsrapi\V2\Core\Router;

/**
 * A thin facade over the core {@see Router} handed to a plugin's routes() hook.
 *
 * Every path is automatically prefixed with the plugin's slug, so plugin routes
 * live under their own URL namespace (e.g. a "Billing" plugin's get('/invoices')
 * becomes GET /v2/billing/invoices). This auto-prefix is what guarantees two
 * independently-developed plugins cannot register colliding routes.
 *
 * The handler signature is identical to the core Router's:
 *   Closure(\Pmsrapi\V2\Http\Request, array<string, string>): \Pmsrapi\V2\Http\Response
 *
 * DO NOT MODIFY (core).
 */
final class PluginRouter
{
    /** @param non-empty-string $prefix the plugin slug, no surrounding slashes */
    public function __construct(
        private readonly Router $router,
        private readonly string $prefix,
    ) {}

    /** @param Closure(\Pmsrapi\V2\Http\Request, array<string, string>): \Pmsrapi\V2\Http\Response $handler */
    public function get(string $path, Closure $handler): void
    {
        $this->router->get($this->prefixed($path), $handler);
    }

    /** @param Closure(\Pmsrapi\V2\Http\Request, array<string, string>): \Pmsrapi\V2\Http\Response $handler */
    public function post(string $path, Closure $handler): void
    {
        $this->router->post($this->prefixed($path), $handler);
    }

    /** @param Closure(\Pmsrapi\V2\Http\Request, array<string, string>): \Pmsrapi\V2\Http\Response $handler */
    public function put(string $path, Closure $handler): void
    {
        $this->router->put($this->prefixed($path), $handler);
    }

    /** @param Closure(\Pmsrapi\V2\Http\Request, array<string, string>): \Pmsrapi\V2\Http\Response $handler */
    public function patch(string $path, Closure $handler): void
    {
        $this->router->patch($this->prefixed($path), $handler);
    }

    /** @param Closure(\Pmsrapi\V2\Http\Request, array<string, string>): \Pmsrapi\V2\Http\Response $handler */
    public function delete(string $path, Closure $handler): void
    {
        $this->router->delete($this->prefixed($path), $handler);
    }

    /** The URL prefix (plugin slug) all routes are mounted under. */
    public function prefix(): string
    {
        return $this->prefix;
    }

    private function prefixed(string $path): string
    {
        return '/' . $this->prefix . '/' . ltrim($path, '/');
    }
}
