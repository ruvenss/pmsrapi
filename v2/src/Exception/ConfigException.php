<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Exception;

/**
 * Thrown when configuration is missing or malformed. Always a 500 — the
 * service is misconfigured, not the client's fault.
 */
final class ConfigException extends ApiException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 500, 'config_error');
    }
}
