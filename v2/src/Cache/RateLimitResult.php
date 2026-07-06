<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Cache;

/**
 * Outcome of a rate-limit check. Immutable value object.
 */
readonly class RateLimitResult
{
    private function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $retryAfter,
    ) {}

    public static function allowed(int $limit, int $remaining): self
    {
        return new self(true, $limit, $remaining, 0);
    }

    public static function denied(int $limit, int $retryAfter): self
    {
        return new self(false, $limit, 0, max(1, $retryAfter));
    }
}
