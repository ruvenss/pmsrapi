<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Http\Controllers;

use Pmsrapi\V2\Cluster\HiveRegistry;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

/**
 * The Hive coordinator's HTTP surface (DEVELOPMENT only — routes are registered
 * solely when role = hive_mind).
 *
 * GET  /v2/hive               -> the VueFlow map UI (public shell)
 * GET  /v2/hive/map           -> services, per-function owners, collisions
 * GET  /v2/hive/collisions    -> functions owned by more than one service
 * POST /v2/hive/refresh       -> poll every universe node's /capabilities
 * POST /v2/hive/register      -> a worker pushes its own manifest
 * GET  /v2/hive/export/{svc}  -> the function_map+universe to bake into a worker
 */
final class HiveController
{
    public function __construct(private readonly HiveRegistry $registry) {}

    public function graph(Request $request): Response
    {
        $html = file_get_contents(dirname(__DIR__, 2) . '/Cluster/hive-map.html');
        if ($html === false) {
            throw new ApiException('Hive map template missing', 500, 'hive_template_missing');
        }
        return Response::raw(200, $html);
    }

    public function map(Request $request): Response
    {
        return Response::ok($this->registry->map());
    }

    public function collisions(Request $request): Response
    {
        return Response::ok(['collisions' => $this->registry->collisions()]);
    }

    public function refresh(Request $request): Response
    {
        $collected = $this->registry->collect();

        return Response::ok([
            'collected' => $collected,
            ...$this->registry->map(),
        ]);
    }

    public function register(Request $request): Response
    {
        $manifest = $request->body;
        if (empty($manifest['service']) || !isset($manifest['functions'])) {
            throw new ValidationException(['manifest' => 'A manifest with "service" and "functions" is required']);
        }

        $this->registry->register($manifest);

        return Response::ok(['registered' => (string) $manifest['service']]);
    }

    public function export(Request $request, string $service): Response
    {
        return Response::ok($this->registry->exportFor($service));
    }
}
