<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Core;

use Closure;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\RateLimitException;
use Pmsrapi\V2\Http\HttpMethod;
use Pmsrapi\V2\Http\Middleware;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Http\Middleware\RateLimitMiddleware;
use Pmsrapi\V2\Http\Middleware\AuthMiddleware;

/**
 * Orchestrates one request: builds the middleware pipeline (rate-limit ->
 * auth), routes it, and normalizes any ApiException into an envelope. CORS
 * headers are attached to every outgoing response.
 */
final class Kernel
{
    public function __construct(
        private readonly Container $container,
        private readonly Router $router,
    ) {}

    public function handle(Request $request): Response
    {
        // CORS preflight never needs auth or a body.
        if ($request->method === HttpMethod::OPTIONS) {
            return $this->withCors($request, Response::noContent());
        }

        try {
            $response = $this->pipeline()($request);
        } catch (ApiException $e) {
            $response = Response::fromApiException($e);
            if ($e instanceof RateLimitException) {
                $response = $response->withHeader('Retry-After', (string) $e->retryAfter);
            }
        }

        return $this->withCors($request, $response);
    }

    private function pipeline(): Closure
    {
        $core = fn(Request $request): Response => $this->router->dispatch($request);

        return array_reduce(
            array_reverse($this->middleware()),
            static fn(Closure $next, Middleware $mw): Closure
                => static fn(Request $request): Response => $mw->process($request, $next),
            $core,
        );
    }

    /**
     * @return list<Middleware>
     */
    private function middleware(): array
    {
        // Order matters: throttle abusive callers before verifying tokens.
        return [
            $this->container->get(RateLimitMiddleware::class),
            $this->container->get(AuthMiddleware::class),
        ];
    }

    private function withCors(Request $request, Response $response): Response
    {
        $headers = $this->container->get(Config::class)->public('headers', []);
        foreach ((array) $headers as $name => $value) {
            $response = $response->withHeader((string) $name, (string) $value);
        }
        return $response;
    }
}
