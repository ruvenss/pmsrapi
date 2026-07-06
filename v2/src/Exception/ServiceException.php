<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Exception;

use Throwable;

/**
 * A cross-service call (universe / hive) failed or was misconfigured.
 * Defaults to 502 Bad Gateway — the fault is upstream, not the client's.
 */
final class ServiceException extends ApiException
{
    public function __construct(
        string $message,
        int $statusCode = 502,
        string $errorCode = 'service_error',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $errorCode, [], $previous);
    }
}
