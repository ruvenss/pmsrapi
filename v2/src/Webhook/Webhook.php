<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Webhook;

use Pmsrapi\V2\Exception\ValidationException;

/**
 * A single webhook subscription — immutable value object.
 *
 * Persisted in the managed store (see WebhookStore) as one entry of the
 * external JSON registry, NOT in the secret config. A subscription is:
 *
 *   - target      the host we POST the event to
 *   - events      which events fire it (CRUD like "users.created", wildcards
 *                 like "users.*" / "*", or custom-function hooks "before:fn"
 *                 / "after:fn")
 *   - enabled     disabled subscriptions are kept but never delivered to
 *   - secret      optional; when set, deliveries carry an HMAC X-Signature
 *   - allowedIps  optional; captured for a future egress allowlist. NOT
 *                 enforced yet — stored so the config is forward-compatible.
 */
final readonly class Webhook
{
    /**
     * @param list<string> $events     non-empty list of event selectors
     * @param list<string> $allowedIps reserved for future IP enforcement (stored, not enforced)
     */
    public function __construct(
        public string $id,
        public string $target,
        public array $events,
        public bool $enabled = true,
        public ?string $secret = null,
        public array $allowedIps = [],
        public ?string $name = null,
        public string $createdAt = '',
        public string $updatedAt = '',
    ) {}

    /**
     * Build a brand-new subscription from validated client input, minting an id
     * and timestamps. $input has already passed through validate().
     *
     * @param array{target: string, events: list<string>, enabled?: bool, secret?: ?string, allowed_ips?: list<string>, name?: ?string} $input
     */
    public static function create(array $input): self
    {
        $now = date('c');

        return new self(
            id: self::newId(),
            target: $input['target'],
            events: $input['events'],
            enabled: $input['enabled'] ?? true,
            secret: ($input['secret'] ?? '') !== '' ? $input['secret'] : null,
            allowedIps: $input['allowed_ips'] ?? [],
            name: ($input['name'] ?? '') !== '' ? $input['name'] : null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * Hydrate from a trusted store record. Missing keys fall back to defaults so
     * a hand-edited registry with partial entries still loads.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $events = $data['events'] ?? [];

        return new self(
            id: (string) ($data['id'] ?? ''),
            target: (string) ($data['target'] ?? ''),
            events: is_array($events) ? array_values(array_map(strval(...), $events)) : [],
            enabled: (bool) ($data['enabled'] ?? true),
            secret: isset($data['secret']) && $data['secret'] !== '' ? (string) $data['secret'] : null,
            allowedIps: self::stringList($data['allowed_ips'] ?? []),
            name: isset($data['name']) && $data['name'] !== '' ? (string) $data['name'] : null,
            createdAt: (string) ($data['created_at'] ?? ''),
            updatedAt: (string) ($data['updated_at'] ?? ''),
        );
    }

    /**
     * Return a copy with the given validated fields overridden and a bumped
     * updated_at. Only keys present in $changes are applied (partial update).
     *
     * @param array{target?: string, events?: list<string>, enabled?: bool, secret?: ?string, allowed_ips?: list<string>, name?: ?string} $changes
     */
    public function withChanges(array $changes): self
    {
        return new self(
            id: $this->id,
            target: $changes['target'] ?? $this->target,
            events: $changes['events'] ?? $this->events,
            enabled: $changes['enabled'] ?? $this->enabled,
            secret: array_key_exists('secret', $changes)
                ? (($changes['secret'] ?? '') !== '' ? $changes['secret'] : null)
                : $this->secret,
            allowedIps: $changes['allowed_ips'] ?? $this->allowedIps,
            name: array_key_exists('name', $changes)
                ? (($changes['name'] ?? '') !== '' ? $changes['name'] : null)
                : $this->name,
            createdAt: $this->createdAt,
            updatedAt: date('c'),
        );
    }

    public function withEnabled(bool $enabled): self
    {
        return $this->withChanges(['enabled' => $enabled]);
    }

    /**
     * Does this subscription fire for $event? Supports exact match, a global
     * "*", and trailing wildcards ("users.*" matches "users.created").
     */
    public function subscribesTo(string $event): bool
    {
        foreach ($this->events as $selector) {
            if ($selector === '*' || $selector === $event) {
                return true;
            }
            if (str_ends_with($selector, '.*')) {
                $prefix = substr($selector, 0, -1); // keep the dot: "users."
                if (str_starts_with($event, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Full persisted shape written to the registry (includes the secret).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'target' => $this->target,
            'events' => $this->events,
            'enabled' => $this->enabled,
            'secret' => $this->secret,
            'allowed_ips' => $this->allowedIps,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Client-facing detail — the secret is never exposed, only whether one is set.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'target' => $this->target,
            'events' => $this->events,
            'enabled' => $this->enabled,
            'has_secret' => $this->secret !== null,
            'allowed_ips' => $this->allowedIps,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Validate and normalize raw client input. $partial=true (update) allows any
     * subset of keys; $partial=false (create) requires target + events.
     *
     * @param array<string, mixed> $body
     * @return array{target?: string, events?: list<string>, enabled?: bool, secret?: ?string, allowed_ips?: list<string>, name?: ?string}
     * @throws ValidationException
     */
    public static function validate(array $body, bool $partial): array
    {
        $errors = [];
        $out = [];

        if (!$partial || array_key_exists('target', $body)) {
            $target = is_string($body['target'] ?? null) ? trim((string) $body['target']) : '';
            if ($target === '' || filter_var($target, FILTER_VALIDATE_URL) === false
                || !in_array(strtolower((string) parse_url($target, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                $errors['target'] = 'A valid http(s) target URL is required';
            } else {
                $out['target'] = $target;
            }
        }

        if (!$partial || array_key_exists('events', $body)) {
            $events = $body['events'] ?? null;
            if (!is_array($events) || $events === []) {
                $errors['events'] = 'A non-empty array of event names is required';
            } else {
                $clean = [];
                foreach ($events as $event) {
                    if (!is_string($event) || trim($event) === '') {
                        $errors['events'] = 'Each event must be a non-empty string';
                        $clean = [];
                        break;
                    }
                    $clean[] = trim($event);
                }
                if ($clean !== []) {
                    $out['events'] = array_values(array_unique($clean));
                }
            }
        }

        if (array_key_exists('enabled', $body)) {
            if (!is_bool($body['enabled'])) {
                $errors['enabled'] = 'enabled must be a boolean';
            } else {
                $out['enabled'] = $body['enabled'];
            }
        }

        if (array_key_exists('secret', $body)) {
            if ($body['secret'] !== null && !is_string($body['secret'])) {
                $errors['secret'] = 'secret must be a string or null';
            } else {
                $out['secret'] = $body['secret'] === null ? null : (string) $body['secret'];
            }
        }

        if (array_key_exists('name', $body)) {
            if ($body['name'] !== null && !is_string($body['name'])) {
                $errors['name'] = 'name must be a string or null';
            } else {
                $out['name'] = $body['name'] === null ? null : trim((string) $body['name']);
            }
        }

        if (array_key_exists('allowed_ips', $body)) {
            $ips = $body['allowed_ips'];
            if (!is_array($ips) || array_filter($ips, static fn($v): bool => !is_string($v)) !== []) {
                $errors['allowed_ips'] = 'allowed_ips must be an array of strings';
            } else {
                $out['allowed_ips'] = array_values(array_map(strval(...), $ips));
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $out;
    }

    private static function newId(): string
    {
        return 'wh_' . bin2hex(random_bytes(12));
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(strval(...), array_filter($value, static fn($v): bool => is_scalar($v))));
    }
}
