<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Http\Controllers;

use Generator;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Database\Repository;
use Pmsrapi\V2\Database\Schema;
use Pmsrapi\V2\Exception\NotFoundException;
use Pmsrapi\V2\Http\Request;
use Pmsrapi\V2\Http\Response;
use Pmsrapi\V2\Http\ResourceDefinition;

/**
 * NDJSON streaming producers — the server side of the inter-service transport.
 *
 * GET /v2/stream/{resource}  — stream every row of a configured resource,
 *                              paged internally so memory stays flat regardless
 *                              of table size.
 * GET /v2/stream/_demo       — synthetic stream (no DB) for testing the pipe.
 */
final class StreamController
{
    private const RESERVED_QUERY = ['order', 'page', 'per_page'];

    public function __construct(
        private readonly Config $config,
        private readonly Repository $repository,
        private readonly Schema $schema,
    ) {}

    public function export(Request $request, string $resource): Response
    {
        $resources = $this->config->resources();
        if (!isset($resources[$resource])) {
            throw new NotFoundException("Unknown stream resource: {$resource}");
        }

        $def = ResourceDefinition::fromConfig($resource, (array) $resources[$resource]);
        $filters = $this->filters($def, $request);
        $order = $request->query('order');

        return Response::stream($this->rows($def, $filters, $order));
    }

    public function demo(Request $request): Response
    {
        $count = max(1, min(100000, $request->queryInt('count', 10)));

        return Response::stream((function () use ($count): Generator {
            for ($i = 1; $i <= $count; $i++) {
                yield [
                    'seq' => $i,
                    'service' => $this->config->name(),
                    'value' => bin2hex(random_bytes(4)),
                ];
            }
        })());
    }

    /**
     * @param array<string, string> $filters
     * @return Generator<int, array<string, mixed>>
     */
    private function rows(ResourceDefinition $def, array $filters, ?string $order): Generator
    {
        $page = 500;
        $offset = 0;

        do {
            $batch = $this->repository->selectRows($def->table, $filters, $order, $page, $offset);
            foreach ($batch as $row) {
                yield $row;
            }
            $offset += $page;
        } while (count($batch) === $page);
    }

    /**
     * @return array<string, string>
     */
    private function filters(ResourceDefinition $def, Request $request): array
    {
        $columns = $this->schema->columns($def->table);
        $filters = [];
        foreach ($request->query as $key => $value) {
            if (!in_array($key, self::RESERVED_QUERY, true) && in_array($key, $columns, true)) {
                $filters[$key] = (string) $value;
            }
        }
        return $filters;
    }
}
