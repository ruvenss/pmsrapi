<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Queue;

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Support\Logger;

/**
 * Fire-and-forget webhook emitter.
 *
 * emit() enqueues an event onto the Redis "webhooks" queue and returns
 * immediately, so request latency never depends on subscriber endpoints. The
 * CLI worker (worker.php) drains the queue and deliver()s each matching
 * subscriber with an HMAC signature header.
 *
 * Subscribers are declared in the secret config under "webhooks":
 *   "webhooks": [ { "event": "users.created", "url": "https://...", "secret": "..." } ]
 */
final class WebhookDispatcher
{
    public const QUEUE = 'webhooks';

    public function __construct(
        private readonly RedisQueue $queue,
        private readonly Config $config,
        private readonly Logger $logger,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function emit(string $event, array $payload): void
    {
        if (!$this->hasSubscribers($event)) {
            return;
        }

        try {
            $this->queue->push(self::QUEUE, [
                'event' => $event,
                'payload' => $payload,
                'emitted_at' => date('c'),
            ]);
        } catch (\RedisException $e) {
            // Non-fatal: the write already succeeded; only the notification is lost.
            $this->logger->error('Failed to enqueue webhook', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Deliver one dequeued job to every subscriber of its event.
     * Called by the worker, not during the request.
     *
     * @param array{event: string, payload: array<string, mixed>} $job
     * @return list<array{url: string, status: int, ok: bool}>
     */
    public function deliver(array $job): array
    {
        $results = [];

        foreach ($this->subscribers($job['event']) as $sub) {
            $body = (string) json_encode([
                'event' => $job['event'],
                'payload' => $job['payload'],
            ]);

            $headers = ['Content-Type: application/json'];
            if (isset($sub['secret']) && $sub['secret'] !== '') {
                $signature = hash_hmac('sha256', $body, (string) $sub['secret']);
                $headers[] = 'X-Signature: sha256=' . $signature;
            }

            $ch = curl_init($sub['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 15,
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $ok = $error === '' && $status >= 200 && $status < 300;
            if (!$ok) {
                $this->logger->warning('Webhook delivery failed', [
                    'event' => $job['event'],
                    'url' => $sub['url'],
                    'status' => $status,
                    'error' => $error,
                ]);
            }

            $results[] = ['url' => (string) $sub['url'], 'status' => $status, 'ok' => $ok];
        }

        return $results;
    }

    private function hasSubscribers(string $event): bool
    {
        return $this->subscribers($event) !== [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function subscribers(string $event): array
    {
        $all = $this->config->secret('webhooks', []);
        if (!is_array($all)) {
            return [];
        }

        return array_values(array_filter(
            $all,
            static fn(mixed $sub): bool => is_array($sub)
                && ($sub['event'] ?? null) === $event
                && !empty($sub['url']),
        ));
    }
}
