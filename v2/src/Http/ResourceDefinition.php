<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Http;

/**
 * A CRUD resource exposed by config, e.g.:
 *
 *   "resources": {
 *     "users": { "table": "users", "methods": ["GET","POST","PUT","DELETE"],
 *                "cache_ttl": 30, "per_page": 25 }
 *   }
 *
 * This is what lets operators expose a table as a REST resource without writing
 * a controller (the v2 analogue of v1's allowed_functions whitelist).
 */
readonly class ResourceDefinition
{
    /**
     * @param list<HttpMethod> $methods
     */
    public function __construct(
        public string $name,
        public string $table,
        public array $methods,
        public int $cacheTtl,
        public int $perPage,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        $methods = [];
        foreach ((array) ($config['methods'] ?? ['GET', 'POST', 'PUT', 'DELETE']) as $verb) {
            $method = HttpMethod::tryFrom(strtoupper((string) $verb));
            if ($method !== null) {
                $methods[] = $method;
            }
        }

        return new self(
            name: $name,
            table: (string) ($config['table'] ?? $name),
            methods: $methods,
            cacheTtl: (int) ($config['cache_ttl'] ?? 30),
            perPage: (int) ($config['per_page'] ?? 20),
        );
    }

    public function allows(HttpMethod $method): bool
    {
        return in_array($method, $this->methods, true);
    }
}
