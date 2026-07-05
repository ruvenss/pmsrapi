<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Security;

use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Support\Logger;
use RedisException;

/**
 * Redis-backed store for dynamically issued API tokens, layered on top of the
 * static master token (ms_server_token) that AuthMiddleware always accepts.
 *
 * Tokens are never stored in clear: only their SHA-256 hash is a Redis key, so
 * a Redis dump does not leak usable credentials. Supports TTL (short-lived
 * tokens) and instant revocation — neither of which the static token allows.
 */
final class TokenStore
{
    public function __construct(
        private readonly RedisClient $redis,
        private readonly Logger $logger,
    ) {}

    /**
     * @param array<string, mixed> $meta arbitrary claims (scopes, user id, ...)
     */
    public function issue(string $token, array $meta = [], ?int $ttl = null): void
    {
        if (!$this->redis->isEnabled()) {
            return;
        }

        try {
            $conn = $this->redis->connection();
            $key = $this->key($token);
            $conn->set($key, (string) json_encode($meta));
            if ($ttl !== null && $ttl > 0) {
                $conn->expire($key, $ttl);
            }
        } catch (RedisException $e) {
            $this->logger->error('TokenStore issue failed', ['error' => $e->getMessage()]);
        }
    }

    public function revoke(string $token): void
    {
        if (!$this->redis->isEnabled()) {
            return;
        }

        try {
            $this->redis->connection()->del($this->key($token));
        } catch (RedisException $e) {
            $this->logger->error('TokenStore revoke failed', ['error' => $e->getMessage()]);
        }
    }

    public function isValid(string $token): bool
    {
        if (!$this->redis->isEnabled() || $token === '') {
            return false;
        }

        try {
            return (bool) $this->redis->connection()->exists($this->key($token));
        } catch (RedisException $e) {
            $this->logger->warning('TokenStore lookup failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * @return array<string, mixed>|null claims for a valid token
     */
    public function claims(string $token): ?array
    {
        if (!$this->redis->isEnabled()) {
            return null;
        }

        try {
            $raw = $this->redis->connection()->get($this->key($token));
            if (is_string($raw) && json_validate($raw)) {
                return json_decode($raw, true);
            }
        } catch (RedisException $e) {
            $this->logger->warning('TokenStore claims failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function key(string $token): string
    {
        return 'token:' . hash('sha256', $token);
    }
}
