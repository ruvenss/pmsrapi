<?php

declare(strict_types=1);

/**
 * CLI worker — drains the Redis webhook queue and delivers events.
 *
 *   php v2/worker.php            # run forever (use systemd Restart=always)
 *   php v2/worker.php --once     # drain until empty, then exit (cron/testing)
 *
 * Replaces v1's file-based webhooks/queue polling. Reliable under load because
 * the queue is Redis, and request latency never waits on subscriber endpoints.
 *
 * DO NOT MODIFY (core).
 */

use Pmsrapi\V2\Cache\RedisClient;
use Pmsrapi\V2\Core\Container;
use Pmsrapi\V2\Queue\RedisQueue;
use Pmsrapi\V2\Queue\WebhookDispatcher;
use Pmsrapi\V2\Support\Logger;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Container $container */
$container = require __DIR__ . '/bootstrap.php';

$logger = $container->get(Logger::class);

if (!$container->get(RedisClient::class)->isEnabled()) {
    fwrite(STDERR, "Redis is not configured/available — worker cannot run.\n");
    exit(1);
}

$queue = $container->get(RedisQueue::class);
$dispatcher = $container->get(WebhookDispatcher::class);

$once = in_array('--once', $argv, true);
$logger->info('Webhook worker started', ['mode' => $once ? 'once' : 'daemon']);

do {
    try {
        $job = $queue->pop(WebhookDispatcher::QUEUE, $once ? 1 : 5);

        if ($job === null) {
            if ($once) {
                break;
            }
            continue;
        }

        if (isset($job['event'], $job['payload']) && is_array($job['payload'])) {
            /** @var array{event: string, payload: array<string, mixed>} $job */
            $dispatcher->deliver($job);
        } else {
            $logger->warning('Discarded malformed webhook job', ['job' => $job]);
        }
    } catch (\Throwable $e) {
        $logger->error('Worker loop error', ['error' => $e->getMessage()]);
        usleep(500_000); // brief backoff so a persistent failure doesn't hot-loop
    }
} while (true);

$logger->info('Webhook worker stopped');
