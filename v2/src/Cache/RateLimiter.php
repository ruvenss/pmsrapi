<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Cache;

use Pmsrapi\V2\Support\Logger;
use RedisException;

/**
 * Fixed-window rate limiter backed by Redis INCR/EXPIRE.
 *
 * Fails OPEN: if Redis is unreachable the request is allowed (and logged),
 * because a cache outage should not become a full outage. Flip $failOpen to
 * false for stricter deployments.
 */
final class RateLimiter
{
    public function __construct(
        private readonly RedisClient $redis,
        private readonly Logger $logger,
        private readonly bool $failOpen = true,
    ) {}

    public function hit(string $identifier, int $max, int $window): RateLimitResult
    {
        if (!$this->redis->isEnabled()) {
            return RateLimitResult::allowed($max, $max);
        }

        $key = 'rl:' . $identifier;

        try {
            $redis = $this->redis->connection();
            $count = $redis->incr($key);

            if ($count === 1) {
                $redis->expire($key, $window);
                $ttl = $window;
            } else {
                $ttl = $redis->ttl($key);
                if ($ttl < 0) {
                    $redis->expire($key, $window);
                    $ttl = $window;
                }
            }

            $remaining = max(0, $max - $count);

            return $count > $max
                ? RateLimitResult::denied($max, $ttl)
                : RateLimitResult::allowed($max, $remaining);
        } catch (RedisException $e) {
            $this->logger->warning('RateLimiter degraded', ['error' => $e->getMessage()]);
            return $this->failOpen
                ? RateLimitResult::allowed($max, $max)
                : RateLimitResult::denied($max, $window);
        }
    }
}
