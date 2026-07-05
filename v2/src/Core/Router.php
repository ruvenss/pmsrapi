<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Core;

use Closure;
use Pmsrapi\V2\Exception\MethodNotAllowedException;
use Pmsrapi\V2\Exception\NotFoundException;
use Pmsrapi\V2\Http\HttpMethod;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

/**
 * Trie-free regex router. Templates use "{name}" placeholders that match a
 * single path segment; matched values are passed to the handler as params.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /**
     * @param Closure(Request, array<string, string>): Response $handler
     */
    public function add(HttpMethod $method, string $template, Closure $handler): void
    {
        [$regex, $params] = $this->compile($template);
        $this->routes[] = new Route($method, $regex, $params, $handler);
    }

    /** @param Closure(Request, array<string, string>): Response $handler */
    public function get(string $template, Closure $handler): void
    {
        $this->add(HttpMethod::GET, $template, $handler);
    }

    /** @param Closure(Request, array<string, string>): Response $handler */
    public function post(string $template, Closure $handler): void
    {
        $this->add(HttpMethod::POST, $template, $handler);
    }

    /** @param Closure(Request, array<string, string>): Response $handler */
    public function put(string $template, Closure $handler): void
    {
        $this->add(HttpMethod::PUT, $template, $handler);
    }

    /** @param Closure(Request, array<string, string>): Response $handler */
    public function patch(string $template, Closure $handler): void
    {
        $this->add(HttpMethod::PATCH, $template, $handler);
    }

    /** @param Closure(Request, array<string, string>): Response $handler */
    public function delete(string $template, Closure $handler): void
    {
        $this->add(HttpMethod::DELETE, $template, $handler);
    }

    /**
     * Dispatch a request to the first matching route.
     *
     * @throws NotFoundException          when no route matches the path
     * @throws MethodNotAllowedException  when the path matches but the verb does not
     */
    public function dispatch(Request $request): Response
    {
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (preg_match($route->regex, $request->path, $matches) !== 1) {
                continue;
            }

            if ($route->method !== $request->method) {
                $allowedMethods[$route->method->value] = true;
                continue;
            }

            $params = [];
            foreach ($route->paramNames as $name) {
                $params[$name] = $matches[$name];
            }

            return ($route->handler)($request, $params);
        }

        if ($allowedMethods !== []) {
            throw new MethodNotAllowedException(array_keys($allowedMethods));
        }

        throw new NotFoundException("No route for {$request->method->value} {$request->path}");
    }

    /**
     * @return array{0: string, 1: list<string>} compiled regex and ordered param names
     */
    private function compile(string $template): array
    {
        $params = [];
        $path = '/' . trim($template, '/');

        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $path,
        );

        return ['#^' . $regex . '$#', $params];
    }
}
