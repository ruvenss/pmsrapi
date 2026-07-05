<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Debug;

use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use RedisException;
use Throwable;

/**
 * On-demand production request/response/query recorder for the live dashboard.
 *
 * Design (see v2/README.md → "Debug dashboard"):
 *  - Every request is buffered IN MEMORY as it runs (cheap: array appends). No
 *    I/O, no Redis writes on the hot path.
 *  - At the end of the request we decide whether to KEEP the buffer:
 *      * the request errored (5xx / uncaught throwable)  -> flush AND arm a
 *        capture window, so the triggering transaction is saved in full and the
 *        aftermath is captured too;
 *      * the window is already armed                     -> flush;
 *      * otherwise                                       -> discard.
 *  - Kept buffers are flushed to a CAPPED Redis Stream (MAXLEN) with a TTL, so
 *    captured data lives OUTSIDE the read-only code tree and self-expires
 *    (.claude/rules/sec-writable-state-outside-code).
 *
 * Everything is best-effort: Redis errors never break a request. When debug is
 * disabled or Redis is unavailable, every method is a cheap no-op.
 */
final class DebugRecorder
{
    private const STREAM_KEY = 'debug:stream';
    private const ARMED_KEY = 'debug:armed';

    private readonly bool $enabled;
    private readonly bool $autoArm;
    private readonly int $window;
    private readonly int $maxEvents;
    private readonly int $ttl;
    private readonly bool $captureBodies;
    private readonly int $maxBuffer;

    /** Paths that are never recorded (the dashboard itself + noisy health pings). */
    private const SKIP_PREFIXES = ['/_debug', '/health'];

    private bool $active = false;
    private bool $errored = false;
    private string $requestId = '';
    private float $startedAt = 0.0;

    /** @var list<array<string, mixed>> in-memory buffer for the current request */
    private array $buffer = [];
    private bool $truncated = false;

    public function __construct(
        private readonly RedisClient $redis,
        private readonly Config $config,
        private readonly Redactor $redactor,
    ) {
        $this->enabled = (bool) $config->secret('debug.enabled', false) && $redis->isEnabled();
        $this->autoArm = (bool) $config->secret('debug.auto_arm_on_error', true);
        $this->window = (int) $config->secret('debug.window', 300);
        $this->maxEvents = (int) $config->secret('debug.max_events', 5000);
        $this->ttl = (int) $config->secret('debug.ttl', 3600);
        $this->captureBodies = (bool) $config->secret('debug.capture_bodies', true);
        $this->maxBuffer = (int) $config->secret('debug.max_buffer', 500);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    // --- Instrumentation hooks (called from index.php / Connection) ---------

    public function beginRequest(Request $request): void
    {
        if (!$this->enabled || $this->isSkipped($request->path)) {
            return;
        }

        $this->active = true;
        $this->errored = false;
        $this->requestId = bin2hex(random_bytes(6));
        $this->startedAt = microtime(true);
        $this->buffer = [];
        $this->truncated = false;

        $this->push(DebugEventType::Request, [
            'method' => $request->method->value,
            'path' => $request->path,
            'ip' => $request->ip,
            'query' => $this->redactor->data($request->query),
            'headers' => $this->redactor->headers($request->headers),
            'body' => $this->captureBodies ? $this->redactor->data($request->body) : null,
        ]);
    }

    /**
     * @param list<scalar|null> $params
     */
    public function recordQuery(string $sql, array $params, float $durationMs, int $rows): void
    {
        if (!$this->active) {
            return;
        }

        $this->push(DebugEventType::Query, [
            'sql' => $sql,
            'params' => $this->redactor->params($params),
            'duration_ms' => round($durationMs, 2),
            'rows' => $rows,
        ]);
    }

    public function recordError(Throwable $e): void
    {
        $this->errored = true;

        if (!$this->active) {
            return;
        }

        $this->push(DebugEventType::Error, [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'location' => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
        ]);
    }

    public function endRequest(?Response $response): void
    {
        if (!$this->active) {
            return;
        }

        $status = $response->status ?? 0;
        $errored = $this->errored || $status >= 500;

        $this->push(DebugEventType::Response, [
            'status' => $status,
            'success' => $response->success ?? false,
            'duration_ms' => round((microtime(true) - $this->startedAt) * 1000, 2),
            'body' => ($this->captureBodies && $response !== null)
                ? $this->redactor->data($response->success ? $response->data : $response->error)
                : null,
        ]);

        try {
            if ($errored) {
                if ($this->autoArm) {
                    $this->arm($this->window, 'auto: HTTP ' . $status);
                }
                $this->flush();
            } elseif ($this->isArmed()) {
                $this->flush();
            }
        } catch (RedisException) {
            // Debug capture must never break a request — drop the buffer silently.
        } finally {
            $this->active = false;
            $this->buffer = [];
        }
    }

    // --- Control surface (used by DebugController) --------------------------

    public function arm(int $ttl, string $reason): void
    {
        if (!$this->enabled) {
            return;
        }
        try {
            $this->redis->connection()->setex(self::ARMED_KEY, max(1, $ttl), json_encode([
                'reason' => $reason,
                'armed_at' => date('c'),
            ]) ?: '{}');
        } catch (RedisException) {
            // ignore
        }
    }

    public function disarm(): void
    {
        if (!$this->enabled) {
            return;
        }
        try {
            $this->redis->connection()->del(self::ARMED_KEY);
        } catch (RedisException) {
            // ignore
        }
    }

    public function isArmed(): bool
    {
        if (!$this->enabled) {
            return false;
        }
        try {
            return (bool) $this->redis->connection()->exists(self::ARMED_KEY);
        } catch (RedisException) {
            return false;
        }
    }

    /**
     * @return array{armed: bool, reason: ?string, ttl: int, events: int}
     */
    public function status(): array
    {
        $armed = false;
        $reason = null;
        $ttl = 0;
        $events = 0;

        if ($this->enabled) {
            try {
                $conn = $this->redis->connection();
                $raw = $conn->get(self::ARMED_KEY);
                if (is_string($raw)) {
                    $armed = true;
                    $ttl = max(0, (int) $conn->ttl(self::ARMED_KEY));
                    $decoded = json_validate($raw) ? json_decode($raw, true) : [];
                    $reason = is_array($decoded) ? ($decoded['reason'] ?? null) : null;
                }
                $events = (int) $conn->xLen(self::STREAM_KEY);
            } catch (RedisException) {
                // report defaults
            }
        }

        return ['armed' => $armed, 'reason' => $reason, 'ttl' => $ttl, 'events' => $events];
    }

    /**
     * Fetch events newer than $since (a stream id). When $since is null/empty,
     * return the most recent $limit events (initial dashboard load).
     *
     * @return array{events: list<array<string, mixed>>, cursor: string}
     */
    public function events(?string $since, int $limit): array
    {
        if (!$this->enabled) {
            return ['events' => [], 'cursor' => '0-0'];
        }

        $limit = max(1, min(1000, $limit));

        try {
            $conn = $this->redis->connection();

            if ($since === null || $since === '' || $since === '0' || $since === '0-0') {
                /** @var array<string, array<string, string>> $raw */
                $raw = $conn->xRevRange(self::STREAM_KEY, '+', '-', $limit);
                $entries = array_reverse($raw, true);
            } else {
                /** @var array<string, array<string, string>> $entries */
                $entries = $conn->xRange(self::STREAM_KEY, '(' . $since, '+', $limit);
            }

            $events = [];
            $cursor = $since ?? '0-0';
            foreach ($entries as $id => $fields) {
                $cursor = (string) $id;
                $json = $fields['json'] ?? '';
                if (is_string($json) && json_validate($json)) {
                    $event = json_decode($json, true);
                    if (is_array($event)) {
                        $event['id'] = (string) $id;
                        $events[] = $event;
                    }
                }
            }

            return ['events' => $events, 'cursor' => $cursor];
        } catch (RedisException) {
            return ['events' => [], 'cursor' => $since ?? '0-0'];
        }
    }

    public function clear(): void
    {
        if (!$this->enabled) {
            return;
        }
        try {
            $this->redis->connection()->del(self::STREAM_KEY);
        } catch (RedisException) {
            // ignore
        }
    }

    // --- internals ----------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    private function push(DebugEventType $type, array $data): void
    {
        if (count($this->buffer) >= $this->maxBuffer) {
            $this->truncated = true;
            return;
        }

        $this->buffer[] = [
            'type' => $type->value,
            'rid' => $this->requestId,
            'ts' => round(microtime(true) * 1000),
            'data' => $data,
        ];
    }

    /**
     * @throws RedisException
     */
    private function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $conn = $this->redis->connection();

        if ($this->truncated) {
            $this->buffer[] = [
                'type' => DebugEventType::Log->value,
                'rid' => $this->requestId,
                'ts' => round(microtime(true) * 1000),
                'data' => ['message' => "Buffer truncated at {$this->maxBuffer} events for this request"],
            ];
        }

        foreach ($this->buffer as $event) {
            // '*' = auto id; MAXLEN ~ N keeps the stream capped (approximate for speed).
            $conn->xAdd(self::STREAM_KEY, '*', ['json' => (string) json_encode($event)], $this->maxEvents, true);
        }

        $conn->expire(self::STREAM_KEY, $this->ttl);
    }

    private function isSkipped(string $path): bool
    {
        foreach (self::SKIP_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }
}
