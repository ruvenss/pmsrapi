<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Debug;

/**
 * The kinds of events the debug recorder captures for the live dashboard.
 */
enum DebugEventType: string
{
    case Request = 'request';
    case Response = 'response';
    case Query = 'query';
    case Error = 'error';
    case Log = 'log';
}
