<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Cluster;

use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Support\Logger;
use RedisException;

/**
 * The Hive's registry of every worker's function manifest (DEVELOPMENT only).
 *
 * Manifests are collected two ways and stored in a Redis hash:
 *   - push: a worker POSTs its manifest to /v2/hive/register
 *   - pull: the Hive polls every universe node's /v2/capabilities (refresh)
 *
 * From the collected manifests it builds the global map and — crucially —
 * detects DUPLICATE ownership, e.g. two services both claiming `get_client`.
 * The whole map-building step is a pure function so it is trivially testable.
 */
final class HiveRegistry
{
    private const KEY = 'hive:services';

    public function __construct(
        private readonly RedisClient $redis,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    /**
     * @param array<string, mixed> $manifest
     */
    public function register(array $manifest): void
    {
        $service = (string) ($manifest['service'] ?? '');
        if ($service === '' || !$this->redis->isEnabled()) {
            return;
        }

        try {
            $conn = $this->redis->connection();
            $conn->hSet(self::KEY, $service, (string) json_encode($manifest));
            $conn->expire(self::KEY, 86400);
        } catch (RedisException $e) {
            $this->logger->error('Hive register failed', ['service' => $service, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Poll every universe node's /capabilities and store the manifests.
     *
     * @return int number of nodes successfully collected
     */
    public function collect(): int
    {
        $count = 0;
        foreach ((array) $this->config->secret('universe', []) as $node) {
            if (!is_array($node)) {
                continue;
            }
            $manifest = $this->fetchCapabilities($node);
            if ($manifest !== null) {
                $this->register($manifest);
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function services(): array
    {
        if (!$this->redis->isEnabled()) {
            return [];
        }
        try {
            $all = $this->redis->connection()->hGetAll(self::KEY);
        } catch (RedisException) {
            return [];
        }

        $manifests = [];
        foreach ((array) $all as $json) {
            if (is_string($json) && json_validate($json)) {
                $decoded = json_decode($json, true);
                if (is_array($decoded)) {
                    $manifests[] = $decoded;
                }
            }
        }
        return $manifests;
    }

    public function clear(): void
    {
        if (!$this->redis->isEnabled()) {
            return;
        }
        try {
            $this->redis->connection()->del(self::KEY);
        } catch (RedisException) {
            // ignore
        }
    }

    /**
     * The full cluster map: services, per-function owners, collisions and a
     * canonical function_map. Reads from the registry.
     *
     * @return array<string, mixed>
     */
    public function map(): array
    {
        return self::buildMap($this->services());
    }

    /**
     * @return list<array{function: string, owners: list<string>}>
     */
    public function collisions(): array
    {
        /** @var list<array{function: string, owners: list<string>}> $collisions */
        $collisions = $this->map()['collisions'];
        return $collisions;
    }

    /**
     * The config a given worker should carry in production: the function_map for
     * every function it does NOT own, plus the universe nodes it needs.
     *
     * @return array<string, mixed>
     */
    public function exportFor(string $service): array
    {
        $map = $this->map();

        $functionMap = [];
        $needed = [];
        foreach ($map['function_map'] as $name => $spec) {
            if (($spec['service'] ?? null) !== $service) {
                $functionMap[$name] = $spec;
                $needed[(string) $spec['service']] = true;
            }
        }

        $universe = [];
        foreach ((array) $this->config->secret('universe', []) as $node) {
            if (is_array($node) && isset($needed[(string) ($node['name'] ?? '')])) {
                $universe[] = $node;
            }
        }

        return [
            'service' => $service,
            'function_map' => $functionMap,
            'universe' => $universe,
            'collisions' => $map['collisions'],
        ];
    }

    /**
     * Pure map builder — no I/O, so it can be unit-tested directly.
     *
     * @param list<array<string, mixed>> $manifests
     * @return array{
     *   services: list<array<string, mixed>>,
     *   functions: array<string, list<string>>,
     *   collisions: list<array{function: string, owners: list<string>}>,
     *   function_map: array<string, array<string, mixed>>
     * }
     */
    public static function buildMap(array $manifests): array
    {
        $services = [];
        $functions = [];
        $functionMap = [];

        foreach ($manifests as $manifest) {
            if (!is_array($manifest)) {
                continue;
            }
            $service = (string) ($manifest['service'] ?? 'unknown');
            $fns = is_array($manifest['functions'] ?? null) ? $manifest['functions'] : [];

            $services[] = [
                'name' => $service,
                'role' => (string) ($manifest['role'] ?? 'worker'),
                'version' => (string) ($manifest['version'] ?? ''),
                'functions' => array_keys($fns),
            ];

            foreach ($fns as $name => $spec) {
                $functions[(string) $name][] = $service;
                if (!isset($functionMap[$name]) && is_array($spec)) {
                    $functionMap[$name] = [
                        'service' => $service,
                        'method' => strtoupper((string) ($spec['method'] ?? 'GET')),
                        'path' => (string) ($spec['path'] ?? '/'),
                        'stream' => (bool) ($spec['stream'] ?? false),
                    ];
                }
            }
        }

        $collisions = [];
        foreach ($functions as $name => $owners) {
            $unique = array_values(array_unique($owners));
            if (count($unique) > 1) {
                $collisions[] = ['function' => (string) $name, 'owners' => $unique];
            }
        }

        return [
            'services' => $services,
            'functions' => $functions,
            'collisions' => $collisions,
            'function_map' => $functionMap,
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>|null
     */
    private function fetchCapabilities(array $node): ?array
    {
        $scheme = ($node['ssl'] ?? true) ? 'https' : 'http';
        $url = $scheme . '://' . ($node['ip'] ?? '') . ':' . ($node['port'] ?? '') . '/v2/capabilities';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . (string) ($node['token'] ?? '')],
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '' || !is_string($response) || !json_validate($response)) {
            $this->logger->warning('Hive capabilities fetch failed', ['node' => $node['name'] ?? '?', 'error' => $error]);
            return null;
        }

        $decoded = json_decode($response, true);
        $manifest = is_array($decoded) ? ($decoded['data'] ?? $decoded) : null;

        return is_array($manifest) && isset($manifest['service']) ? $manifest : null;
    }
}
