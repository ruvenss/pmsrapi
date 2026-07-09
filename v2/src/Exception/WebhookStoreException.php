<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Exception;

use Throwable;

/**
 * The managed webhook registry could not be read or written (corrupt JSON,
 * unwritable path, or a refusal to write inside the code tree).
 *
 * During request handling it renders as a 500 envelope; the WebhookDispatcher
 * catches it specifically and degrades to "no subscribers" so a broken registry
 * never fails a write that already succeeded.
 */
final class WebhookStoreException extends ApiException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 500, 'webhook_store_error', [], $previous);
    }
}
