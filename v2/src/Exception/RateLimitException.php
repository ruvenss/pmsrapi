<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Exception;

final class RateLimitException extends ApiException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct(
            'Too Many Requests',
            429,
            'rate_limited',
            ['retry_after' => $retryAfter],
        );
    }
}
