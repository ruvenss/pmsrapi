<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Database;

use Pmsrapi\V2\Cache\QueryCache;
use Pmsrapi\V2\Exception\NotFoundException;
use Pmsrapi\V2\Exception\ValidationException;

/**
 * Introspects table/column names so identifiers can be WHITELISTED.
 *
 * Bound parameters protect VALUES, but SQL identifiers (table & column names,
 * ORDER BY targets) can never be bound — so v2 validates every identifier
 * against the live schema before interpolating it. Schema reads are cached in
 * Redis (via QueryCache) so this costs one information_schema lookup, not one
 * per request.
 */
final class Schema
{
    private const SCHEMA_TTL = 300;

    /** @var array<string, list<string>> in-process cache for this request */
    private array $columnCache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly QueryCache $cache,
    ) {}

    /**
     * @return list<string> column names for a table (empty if the table is unknown)
     */
    public function columns(string $table): array
    {
        $this->assertIdentifierShape($table);

        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }

        /** @var list<string> $columns */
        $columns = $this->cache->remember(
            '__schema__',
            ['columns', $this->connection->databaseName(), $table],
            fn(): array => array_map(
                static fn(array $row): string => (string) $row['COLUMN_NAME'],
                $this->connection->select(
                    'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                    [$this->connection->databaseName(), $table],
                ),
            ),
            self::SCHEMA_TTL,
        );

        return $this->columnCache[$table] = $columns;
    }

    public function primaryKey(string $table): string
    {
        $this->assertIdentifierShape($table);

        /** @var string|null $pk */
        $pk = $this->cache->remember(
            '__schema__',
            ['pk', $this->connection->databaseName(), $table],
            fn(): ?string => (string) ($this->connection->scalar(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_KEY = 'PRI' LIMIT 1",
                [$this->connection->databaseName(), $table],
            ) ?? '') ?: null,
            self::SCHEMA_TTL,
        );

        if ($pk === null || $pk === '') {
            throw new ValidationException(['table' => "Table '{$table}' has no primary key"]);
        }

        return $pk;
    }

    public function assertTable(string $table): void
    {
        if ($this->columns($table) === []) {
            throw new NotFoundException("Unknown table: {$table}");
        }
    }

    public function assertColumns(string $table, string ...$columns): void
    {
        $known = $this->columns($table);
        foreach ($columns as $column) {
            if (!in_array($column, $known, true)) {
                throw new ValidationException(['column' => "Unknown column '{$column}' on '{$table}'"]);
            }
        }
    }

    /**
     * Backtick-quote an identifier that has ALREADY been whitelisted.
     */
    public function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function assertIdentifierShape(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new ValidationException(['identifier' => "Illegal identifier: {$identifier}"]);
        }
    }
}
