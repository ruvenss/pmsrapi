<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Exception;

use RuntimeException;
use Throwable;

/**
 * Base for every exception the API can turn into an HTTP response.
 *
 * Carries an HTTP status code and an optional machine-readable error code plus
 * structured details, so the top-level boundary can render a consistent
 * envelope without string-matching messages (see .claude/rules/error-*).
 */
class ApiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        private readonly int $statusCode = 500,
        private readonly string $errorCode = 'internal_error',
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
