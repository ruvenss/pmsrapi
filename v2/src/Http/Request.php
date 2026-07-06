<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Http;

use Pmsrapi\V2\Exception\ApiException;

/**
 * Immutable snapshot of the incoming HTTP request.
 *
 * The routable path has the mount prefix (/v2) stripped, so routes are written
 * as "/users/{id}" regardless of where the service is mounted.
 */
final class Request
{
    /**
     * @param array<string, string>  $headers lowercased header name => value
     * @param array<string, string>  $query
     * @param array<string, mixed>   $body    decoded JSON body (empty if none)
     */
    public function __construct(
        public readonly HttpMethod $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly array $query,
        public readonly array $body,
        public readonly string $rawBody,
        public readonly string $ip,
    ) {}

    public static function fromGlobals(string $mountPrefix = '/v2'): self
    {
        $method = HttpMethod::fromRequest($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = self::normalizePath(is_string($uriPath) ? urldecode($uriPath) : '/', $mountPrefix);

        $headers = self::readHeaders();

        $rawBody = file_get_contents('php://input');
        $rawBody = $rawBody === false ? '' : $rawBody;
        $body = self::decodeBody($rawBody, $headers['content-type'] ?? '');

        /** @var array<string, string> $query */
        $query = $_GET;

        return new self(
            method: $method,
            path: $path,
            headers: $headers,
            query: $query,
            body: $body,
            rawBody: $rawBody,
            ip: self::clientIp(),
        );
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');
        if ($header === null) {
            return null;
        }

        $parts = explode(' ', $header, 2);
        if (count($parts) === 2 && strcasecmp($parts[0], 'Bearer') === 0) {
            return trim($parts[1]);
        }

        return null;
    }

    /**
     * @return list<string> path split into non-empty segments
     */
    public function segments(): array
    {
        return array_values(array_filter(explode('/', $this->path), static fn(string $s): bool => $s !== ''));
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? $default;
        return $value === null ? null : (string) $value;
    }

    public function queryInt(string $key, int $default): int
    {
        $value = filter_var($this->query[$key] ?? null, FILTER_VALIDATE_INT);
        return $value === false ? $default : $value;
    }

    private static function normalizePath(string $path, string $mountPrefix): string
    {
        $path = '/' . trim($path, '/');

        $mountPrefix = '/' . trim($mountPrefix, '/');
        if ($mountPrefix !== '/' && str_starts_with($path, $mountPrefix)) {
            $path = substr($path, strlen($mountPrefix));
        }

        return $path === '' ? '/' : '/' . trim($path, '/');
    }

    /**
     * @return array<string, string>
     */
    private static function readHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }
        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeBody(string $rawBody, string $contentType): array
    {
        if ($rawBody === '') {
            return [];
        }

        if (!str_starts_with($contentType, 'application/json')) {
            throw new ApiException('Only application/json request bodies are supported', 400, 'unsupported_media_type');
        }

        if (!json_validate($rawBody)) {
            throw new ApiException('Malformed JSON in request body', 400, 'invalid_json');
        }

        $decoded = json_decode($rawBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function clientIp(): string
    {
        // Trust the socket peer by default. If you terminate TLS at a proxy,
        // validate X-Forwarded-For upstream before relying on it here.
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
