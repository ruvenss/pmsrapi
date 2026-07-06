<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Http\Controllers;

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Debug\DebugRecorder;
use Pmsrapi\V2\Exception\ApiException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;

/**
 * Backend for the live production debug dashboard.
 *
 * GET  /_debug          -> the dashboard HTML shell (public; contains no data)
 * GET  /_debug/status   -> armed?, ttl, event count, config             (auth)
 * GET  /_debug/events   -> events newer than ?since= (cursor-based poll) (auth)
 * POST /_debug/arm      -> manually open a capture window {ttl,reason}   (auth)
 * POST /_debug/disarm   -> close the capture window                      (auth)
 * DELETE /_debug/events -> clear the captured stream                     (auth)
 *
 * Routes are only registered when debug.enabled is true (see routes.php). The
 * data endpoints sit behind the normal Bearer auth; only the HTML shell is
 * public (it holds no secrets and asks for the token client-side).
 */
final class DebugController
{
    public function __construct(
        private readonly DebugRecorder $recorder,
        private readonly Config $config,
    ) {}

    public function dashboard(Request $request): Response
    {
        $html = file_get_contents(dirname(__DIR__, 2) . '/Debug/dashboard.html');
        if ($html === false) {
            throw new ApiException('Dashboard template missing', 500, 'debug_template_missing');
        }

        return Response::raw(200, $html);
    }

    public function status(Request $request): Response
    {
        return Response::ok([
            ...$this->recorder->status(),
            'enabled' => $this->recorder->isEnabled(),
            'service' => $this->config->name(),
            'environment' => $this->config->environment(),
            'window' => (int) $this->config->secret('debug.window', 300),
            'auto_arm_on_error' => (bool) $this->config->secret('debug.auto_arm_on_error', true),
            'capture_bodies' => (bool) $this->config->secret('debug.capture_bodies', true),
        ]);
    }

    public function events(Request $request): Response
    {
        return Response::ok($this->recorder->events(
            $request->query('since'),
            $request->queryInt('limit', 200),
        ));
    }

    public function arm(Request $request): Response
    {
        $ttl = (int) ($request->body['ttl'] ?? $this->config->secret('debug.window', 300));
        $reason = trim((string) ($request->body['reason'] ?? 'manual'));

        $this->recorder->arm($ttl, $reason === '' ? 'manual' : 'manual: ' . $reason);

        return $this->status($request);
    }

    public function disarm(Request $request): Response
    {
        $this->recorder->disarm();

        return $this->status($request);
    }

    public function clear(Request $request): Response
    {
        $this->recorder->clear();

        return Response::ok(['cleared' => true]);
    }
}
