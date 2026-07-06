<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Http\Controllers;

use Pmsrapi\V2\Cache\QueryCache;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Database\Schema;
use Pmsrapi\V2\Exception\NotFoundException;
use Pmsrapi\V2\Exception\ValidationException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Http\ResourceDefinition;
use Pmsrapi\V2\Queue\WebhookDispatcher;
use Pmsrapi\V2\Support\Paginator;

/**
 * One generic controller drives CRUD for every configured resource.
 *
 * Reads are cached in Redis and paginated; writes invalidate the cache and emit
 * a webhook event ({resource}.created|updated|deleted). All identifiers pass
 * through Schema whitelisting, all values are bound — no raw SQL reaches here.
 */
final class CrudController
{
    /** Query-string keys reserved for controls (not treated as filters). */
    private const RESERVED_QUERY = ['page', 'per_page', 'order', 'fields'];

    public function __construct(
        private readonly Repository $repository,
        private readonly Schema $schema,
        private readonly QueryCache $cache,
        private readonly WebhookDispatcher $webhooks,
    ) {}

    public function index(ResourceDefinition $def, Request $request): Response
    {
        $paginator = Paginator::fromRequest($request, $def->perPage);
        $filters = $this->filters($def, $request);
        $order = $request->query('order');

        /** @var array{data: list<array<string, mixed>>, total: int} $result */
        $result = $this->cache->remember(
            $def->table,
            ['list', $filters, $order, $paginator->page, $paginator->perPage],
            fn(): array => [
                'data' => $this->repository->selectRows($def->table, $filters, $order, $paginator->limit(), $paginator->offset()),
                'total' => $this->repository->count($def->table, $filters),
            ],
            $def->cacheTtl,
        );

        return Response::ok($result['data'], $paginator->meta((int) $result['total']));
    }

    public function show(ResourceDefinition $def, Request $request, string $id): Response
    {
        $row = $this->cache->remember(
            $def->table,
            ['show', $id],
            fn(): ?array => $this->repository->selectRow($def->table, [$this->schema->primaryKey($def->table) => $id]),
            $def->cacheTtl,
        );

        if ($row === null) {
            throw new NotFoundException("{$def->name} not found: {$id}");
        }

        return Response::ok($row);
    }

    public function store(ResourceDefinition $def, Request $request): Response
    {
        $data = $this->scalarBody($request);
        $id = $this->repository->insertRow($def->table, $data);
        $this->cache->invalidate($def->table);

        $row = $this->repository->selectRow($def->table, [$this->schema->primaryKey($def->table) => $id]);
        $this->webhooks->emit("{$def->name}.created", ['id' => $id, 'record' => $row]);

        return Response::created($row ?? ['id' => $id]);
    }

    public function update(ResourceDefinition $def, Request $request, string $id): Response
    {
        $data = $this->scalarBody($request);
        $pk = $this->schema->primaryKey($def->table);

        if ($this->repository->selectRow($def->table, [$pk => $id]) === null) {
            throw new NotFoundException("{$def->name} not found: {$id}");
        }

        $this->repository->updateById($def->table, $id, $data);
        $this->cache->invalidate($def->table);

        $row = $this->repository->selectRow($def->table, [$pk => $id]);
        $this->webhooks->emit("{$def->name}.updated", ['id' => $id, 'record' => $row]);

        return Response::ok($row);
    }

    public function destroy(ResourceDefinition $def, Request $request, string $id): Response
    {
        if ($this->repository->deleteById($def->table, $id) === 0) {
            throw new NotFoundException("{$def->name} not found: {$id}");
        }

        $this->cache->invalidate($def->table);
        $this->webhooks->emit("{$def->name}.deleted", ['id' => $id]);

        return Response::noContent();
    }

    /**
     * Advanced read: GROUP BY / aggregates / GROUP_CONCAT / CONCAT / HAVING.
     * The structured spec is the request body (see AggregateQuery). Cached like
     * any read and invalidated by writes.
     */
    public function query(ResourceDefinition $def, Request $request): Response
    {
        if ($request->body === []) {
            throw new ValidationException(['body' => 'A query spec with a "select" array is required']);
        }
        $spec = $request->body;

        $rows = $this->cache->remember(
            $def->table,
            ['aggregate', $spec],
            fn(): array => $this->repository->aggregate($def->table, $spec),
            $def->cacheTtl,
        );

        return Response::ok($rows);
    }

    /**
     * Upsert — insert, or update the existing row on a duplicate key
     * ("IF EXISTS THEN UPDATE"). Body: { "values": {...}, "update": [cols?] }.
     * 201 when a row was inserted, 200 when updated/unchanged.
     */
    public function upsert(ResourceDefinition $def, Request $request): Response
    {
        $values = $request->body['values'] ?? null;
        if (!is_array($values) || $values === []) {
            throw new ValidationException(['values' => 'A non-empty "values" object is required']);
        }
        foreach ($values as $key => $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new ValidationException([(string) $key => 'Value must be a scalar or null']);
            }
        }
        $updateColumns = isset($request->body['update']) && is_array($request->body['update'])
            ? array_map(strval(...), $request->body['update'])
            : null;

        /** @var array<string, scalar|null> $values */
        $result = $this->repository->upsert($def->table, $values, $updateColumns);
        $this->cache->invalidate($def->table);
        $this->webhooks->emit("{$def->name}.upserted", ['id' => $result['id'], 'action' => $result['action']]);

        $payload = ['action' => $result['action'], 'id' => $result['id'], 'record' => $result['record']];

        return $result['action'] === 'inserted'
            ? Response::created($payload)
            : Response::ok($payload);
    }

    /**
     * Equality filters from the query string — only keys that are real columns.
     *
     * @return array<string, string>
     */
    private function filters(ResourceDefinition $def, Request $request): array
    {
        $columns = $this->schema->columns($def->table);
        $filters = [];

        foreach ($request->query as $key => $value) {
            if (in_array($key, self::RESERVED_QUERY, true)) {
                continue;
            }
            if (in_array($key, $columns, true)) {
                $filters[$key] = (string) $value;
            }
        }

        return $filters;
    }

    /**
     * Body must be a non-empty JSON object of scalar (or null) values.
     *
     * @return array<string, scalar|null>
     */
    private function scalarBody(Request $request): array
    {
        if ($request->body === []) {
            throw new ValidationException(['body' => 'Request body must be a non-empty JSON object']);
        }

        $errors = [];
        foreach ($request->body as $key => $value) {
            if ($value !== null && !is_scalar($value)) {
                $errors[(string) $key] = 'Value must be a scalar or null';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var array<string, scalar|null> $body */
        $body = $request->body;
        return $body;
    }
}
