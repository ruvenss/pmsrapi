<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Core;

use Closure;
use Pmsrapi\V2\Http\HttpMethod;

/**
 * A single compiled route: a verb, a regex compiled from a "/users/{id}"
 * template, the captured parameter names, and the handler to invoke.
 */
final class Route
{
    /**
     * @param list<string>                                 $paramNames
     * @param Closure(\Pmsrapi\V2\Http\Request, array<string, string>): \Pmsrapi\V2\Http\Response $handler
     */
    public function __construct(
        public readonly HttpMethod $method,
        public readonly string $regex,
        public readonly array $paramNames,
        public readonly Closure $handler,
    ) {}
}
