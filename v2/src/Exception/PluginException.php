<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Exception;

/**
 * Thrown when a plugin cannot be loaded, is malformed, or violates a plugin
 * rule (duplicate/reserved URL prefix, replacing a core service, missing
 * dependency). Always a 500 — a broken plugin is a deployment problem, not a
 * client error. By design it takes the whole service down loudly rather than
 * running with a half-loaded extension.
 */
final class PluginException extends ApiException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 500, 'plugin_error');
    }
}
