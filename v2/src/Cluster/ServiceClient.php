<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Cluster;

use Generator;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\ServiceException;
use Pmsrapi\V2\Support\Logger;

/**
 * The standard client for calling functions on sibling microservices.
 *
 * Resolution is driven entirely by the LOCAL config, so a worker runs
 * standalone in production with no Hive:
 *   - "function_map": name -> { service, method, path, stream }  (baked by the Hive)
 *   - "universe":     [ { name, ip, port, token, ssl } ]         (the nodes)
 *
 * Two call styles:
 *   - call()   — request/response, returns the decoded `data`.
 *   - stream() — NDJSON over chunked HTTP, yielded lazily as a Generator so the
 *                caller processes records as they arrive at constant memory
 *                (see .claude/rules/perf-generators). This is the standard
 *                inter-service streaming transport.
 */
final class ServiceClient
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    /**
     * @param array<string, scalar|null> $params  path/query params
     * @param array<string, mixed>       $payload JSON body for write calls
     * @return array<string, mixed>|list<mixed>
     */
    public function call(string $function, array $params = [], array $payload = []): array
    {
        [$node, $spec] = $this->resolve($function);
        [$url, $body, $method] = $this->prepare($node, $spec, $params, $payload);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $this->headerLines($node),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error !== '' || !is_string($response)) {
            throw new ServiceException("Call to '{$function}' failed: {$error}");
        }
        if (!json_validate($response)) {
            throw new ServiceException("Non-JSON response from '{$function}'");
        }

        $decoded = json_decode($response, true);
        if ($code >= 400) {
            throw new ServiceException("'{$function}' returned HTTP {$code}", $code >= 500 ? 502 : $code);
        }

        return is_array($decoded) ? ($decoded['data'] ?? $decoded) : [];
    }

    /**
     * Stream a function's NDJSON output, one decoded record at a time.
     *
     * @param array<string, scalar|null> $params
     * @param array<string, mixed>       $payload
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(string $function, array $params = [], array $payload = []): Generator
    {
        [$node, $spec] = $this->resolve($function);
        [$url, $body, $method] = $this->prepare($node, $spec, $params, $payload);

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $this->headerLines($node)),
                'content' => $body ?? '',
                'timeout' => 300,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        // Open without the @ operator: swallow the connection warning via a
        // scoped handler, then check the return value explicitly.
        set_error_handler(static fn(): bool => true);
        try {
            $fp = fopen($url, 'r', false, $context);
        } finally {
            restore_error_handler();
        }

        if ($fp === false) {
            throw new ServiceException("Could not open stream for '{$function}' at {$url}");
        }

        try {
            while (($line = fgets($fp)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (json_validate($line)) {
                    /** @var array<string, mixed> $record */
                    $record = json_decode($line, true);
                    yield $record;
                } else {
                    $this->logger->warning('Discarded non-JSON stream line', ['function' => $function]);
                }
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the node and the function spec
     */
    private function resolve(string $function): array
    {
        $map = $this->config->secret('function_map', []);
        if (!is_array($map) || !isset($map[$function]) || !is_array($map[$function])) {
            throw new ServiceException("Unknown function '{$function}' (not in function_map)", 404, 'unknown_function');
        }

        $spec = $map[$function];
        $service = (string) ($spec['service'] ?? '');

        foreach ((array) $this->config->secret('universe', []) as $node) {
            if (is_array($node) && ($node['name'] ?? null) === $service) {
                return [$node, $spec];
            }
        }

        throw new ServiceException("No universe node for service '{$service}' (function '{$function}')", 502, 'no_node');
    }

    /**
     * @param array<string, mixed>       $node
     * @param array<string, mixed>       $spec
     * @param array<string, scalar|null> $params
     * @param array<string, mixed>       $payload
     * @return array{0: string, 1: ?string, 2: string} url, body, method
     */
    private function prepare(array $node, array $spec, array $params, array $payload): array
    {
        $method = strtoupper((string) ($spec['method'] ?? 'GET'));
        $remaining = $params;

        // Substitute {name} path segments from params.
        $path = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $m) use (&$remaining): string {
                $key = $m[1];
                $value = (string) ($remaining[$key] ?? '');
                unset($remaining[$key]);
                return rawurlencode($value);
            },
            (string) ($spec['path'] ?? '/'),
        );

        $version = (int) ($spec['version'] ?? 2);
        $scheme = ($node['ssl'] ?? true) ? 'https' : 'http';
        $url = $scheme . '://' . $node['ip'] . ':' . $node['port'] . '/v' . $version . '/' . ltrim($path, '/');

        $body = null;
        if ($method === 'GET') {
            $query = http_build_query($remaining);
            if ($query !== '') {
                $url .= '?' . $query;
            }
        } else {
            $body = (string) json_encode($payload !== [] ? $payload : $remaining);
        }

        return [$url, $body, $method];
    }

    /**
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private function headerLines(array $node): array
    {
        return [
            'Authorization: Bearer ' . (string) ($node['token'] ?? ''),
            'Content-Type: application/json',
            'Accept: application/x-ndjson, application/json',
            'X-MicroService: ' . $this->config->name(),
        ];
    }
}
