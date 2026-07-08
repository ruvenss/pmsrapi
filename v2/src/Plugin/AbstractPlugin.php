<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Plugin;

use Pmsrapi\V2\Core\Container;

/**
 * Convenience base class for plugins. Both hooks default to no-ops, so a plugin
 * that only adds routes (or only registers services) overrides just one method.
 *
 * DO NOT MODIFY (core). Extend this from your plugin under v2/plugins/.
 */
abstract class AbstractPlugin implements Plugin
{
    public function register(PluginRegistrar $registrar): void
    {
    }

    public function routes(PluginRouter $router, Container $container): void
    {
    }
}
