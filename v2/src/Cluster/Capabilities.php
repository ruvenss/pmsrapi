<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Cluster;

use Pmsrapi\V2\Core\Config;

/**
 * A worker's self-description: the functions it OWNS and how to invoke each.
 *
 * Functions are declared explicitly in the secret config so there is a single
 * source of truth the Hive can diff for collisions:
 *
 *   "functions": {
 *     "get_client":     { "method": "GET",  "path": "/clients/{id}" },
 *     "export_clients": { "method": "POST", "path": "/stream/clients", "stream": true }
 *   }
 *
 * This manifest is what `GET /v2/capabilities` returns and what the Hive polls.
 */
final class Capabilities
{
    public function __construct(private readonly Config $config) {}

    /**
     * @return array{service: string, role: string, version: string, functions: array<string, array<string, mixed>>}
     */
    public function manifest(): array
    {
        return [
            'service' => $this->config->name(),
            'role' => $this->config->role(),
            'version' => $this->config->version(),
            'functions' => $this->functions(),
        ];
    }

    /**
     * @return array<string, array{method: string, path: string, stream: bool, desc: string}>
     */
    public function functions(): array
    {
        $declared = $this->config->secret('functions', []);
        if (!is_array($declared)) {
            return [];
        }

        $functions = [];
        foreach ($declared as $name => $spec) {
            if (!is_string($name) || !is_array($spec) || empty($spec['path'])) {
                continue;
            }
            $functions[$name] = [
                'method' => strtoupper((string) ($spec['method'] ?? 'GET')),
                'path' => (string) $spec['path'],
                'stream' => (bool) ($spec['stream'] ?? false),
                'desc' => (string) ($spec['desc'] ?? ''),
            ];
        }

        return $functions;
    }
}
