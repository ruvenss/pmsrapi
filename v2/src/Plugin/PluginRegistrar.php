<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Plugin;

use Closure;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Exception\PluginException;

/**
 * A collision-guarded facade over the {@see Container} handed to a plugin's
 * register() hook.
 *
 * Plugins may only ADD new service ids; attempting to register an id that is
 * already bound (by the core or another plugin) throws a {@see PluginException}
 * at boot. This preserves v2's rule that there is one shared Connection, one
 * Repository, one Logger, etc. — a plugin decorates the core, it never replaces
 * it silently.
 *
 * DO NOT MODIFY (core).
 */
final class PluginRegistrar
{
    public function __construct(
        private readonly Container $container,
        private readonly string $plugin,
    ) {}

    /**
     * Register a lazily-built shared service.
     *
     * @param Closure(Container): mixed $factory
     */
    public function singleton(string $id, Closure $factory): void
    {
        $this->guard($id);
        $this->container->singleton($id, $factory);
    }

    /**
     * Register a factory binding (a fresh instance per resolution).
     *
     * @param Closure(Container): mixed $factory
     */
    public function bind(string $id, Closure $factory): void
    {
        $this->guard($id);
        $this->container->bind($id, $factory);
    }

    /**
     * Is a service already registered? Useful to depend on a service another
     * plugin may or may not provide.
     */
    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    private function guard(string $id): void
    {
        if ($this->container->has($id)) {
            throw new PluginException(sprintf(
                "Plugin '%s' tried to register service '%s', but it is already registered. "
                . 'Plugins may only ADD new services, never replace core ones. '
                . 'Inject the existing service in your factory instead (see v2/plugins/README.md).',
                $this->plugin,
                $id,
            ));
        }
    }
}
