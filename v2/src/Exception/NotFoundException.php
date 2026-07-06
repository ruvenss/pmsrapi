<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Exception;

final class NotFoundException extends ApiException
{
    public function __construct(string $message = 'Resource not found')
    {
        parent::__construct($message, 404, 'not_found');
    }
}
