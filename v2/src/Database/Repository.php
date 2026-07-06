<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Database;

use Pmsrapi\V2\Exception\ValidationException;

/**
 * Generic, schema-safe data access. This is the modern, prepared-statement
 * replacement for v1's sql* helpers (sqlSelectRows/sqlSelectRow/sqlInsert/
 * sqlUpdate/sqlDelete/sqlCount) in general/db.php.
 *
 * Rules enforced here:
 *  - VALUES are always bound parameters (never concatenated).
 *  - IDENTIFIERS (table, columns, order-by) are validated against the live
 *    schema and backtick-quoted — never taken raw from the client.
 */
final class Repository
{
    private readonly AggregateQuery $aggregateBuilder;

    public function __construct(
        private readonly Connection $connection,
        private readonly Schema $schema,
    ) {
        $this->aggregateBuilder = new AggregateQuery($schema);
    }

    /**
     * Run a structured advanced SELECT (GROUP BY, aggregates, GROUP_CONCAT,
     * CONCAT, HAVING, DISTINCT). Identifiers are whitelisted, values bound.
     *
     * @param array<string, mixed> $spec see AggregateQuery
     * @return list<array<string, mixed>>
     */
    public function aggregate(string $table, array $spec): array
    {
        [$sql, $params] = $this->aggregateBuilder->build($table, $spec);
        return $this->connection->select($sql, $params);
    }

    /**
     * Insert, or update the existing row on a duplicate key
     * ("IF EXISTS THEN UPDATE") via INSERT … ON DUPLICATE KEY UPDATE.
     *
     * @param array<string, scalar|null> $data          full row to insert
     * @param list<string>|null          $updateColumns columns to overwrite on
     *                                                   conflict (default: all
     *                                                   provided columns except PK)
     * @return array{action: string, affected: int, id: int|string|null, record: array<string, mixed>|null}
     */
    public function upsert(string $table, array $data, ?array $updateColumns = null): array
    {
        $this->schema->assertTable($table);
        $data = $this->filterKnownColumns($table, $data);
        if ($data === []) {
            throw new ValidationException(['values' => 'No valid columns to upsert']);
        }

        $pk = $this->schema->primaryKey($table);
        $columns = array_keys($data);

        $toUpdate = $updateColumns !== null
            ? array_values(array_intersect($updateColumns, $columns))
            : array_values(array_diff($columns, [$pk]));
        $this->schema->assertColumns($table, ...$toUpdate);

        $assignments = array_map(
            fn(string $c): string => $this->schema->quote($c) . ' = VALUES(' . $this->schema->quote($c) . ')',
            $toUpdate,
        );
        // Make insert_id return the existing row's id on update too.
        $assignments[] = $this->schema->quote($pk) . ' = LAST_INSERT_ID(' . $this->schema->quote($pk) . ')';

        $sql = 'INSERT INTO ' . $this->schema->quote($table)
            . ' (' . implode(', ', array_map($this->schema->quote(...), $columns)) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);

        $res = $this->connection->modify($sql, array_values($data));

        // affected_rows: 1 = inserted, 2 = updated, 0 = matched but unchanged.
        $action = match (true) {
            $res['affected'] === 1 => 'inserted',
            $res['affected'] >= 2 => 'updated',
            default => 'unchanged',
        };

        $id = $res['insert_id'] > 0 ? $res['insert_id'] : ($data[$pk] ?? null);
        $record = $id !== null ? $this->selectRow($table, [$pk => $id]) : null;

        return ['action' => $action, 'affected' => $res['affected'], 'id' => $id, 'record' => $record];
    }

    /**
     * @param array<string, scalar|null> $filters column => value equality filters
     * @return list<array<string, mixed>>
     */
    public function selectRows(
        string $table,
        array $filters = [],
        ?string $orderBy = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        $this->schema->assertTable($table);
        [$where, $params] = $this->buildWhere($table, $filters);

        $sql = 'SELECT * FROM ' . $this->schema->quote($table) . $where . $this->buildOrderBy($table, $orderBy);

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset ?? 0);
        }

        return $this->connection->select($sql, $params);
    }

    /**
     * @param array<string, scalar|null> $filters
     * @return array<string, mixed>|null
     */
    public function selectRow(string $table, array $filters): ?array
    {
        return $this->selectRows($table, $filters, limit: 1)[0] ?? null;
    }

    /**
     * @param array<string, scalar|null> $filters
     */
    public function count(string $table, array $filters = []): int
    {
        $this->schema->assertTable($table);
        [$where, $params] = $this->buildWhere($table, $filters);

        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM ' . $this->schema->quote($table) . $where,
            $params,
        );
    }

    /**
     * @param array<string, scalar|null> $data column => value
     * @return int new primary-key id
     */
    public function insertRow(string $table, array $data): int
    {
        $this->schema->assertTable($table);
        $data = $this->filterKnownColumns($table, $data);

        if ($data === []) {
            throw new ValidationException(['values' => 'No valid columns to insert']);
        }

        $columns = array_map($this->schema->quote(...), array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = 'INSERT INTO ' . $this->schema->quote($table)
            . ' (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';

        return $this->connection->insert($sql, array_values($data));
    }

    /**
     * @param array<string, scalar|null> $data
     * @return int affected rows
     */
    public function updateById(string $table, string|int $id, array $data): int
    {
        $this->schema->assertTable($table);
        $pk = $this->schema->primaryKey($table);
        $data = $this->filterKnownColumns($table, $data);

        if ($data === []) {
            throw new ValidationException(['values' => 'No valid columns to update']);
        }

        $assignments = implode(', ', array_map(
            fn(string $col): string => $this->schema->quote($col) . ' = ?',
            array_keys($data),
        ));

        $sql = 'UPDATE ' . $this->schema->quote($table) . ' SET ' . $assignments
            . ' WHERE ' . $this->schema->quote($pk) . ' = ?';

        return $this->connection->affect($sql, [...array_values($data), $id]);
    }

    public function deleteById(string $table, string|int $id): int
    {
        $this->schema->assertTable($table);
        $pk = $this->schema->primaryKey($table);

        return $this->connection->affect(
            'DELETE FROM ' . $this->schema->quote($table) . ' WHERE ' . $this->schema->quote($pk) . ' = ?',
            [$id],
        );
    }

    /**
     * @param array<string, scalar|null> $filters
     * @return array{0: string, 1: list<scalar|null>} where-clause (with leading space) and bound params
     */
    private function buildWhere(string $table, array $filters): array
    {
        if ($filters === []) {
            return ['', []];
        }

        $clauses = [];
        $params = [];

        foreach ($filters as $column => $value) {
            $this->schema->assertColumns($table, (string) $column);
            if ($value === null) {
                $clauses[] = $this->schema->quote((string) $column) . ' IS NULL';
            } else {
                $clauses[] = $this->schema->quote((string) $column) . ' = ?';
                $params[] = $value;
            }
        }

        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }

    private function buildOrderBy(string $table, ?string $orderBy): string
    {
        if ($orderBy === null || $orderBy === '') {
            return '';
        }

        [$column, $direction] = array_pad(explode(':', $orderBy, 2), 2, 'asc');
        $this->schema->assertColumns($table, $column);
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';

        return ' ORDER BY ' . $this->schema->quote($column) . ' ' . $direction;
    }

    /**
     * Drop any keys the client sent that are not real columns.
     *
     * @param array<string, scalar|null> $data
     * @return array<string, scalar|null>
     */
    private function filterKnownColumns(string $table, array $data): array
    {
        $known = $this->schema->columns($table);
        return array_filter(
            $data,
            static fn(string $key): bool => in_array($key, $known, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
