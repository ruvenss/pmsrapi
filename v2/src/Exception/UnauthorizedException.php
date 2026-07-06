<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Exception;

final class UnauthorizedException extends ApiException
{
    public function __construct(string $message = 'Unauthorized')
    {
        parent::__construct($message, 401, 'unauthorized');
    }
}
