<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Http\Controllers;

use Pmsrapi\V2\Cluster\Capabilities;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

/**
 * GET /v2/capabilities — a worker's function manifest (what the Hive polls).
 */
final class CapabilitiesController
{
    public function __construct(private readonly Capabilities $capabilities) {}

    public function show(Request $request): Response
    {
        return Response::ok($this->capabilities->manifest());
    }
}
