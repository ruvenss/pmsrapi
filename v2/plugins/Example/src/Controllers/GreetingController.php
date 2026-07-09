<?php

declare(strict_types=1);

namespace Plugins\Example\Controllers;

use Pmsrapi\V2\Http\Response;

/**
 * A plain controller. It returns a {@see Response} exactly like a core
 * controller does — the envelope, status codes, and streaming helpers are all
 * available to plugins.
 */
final class GreetingController
{
    public function hello(string $name): Response
    {
        return Response::ok([
            'greeting' => "Hello, {$name}!",
            'from' => 'example plugin',
        ]);
    }
}
