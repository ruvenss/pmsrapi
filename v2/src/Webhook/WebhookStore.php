<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Webhook;

use Pmsrapi\V2\Exception\WebhookStoreException;
use Pmsrapi\V2\Support\Logger;

/**
 * File-backed webhook registry.
 *
 * The runtime-managed subscriptions live in a single JSON document at an
 * ABSOLUTE path OUTSIDE the code tree (configured via public config
 * `webhooks_path`) — never in the secret config, never inside the deployment
 * dir (see .claude/rules/sec-writable-state-outside-code). The REST management
 * endpoints build/rebuild this file; the WebhookDispatcher reads it to decide
 * who receives an event.
 *
 * On-disk shape:
 *   { "version": 1, "webhooks": [ { ...Webhook::toArray() }, ... ] }
 *
 * Writes are serialized with an flock'd sidecar lock and made atomic with a
 * temp-file + rename, so a concurrent read never sees a half-written file.
 */
final class WebhookStore
{
    private const VERSION = 1;

    public function __construct(
        private readonly string $path,
        private readonly string $codeRoot,
        private readonly Logger $logger,
    ) {}

    /** True once the registry file exists (i.e. the store owns delivery). */
    public function isInitialized(): bool
    {
        return is_file($this->path);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return list<Webhook>
     * @throws WebhookStoreException on corrupt/unreadable JSON
     */
    public function all(): array
    {
        return $this->read();
    }

    public function find(string $id): ?Webhook
    {
        foreach ($this->read() as $webhook) {
            if ($webhook->id === $id) {
                return $webhook;
            }
        }

        return null;
    }

    /**
     * Enabled subscriptions that fire for $event, in registry order.
     *
     * @return list<Webhook>
     * @throws WebhookStoreException
     */
    public function forEvent(string $event): array
    {
        return array_values(array_filter(
            $this->read(),
            static fn(Webhook $w): bool => $w->enabled && $w->subscribesTo($event),
        ));
    }

    /**
     * Insert a new subscription or replace the one sharing its id.
     *
     * @throws WebhookStoreException
     */
    public function save(Webhook $webhook): void
    {
        $this->mutate(static function (array $webhooks) use ($webhook): array {
            $replaced = false;
            foreach ($webhooks as $i => $existing) {
                if ($existing->id === $webhook->id) {
                    $webhooks[$i] = $webhook;
                    $replaced = true;
                    break;
                }
            }
            if (!$replaced) {
                $webhooks[] = $webhook;
            }

            return array_values($webhooks);
        });
    }

    /**
     * @return bool true if a subscription was removed
     * @throws WebhookStoreException
     */
    public function delete(string $id): bool
    {
        $removed = false;

        $this->mutate(static function (array $webhooks) use ($id, &$removed): array {
            $kept = array_values(array_filter(
                $webhooks,
                static fn(Webhook $w): bool => $w->id !== $id,
            ));
            $removed = count($kept) !== count($webhooks);

            return $kept;
        });

        return $removed;
    }

    /**
     * Build/rebuild the whole registry from scratch. Deliberately does NOT read
     * the existing file, so a corrupt registry can always be rebuilt via the API.
     *
     * @param list<Webhook> $webhooks
     * @throws WebhookStoreException
     */
    public function replaceAll(array $webhooks): void
    {
        $this->withLock(function () use ($webhooks): void {
            $this->write(array_values($webhooks));
        });
    }

    /**
     * Serialize a read-modify-write under an exclusive lock. The callback
     * receives the current list and returns the next list to persist.
     *
     * @param callable(list<Webhook>): list<Webhook> $mutator
     * @throws WebhookStoreException
     */
    private function mutate(callable $mutator): void
    {
        $this->withLock(function () use ($mutator): void {
            $this->write($mutator($this->read()));
        });
    }

    /**
     * Run $critical while holding the store's exclusive file lock.
     *
     * @throws WebhookStoreException
     */
    private function withLock(callable $critical): void
    {
        $this->ensureOutsideCode();

        $lock = fopen($this->lockPath(), 'c');
        if ($lock === false) {
            throw new WebhookStoreException("Cannot open webhook store lock: {$this->lockPath()}");
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new WebhookStoreException('Could not acquire webhook store lock');
            }

            $critical();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return list<Webhook>
     * @throws WebhookStoreException
     */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $raw = file_get_contents($this->path);
        if ($raw === false) {
            throw new WebhookStoreException("Cannot read webhook store: {$this->path}");
        }
        if (trim($raw) === '') {
            return [];
        }
        if (!json_validate($raw)) {
            throw new WebhookStoreException("Webhook store is not valid JSON: {$this->path}");
        }

        /** @var array<string, mixed> $doc */
        $doc = json_decode($raw, true);
        $entries = $doc['webhooks'] ?? [];
        if (!is_array($entries)) {
            throw new WebhookStoreException("Webhook store 'webhooks' must be an array: {$this->path}");
        }

        $webhooks = [];
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $webhooks[] = Webhook::fromArray($entry);
            }
        }

        return $webhooks;
    }

    /**
     * Atomically persist the list: temp file in the same dir, then rename.
     *
     * @param list<Webhook> $webhooks
     * @throws WebhookStoreException
     */
    private function write(array $webhooks): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0o770, true) && !is_dir($dir)) {
            throw new WebhookStoreException("Webhook store directory is not writable: {$dir}");
        }

        $doc = [
            'version' => self::VERSION,
            'webhooks' => array_map(static fn(Webhook $w): array => $w->toArray(), $webhooks),
        ];

        $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new WebhookStoreException('Failed to encode webhook store JSON');
        }

        $tmp = $this->path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            throw new WebhookStoreException("Failed to write webhook store temp file: {$tmp}");
        }

        if (!rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new WebhookStoreException("Failed to commit webhook store: {$this->path}");
        }
    }

    private function lockPath(): string
    {
        return $this->path . '.lock';
    }

    /**
     * Hard-refuse to write the registry inside the read-only code tree.
     *
     * @throws WebhookStoreException
     */
    private function ensureOutsideCode(): void
    {
        $dir = realpath(dirname($this->path)) ?: dirname($this->path);
        $code = realpath($this->codeRoot) ?: $this->codeRoot;

        if ($dir === $code || str_starts_with($dir . DIRECTORY_SEPARATOR, $code . DIRECTORY_SEPARATOR)) {
            $this->logger->error('Refused webhook store write inside code tree', ['path' => $this->path]);
            throw new WebhookStoreException("Refusing to write webhook store inside code tree: {$this->path}");
        }
    }
}
