<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Http\Middleware;

use Closure;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\UnauthorizedException;
use Pmsrapi\V2\Http\Middleware;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Security\TokenStore;

/**
 * Bearer-token authentication.
 *
 * Accepts the static master token (ms_server_token, compared with hash_equals
 * to avoid timing leaks — same as v1) OR any live token in the Redis TokenStore
 * (which additionally supports TTL and revocation).
 *
 * A short allowlist of paths (health checks) is public.
 */
final class AuthMiddleware implements Middleware
{
    /** @var list<string> */
    private const PUBLIC_PATHS = ['/health'];

    public function __construct(
        private readonly Config $config,
        private readonly TokenStore $tokens,
    ) {}

    public function process(Request $request, Closure $next): Response
    {
        if (in_array($request->path, self::PUBLIC_PATHS, true)) {
            return $next($request);
        }

        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            throw new UnauthorizedException('Missing or malformed Authorization: Bearer header');
        }

        if (hash_equals($this->config->serverToken(), $token) || $this->tokens->isValid($token)) {
            return $next($request);
        }

        throw new UnauthorizedException('Invalid API token');
    }
}
