<?php

declare(strict_types=1);

/**
 * v2 front controller — the single HTTP entry point.
 * @author Ruvenss G. Wilches <ruvenss@gmail.com>
 * Unlike v1 (function-name-in-body dispatch), v2 uses real REST routing:
 *   GET    /v2/{resource}          list
 *   GET    /v2/{resource}/{id}     read one
 *   POST   /v2/{resource}          create
 *   PUT    /v2/{resource}/{id}     replace/update
 *   DELETE /v2/{resource}/{id}     delete
 *
 * Every request flows through the Kernel pipeline: Auth -> RateLimit -> route.
 *
 * DO NOT MODIFY (core). Define routes/resources in routes.php and config.
 */

use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Core\Kernel;
use Pmsrapi\V2\Debug\DebugRecorder;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

/** @var Container $container */
$container = require __DIR__ . '/bootstrap.php';

$router = require __DIR__ . '/routes.php';

$kernel = new Kernel($container, $router);
$recorder = $container->get(DebugRecorder::class);

$response = null;
try {
    $request = Request::fromGlobals('/v2');
    $recorder->beginRequest($request);
    $response = $kernel->handle($request);
} catch (\Throwable $e) {
    // Top-level boundary — the only place a broad catch is allowed.
    $recorder->recordError($e);
    $response = Response::fromThrowable($e, $container->get(\Pmsrapi\V2\Core\Config::class)->isProduction());
    $container->get(\Pmsrapi\V2\Support\Logger::class)->critical('Unhandled exception', [
        'exception' => $e::class,
        'message' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
    ]);
} finally {
    // Flush the captured request buffer (kept only if armed or errored).
    $recorder->endRequest($response);
}

$response->send();
