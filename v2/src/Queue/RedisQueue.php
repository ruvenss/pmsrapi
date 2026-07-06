<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Queue;

use Pmsrapi\V2\Cache\RedisClient;
use RedisException;
use RuntimeException;

/**
 * Reliable-ish FIFO job queue on a Redis list. Replaces v1's file-based
 * webhooks/queue directory.
 *
 * push() LPUSHes JSON jobs; a worker BRPOPs them. This is at-most-once by
 * default; for at-least-once processing a worker can use reserve() (RPOPLPUSH
 * into a per-worker processing list) and ack() on success.
 */
final class RedisQueue
{
    public function __construct(private readonly RedisClient $redis) {}

    /**
     * @param array<string, mixed> $job
     */
    public function push(string $queue, array $job): void
    {
        $this->redis->connection()->lPush($this->key($queue), (string) json_encode($job));
    }

    public function size(string $queue): int
    {
        return (int) $this->redis->connection()->lLen($this->key($queue));
    }

    /**
     * Block until a job is available or $timeout seconds elapse.
     *
     * @return array<string, mixed>|null decoded job, or null on timeout
     * @throws RedisException
     */
    public function pop(string $queue, int $timeout = 5): ?array
    {
        /** @var array{0: string, 1: string}|array{}|false $result */
        $result = $this->redis->connection()->brPop([$this->key($queue)], $timeout);

        if (!is_array($result) || count($result) < 2) {
            return null;
        }

        $payload = $result[1];
        if (!is_string($payload) || !json_validate($payload)) {
            throw new RuntimeException('Corrupt job payload dequeued from ' . $queue);
        }

        return json_decode($payload, true);
    }

    private function key(string $queue): string
    {
        return 'queue:' . $queue;
    }
}
