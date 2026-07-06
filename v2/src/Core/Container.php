<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Core;

use Closure;
use Pmsrapi\V2\Exception\ConfigException;

/**
 * Tiny dependency-injection container (no Composer packages).
 *
 * Supports pre-built instances, lazily-resolved singletons and plain factory
 * bindings. Enough to keep controllers/services constructor-injected and
 * testable per .claude/rules/perf-avoid-globals + solid-dip — without pulling
 * in a framework.
 */
final class Container
{
    /** @var array<string, Closure(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $shared = [];

    public function instance(string $id, mixed $object): void
    {
        $this->instances[$id] = $object;
    }

    /**
     * @param Closure(self): mixed $factory
     */
    public function bind(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->shared[$id], $this->instances[$id]);
    }

    /**
     * @param Closure(self): mixed $factory
     */
    public function singleton(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        $this->shared[$id] = true;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new ConfigException("Service not registered in container: {$id}");
        }

        $object = ($this->factories[$id])($this);

        if (isset($this->shared[$id])) {
            $this->instances[$id] = $object;
        }

        return $object;
    }
}
