<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Cache;

use Closure;
use Pmsrapi\V2\Support\Logger;
use RedisException;

/**
 * Read-through cache for query results, with O(1) invalidation-by-table.
 *
 * Instead of tracking every cache key per table, each table has a monotonically
 * increasing "version" counter embedded in its cache keys. A write bumps the
 * version, orphaning all previous keys (which then expire by TTL). This is fast,
 * race-free, and needs no key bookkeeping.
 *
 * Every Redis error degrades to a cache miss — the producer still runs, so the
 * API keeps serving correct (just uncached) data when Redis is unavailable.
 */
final class QueryCache
{
    public function __construct(
        private readonly RedisClient $redis,
        private readonly Logger $logger,
        private readonly int $defaultTtl = 30,
    ) {}

    /**
     * Return the cached value for these key-parts, or run $producer, cache it,
     * and return it.
     *
     * @param string               $table    logical table/namespace for invalidation
     * @param array<string, mixed> $keyParts everything that makes the query unique
     * @param Closure(): mixed     $producer computes the value on a miss
     */
    public function remember(string $table, array $keyParts, Closure $producer, ?int $ttl = null): mixed
    {
        if (!$this->redis->isEnabled()) {
            return $producer();
        }

        try {
            $redis = $this->redis->connection();
            $version = (int) ($redis->get($this->versionKey($table)) ?: '1');
            $key = $this->dataKey($table, $version, $keyParts);

            $cached = $redis->get($key);
            if (is_string($cached) && json_validate($cached)) {
                return json_decode($cached, true);
            }

            $value = $producer();
            $redis->setex($key, $ttl ?? $this->defaultTtl, (string) json_encode($value));

            return $value;
        } catch (RedisException $e) {
            $this->logger->warning('QueryCache degraded to miss', ['error' => $e->getMessage(), 'table' => $table]);
            return $producer();
        }
    }

    /**
     * Invalidate every cached read for a table after a write.
     */
    public function invalidate(string $table): void
    {
        if (!$this->redis->isEnabled()) {
            return;
        }

        try {
            $this->redis->connection()->incr($this->versionKey($table));
        } catch (RedisException $e) {
            $this->logger->warning('QueryCache invalidation failed', ['error' => $e->getMessage(), 'table' => $table]);
        }
    }

    private function versionKey(string $table): string
    {
        return 'cachever:' . $table;
    }

    /**
     * @param array<string, mixed> $keyParts
     */
    private function dataKey(string $table, int $version, array $keyParts): string
    {
        return 'cache:' . $table . ':v' . $version . ':' . md5((string) json_encode($keyParts));
    }
}
