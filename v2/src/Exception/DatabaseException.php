<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Exception;

use Throwable;

final class DatabaseException extends ApiException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        // Detail stays server-side; clients only ever see a generic 500.
        parent::__construct($message, 500, 'database_error', [], $previous);
    }
}
