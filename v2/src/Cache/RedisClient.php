<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Cache;

use Pmsrapi\V2\Core\Config;
use Redis;
use RedisException;
use RuntimeException;

/**
 * Thin lazy wrapper over the phpredis extension (\Redis) — no Composer/predis.
 *
 * Connects on first use and applies a per-service key prefix. Higher-level
 * helpers (QueryCache, RateLimiter, TokenStore, RedisQueue) are expected to
 * catch RedisException and degrade gracefully, so a Redis outage slows the
 * service down but does not take it offline.
 */
final class RedisClient
{
    private ?Redis $redis = null;
    private readonly bool $enabled;
    private readonly string $host;
    private readonly int $port;
    private readonly ?string $password;
    private readonly int $database;
    private readonly string $prefix;
    private readonly float $timeout;

    public function __construct(Config $config)
    {
        // Redis is enabled whenever a "redis" block exists in the secret config.
        $this->enabled = $config->hasSecret('redis') && extension_loaded('redis');
        $this->host = (string) $config->secret('redis.host', '127.0.0.1');
        $this->port = (int) $config->secret('redis.port', 6379);
        $password = $config->secret('redis.password');
        $this->password = is_string($password) && $password !== '' ? $password : null;
        $this->database = (int) $config->secret('redis.db', 0);
        $this->timeout = (float) $config->secret('redis.timeout', 1.5);
        $this->prefix = (string) $config->secret('redis.prefix', 'ms:' . $config->name() . ':');
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @throws RedisException on connection/auth failure
     * @throws RuntimeException when Redis is not configured/available
     */
    public function connection(): Redis
    {
        if (!$this->enabled) {
            throw new RuntimeException('Redis is not configured or the phpredis extension is missing.');
        }

        if ($this->redis instanceof Redis) {
            return $this->redis;
        }

        $redis = new Redis();
        $redis->connect($this->host, $this->port, $this->timeout);

        if ($this->password !== null) {
            $redis->auth($this->password);
        }
        if ($this->database !== 0) {
            $redis->select($this->database);
        }

        $redis->setOption(Redis::OPT_PREFIX, $this->prefix);
        $redis->setOption(Redis::OPT_SERIALIZER, (string) Redis::SERIALIZER_NONE);

        return $this->redis = $redis;
    }
}
