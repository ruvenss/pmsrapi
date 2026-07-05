<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Http\Controllers;

use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Database\Connection;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

/**
 * Service metadata and health. GET /info mirrors v1's info endpoint;
 * GET /health is public (no auth) and probes DB + Redis for orchestrators.
 */
final class SystemController
{
    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
        private readonly RedisClient $redis,
    ) {}

    public function info(Request $request): Response
    {
        return Response::ok([
            'program' => $this->config->name(),
            'version' => $this->config->version(),
            'description' => $this->config->public('ms_description', ''),
            'environment' => $this->config->environment(),
            'author' => $this->config->public('ms_author', ''),
            'license' => $this->config->public('ms_license', ''),
            'documentation' => $this->config->public('ms_documentation', ''),
            'github_repo' => $this->config->public('ms_github_repo', ''),
            'database' => $this->databaseStatus(),
            'redis' => $this->redis->isEnabled(),
            'local_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * MySQL connection state for /info: whether a connection is configured at
     * all (not every microservice needs one) and — if so — whether it works.
     *
     *   not_configured : no "db" block in config; the service runs without MySQL
     *   connected      : configured and a live query succeeded
     *   unavailable    : configured but the server can't be reached / queried
     *
     * @return array{configured: bool, connected: bool, status: string}
     */
    private function databaseStatus(): array
    {
        if (!$this->db->isConfigured()) {
            return ['configured' => false, 'connected' => false, 'status' => 'not_configured'];
        }

        $connected = $this->db->isAlive();

        return [
            'configured' => true,
            'connected' => $connected,
            'status' => $connected ? 'connected' : 'unavailable',
        ];
    }

    public function health(Request $request): Response
    {
        $db = $this->probeDatabase();
        $redis = $this->probeRedis();

        $healthy = $db !== 'down' && $redis !== 'down';

        return Response::ok([
            'status' => $healthy ? 'ok' : 'degraded',
            'database' => $db,
            'redis' => $redis,
        ]);
    }

    private function probeDatabase(): string
    {
        if (!$this->db->isConfigured()) {
            return 'disabled';
        }

        return $this->db->isAlive() ? 'up' : 'down';
    }

    private function probeRedis(): string
    {
        if (!$this->redis->isEnabled()) {
            return 'disabled';
        }

        try {
            // Best-effort probe: any failure (incl. RedisException) means down.
            return $this->redis->connection()->ping() ? 'up' : 'down';
        } catch (\Throwable) {
            return 'down';
        }
    }
}
