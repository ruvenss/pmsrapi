<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Http\Controllers;

use Pmsrapi\V2\Exception\NotFoundException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Webhook\Webhook;
use Pmsrapi\V2\Webhook\WebhookStore;

/**
 * Runtime management of the webhook registry (the external JSON store).
 *
 *   GET    /webhooks                list subscriptions (secrets masked)
 *   GET    /webhooks/{id}           one subscription's details
 *   POST   /webhooks                create a subscription
 *   PUT    /webhooks/{id}           update (partial merge of provided fields)
 *   PATCH  /webhooks/{id}           update (partial merge of provided fields)
 *   DELETE /webhooks/{id}           delete a subscription
 *   POST   /webhooks/{id}/enable    enable delivery
 *   POST   /webhooks/{id}/disable   disable delivery (kept, not deleted)
 *   POST   /webhooks/rebuild        build/rebuild the whole file from { "webhooks": [...] }
 *
 * All routes are Bearer-authenticated (they are not in AuthMiddleware's public
 * allowlist). Secrets are never returned — only whether one is set.
 */
final class WebhookController
{
    public function __construct(
        private readonly WebhookStore $store,
    ) {}

    public function index(Request $request): Response
    {
        $webhooks = array_map(
            static fn(Webhook $w): array => $w->toPublicArray(),
            $this->store->all(),
        );

        return Response::ok($webhooks, ['total' => count($webhooks)]);
    }

    public function show(Request $request, string $id): Response
    {
        return Response::ok($this->require($id)->toPublicArray());
    }

    public function store(Request $request): Response
    {
        $input = Webhook::validate($request->body, partial: false);
        /** @var array{target: string, events: list<string>, enabled?: bool, secret?: ?string, allowed_ips?: list<string>, name?: ?string} $input */
        $webhook = Webhook::create($input);
        $this->store->save($webhook);

        return Response::created($webhook->toPublicArray());
    }

    public function update(Request $request, string $id): Response
    {
        $webhook = $this->require($id);
        $changes = Webhook::validate($request->body, partial: true);

        if ($changes === []) {
            throw new ValidationException(['body' => 'No updatable fields provided']);
        }

        $updated = $webhook->withChanges($changes);
        $this->store->save($updated);

        return Response::ok($updated->toPublicArray());
    }

    public function destroy(Request $request, string $id): Response
    {
        if (!$this->store->delete($id)) {
            throw new NotFoundException("Webhook not found: {$id}");
        }

        return Response::noContent();
    }

    public function enable(Request $request, string $id): Response
    {
        return $this->toggle($id, true);
    }

    public function disable(Request $request, string $id): Response
    {
        return $this->toggle($id, false);
    }

    /**
     * Build/rebuild the entire registry from a provided list, replacing whatever
     * is on disk. Body: { "webhooks": [ { target, events, ... }, ... ] }.
     * Existing ids are preserved when supplied; new entries mint fresh ids.
     */
    public function rebuild(Request $request): Response
    {
        $entries = $request->body['webhooks'] ?? null;
        if (!is_array($entries)) {
            throw new ValidationException(['webhooks' => 'A "webhooks" array is required']);
        }

        $webhooks = [];
        $errors = [];
        foreach (array_values($entries) as $index => $entry) {
            if (!is_array($entry)) {
                $errors["webhooks.{$index}"] = 'Each webhook must be an object';
                continue;
            }

            try {
                $input = Webhook::validate($entry, partial: false);
            } catch (ValidationException $e) {
                /** @var array<string, string> $fieldErrors */
                $fieldErrors = $e->details()['errors'] ?? [];
                foreach ($fieldErrors as $field => $message) {
                    $errors["webhooks.{$index}.{$field}"] = $message;
                }
                continue;
            }

            /** @var array{target: string, events: list<string>, enabled?: bool, secret?: ?string, allowed_ips?: list<string>, name?: ?string} $input */
            $webhook = Webhook::create($input);

            $id = $entry['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $webhook = new Webhook(
                    id: $id,
                    target: $webhook->target,
                    events: $webhook->events,
                    enabled: $webhook->enabled,
                    secret: $webhook->secret,
                    allowedIps: $webhook->allowedIps,
                    name: $webhook->name,
                    createdAt: $webhook->createdAt,
                    updatedAt: $webhook->updatedAt,
                );
            }

            $webhooks[] = $webhook;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->store->replaceAll($webhooks);

        return Response::ok([
            'rebuilt' => true,
            'count' => count($webhooks),
            'webhooks' => array_map(static fn(Webhook $w): array => $w->toPublicArray(), $webhooks),
        ]);
    }

    private function toggle(string $id, bool $enabled): Response
    {
        $updated = $this->require($id)->withEnabled($enabled);
        $this->store->save($updated);

        return Response::ok($updated->toPublicArray());
    }

    private function require(string $id): Webhook
    {
        $webhook = $this->store->find($id);
        if ($webhook === null) {
            throw new NotFoundException("Webhook not found: {$id}");
        }

        return $webhook;
    }
}
