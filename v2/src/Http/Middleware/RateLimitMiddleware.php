<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Http\Middleware;

use Closure;
use Pmsrapi\V2\Cache\RateLimiter;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\RateLimitException;
use Pmsrapi\V2\Http\Middleware;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

/**
 * Per-caller request throttling via the Redis-backed RateLimiter.
 *
 * The caller is keyed by bearer token when present (so a token's budget is
 * shared across IPs) and falls back to the client IP for anonymous traffic.
 * Adds standard X-RateLimit-* headers; throws 429 with Retry-After when the
 * budget is exhausted.
 *
 * Tunable via secret config:
 *   "rate_limit": { "enabled": true, "max": 120, "window": 60 }
 */
final class RateLimitMiddleware implements Middleware
{
    public function __construct(
        private readonly Config $config,
        private readonly RateLimiter $limiter,
    ) {}

    public function process(Request $request, Closure $next): Response
    {
        // The debug dashboard polls frequently and is admin-only — never throttle it.
        if (str_starts_with($request->path, '/_debug')) {
            return $next($request);
        }

        if ((bool) $this->config->secret('rate_limit.enabled', true) === false) {
            return $next($request);
        }

        $max = (int) $this->config->secret('rate_limit.max', 120);
        $window = (int) $this->config->secret('rate_limit.window', 60);

        $result = $this->limiter->hit($this->identify($request), $max, $window);

        if (!$result->allowed) {
            throw new RateLimitException($result->retryAfter);
        }

        return $next($request)
            ->withHeader('X-RateLimit-Limit', (string) $result->limit)
            ->withHeader('X-RateLimit-Remaining', (string) $result->remaining);
    }

    private function identify(Request $request): string
    {
        $token = $request->bearerToken();
        if ($token !== null && $token !== '') {
            return 'tok:' . substr(hash('sha256', $token), 0, 32);
        }

        return 'ip:' . $request->ip;
    }
}
