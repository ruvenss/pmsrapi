<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Plugin;

/**
 * Immutable record of one discovered, enabled plugin — what the
 * {@see PluginManager} resolved from a `v2/plugins/<Name>/` directory.
 *
 * DO NOT MODIFY (core).
 */
final readonly class LoadedPlugin
{
    /**
     * @param non-empty-string $name         directory / namespace segment (PascalCase)
     * @param non-empty-string $slug          lowercased URL prefix
     * @param string           $directory     absolute path to the plugin directory
     * @param list<string>     $dependencies  names of other plugins this one requires
     */
    public function __construct(
        public string $name,
        public string $slug,
        public string $directory,
        public Plugin $instance,
        public string $version,
        public array $dependencies,
    ) {}
}
