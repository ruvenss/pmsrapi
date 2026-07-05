<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Http;

use Closure;

/**
 * A middleware wraps the next handler: it may short-circuit (throw / return
 * early) or let the request continue by calling $next.
 */
interface Middleware
{
    /**
     * @param Closure(Request): Response $next
     */
    public function process(Request $request, Closure $next): Response;
}
