<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Exception;

final class MethodNotAllowedException extends ApiException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(array $allowed = [])
    {
        parent::__construct(
            'Method Not Allowed',
            405,
            'method_not_allowed',
            $allowed === [] ? [] : ['allowed' => $allowed],
        );
    }
}
