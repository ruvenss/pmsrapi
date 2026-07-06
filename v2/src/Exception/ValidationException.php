<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Exception;

final class ValidationException extends ApiException
{
    /**
     * @param array<string, string> $errors field => message
     */
    public function __construct(array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message, 422, 'validation_failed', ['errors' => $errors]);
    }
}
