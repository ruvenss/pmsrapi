<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Http\Controllers;

use mysqli_sql_exception;
use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Database\Connection;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use RedisException;

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
            'database' => $this->db->isConfigured(),
            'redis' => $this->redis->isEnabled(),
            'local_time' => date('Y-m-d H:i:s'),
        ]);
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

        try {
            $this->db->scalar('SELECT 1');
            return 'up';
        } catch (mysqli_sql_exception | \Throwable) {
            return 'down';
        }
    }

    private function probeRedis(): string
    {
        if (!$this->redis->isEnabled()) {
            return 'disabled';
        }

        try {
            return $this->redis->connection()->ping() ? 'up' : 'down';
        } catch (RedisException | \Throwable) {
            return 'down';
        }
    }
}
