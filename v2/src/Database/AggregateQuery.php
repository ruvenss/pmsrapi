<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Database;

use Pmsrapi\V2\Exception\ValidationException;

/**
 * Builds advanced SELECTs (GROUP BY, aggregates, GROUP_CONCAT, CONCAT, HAVING,
 * DISTINCT) from a STRUCTURED spec — never from raw SQL.
 *
 * Safety model, identical to the rest of the data layer:
 *   - every identifier (table, column, order/group target) is validated against
 *     the live schema and backtick-quoted;
 *   - every value (CONCAT literals, WHERE/HAVING operands) is a bound parameter;
 *   - aggregate functions and comparison operators come from fixed allowlists.
 *
 * A spec looks like:
 *   {
 *     "select": [
 *       { "column": "status" },
 *       { "fn": "count", "as": "total" },
 *       { "fn": "sum", "column": "amount", "as": "revenue" },
 *       { "fn": "group_concat", "column": "name", "separator": ", ", "as": "names" },
 *       { "concat": ["first_name", { "literal": " " }, "last_name"], "as": "full" }
 *     ],
 *     "where":    { "status": "active" },
 *     "group_by": ["status"],
 *     "having":   { "total": { "op": ">", "value": 5 } },
 *     "order":    "total:desc",
 *     "limit": 100, "offset": 0, "distinct": false
 *   }
 */
final class AggregateQuery
{
    private const AGG_FNS = ['count', 'sum', 'avg', 'min', 'max', 'group_concat'];
    private const OPS = ['=', '!=', '<>', '>', '>=', '<', '<='];

    public function __construct(private readonly Schema $schema) {}

    /**
     * @param array<string, mixed> $spec
     * @return array{0: string, 1: list<scalar|null>} [sql, bound params]
     */
    public function build(string $table, array $spec): array
    {
        $this->schema->assertTable($table);
        $columns = $this->schema->columns($table);

        $params = [];
        $aliases = [];

        // -- SELECT list --
        $selectSpec = $spec['select'] ?? [];
        if (!is_array($selectSpec) || $selectSpec === []) {
            throw new ValidationException(['select' => 'A non-empty "select" array is required']);
        }
        $parts = [];
        foreach ($selectSpec as $item) {
            [$expr, $itemParams, $alias] = $this->selectItem($columns, is_array($item) ? $item : []);
            $parts[] = $expr;
            array_push($params, ...$itemParams);
            if ($alias !== null) {
                $aliases[] = $alias;
            }
        }

        $sql = 'SELECT ' . (!empty($spec['distinct']) ? 'DISTINCT ' : '')
            . implode(', ', $parts) . ' FROM ' . $this->schema->quote($table);

        // -- WHERE (equality) --
        [$where, $whereParams] = $this->where($columns, is_array($spec['where'] ?? null) ? $spec['where'] : []);
        $sql .= $where;
        array_push($params, ...$whereParams);

        // -- GROUP BY --
        if (!empty($spec['group_by'])) {
            $group = [];
            foreach ((array) $spec['group_by'] as $col) {
                $this->assertColumn($columns, (string) $col);
                $group[] = $this->schema->quote((string) $col);
            }
            if ($group !== []) {
                $sql .= ' GROUP BY ' . implode(', ', $group);
            }
        }

        // -- HAVING (on aliases or columns) --
        if (!empty($spec['having'])) {
            [$having, $havingParams] = $this->having($columns, $aliases, (array) $spec['having']);
            $sql .= $having;
            array_push($params, ...$havingParams);
        }

        // -- ORDER BY (column or alias) --
        if (!empty($spec['order'])) {
            $sql .= $this->order($columns, $aliases, (string) $spec['order']);
        }

        // -- LIMIT / OFFSET (ints, safe to inline) --
        if (isset($spec['limit'])) {
            $sql .= ' LIMIT ' . max(0, (int) $spec['limit']) . ' OFFSET ' . max(0, (int) ($spec['offset'] ?? 0));
        }

        return [$sql, $params];
    }

    /**
     * @param list<string>         $columns
     * @param array<string, mixed> $item
     * @return array{0: string, 1: list<scalar|null>, 2: ?string} expr, params, alias
     */
    private function selectItem(array $columns, array $item): array
    {
        $alias = isset($item['as']) ? $this->assertAlias((string) $item['as']) : null;
        $aliasSql = $alias !== null ? ' AS ' . $this->schema->quote($alias) : '';

        // Order matters: aggregate items carry both "fn" and "column".
        if (isset($item['fn'])) {
            return [$this->aggregate($columns, $item) . $aliasSql, [], $alias];
        }

        if (isset($item['concat']) && is_array($item['concat'])) {
            $pieces = [];
            $params = [];
            foreach ($item['concat'] as $piece) {
                if (is_array($piece) && array_key_exists('literal', $piece)) {
                    $pieces[] = '?';
                    $params[] = is_scalar($piece['literal']) ? $piece['literal'] : (string) $piece['literal'];
                } else {
                    $this->assertColumn($columns, (string) $piece);
                    $pieces[] = $this->schema->quote((string) $piece);
                }
            }
            if ($pieces === []) {
                throw new ValidationException(['concat' => '"concat" needs at least one column or literal']);
            }
            return ['CONCAT(' . implode(', ', $pieces) . ')' . $aliasSql, $params, $alias];
        }

        if (isset($item['column'])) {
            $this->assertColumn($columns, (string) $item['column']);
            return [$this->schema->quote((string) $item['column']) . $aliasSql, [], $alias];
        }

        throw new ValidationException(['select' => 'Each item needs one of "column", "fn" or "concat"']);
    }

    /**
     * @param list<string>         $columns
     * @param array<string, mixed> $item
     */
    private function aggregate(array $columns, array $item): string
    {
        $fn = strtolower((string) $item['fn']);
        if (!in_array($fn, self::AGG_FNS, true)) {
            throw new ValidationException(['fn' => "Unsupported aggregate: {$fn}"]);
        }
        $distinct = !empty($item['distinct']) ? 'DISTINCT ' : '';

        if ($fn === 'count' && empty($item['column'])) {
            return 'COUNT(*)';
        }

        $this->assertColumn($columns, (string) ($item['column'] ?? ''));
        $col = $this->schema->quote((string) $item['column']);

        if ($fn === 'group_concat') {
            $sep = isset($item['separator'])
                ? " SEPARATOR '" . $this->safeSeparator((string) $item['separator']) . "'"
                : '';
            return "GROUP_CONCAT({$distinct}{$col}{$sep})";
        }

        return strtoupper($fn) . "({$distinct}{$col})";
    }

    /**
     * @param list<string>              $columns
     * @param array<string, mixed>|list<mixed> $filters
     * @return array{0: string, 1: list<scalar|null>}
     */
    private function where(array $columns, array $filters): array
    {
        if ($filters === []) {
            return ['', []];
        }
        $clauses = [];
        $params = [];
        foreach ($filters as $col => $value) {
            $this->assertColumn($columns, (string) $col);
            if ($value === null) {
                $clauses[] = $this->schema->quote((string) $col) . ' IS NULL';
            } else {
                $clauses[] = $this->schema->quote((string) $col) . ' = ?';
                $params[] = is_scalar($value) ? $value : (string) $value;
            }
        }
        return [' WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @param list<string>         $columns
     * @param list<string>         $aliases
     * @param array<string, mixed> $conditions
     * @return array{0: string, 1: list<scalar|null>}
     */
    private function having(array $columns, array $aliases, array $conditions): array
    {
        $clauses = [];
        $params = [];
        foreach ($conditions as $name => $cond) {
            $name = (string) $name;
            if (!in_array($name, $aliases, true) && !in_array($name, $columns, true)) {
                throw new ValidationException(['having' => "Unknown having target: {$name}"]);
            }
            $op = is_array($cond) ? (string) ($cond['op'] ?? '=') : '=';
            $value = is_array($cond) ? ($cond['value'] ?? null) : $cond;
            if (!in_array($op, self::OPS, true)) {
                throw new ValidationException(['having' => "Unsupported operator: {$op}"]);
            }
            $clauses[] = $this->schema->quote($name) . " {$op} ?";
            $params[] = is_scalar($value) ? $value : (string) $value;
        }
        return [' HAVING ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @param list<string> $columns
     * @param list<string> $aliases
     */
    private function order(array $columns, array $aliases, string $order): string
    {
        [$col, $dir] = array_pad(explode(':', $order, 2), 2, 'asc');
        $col = trim($col);
        if (!in_array($col, $aliases, true) && !in_array($col, $columns, true)) {
            throw new ValidationException(['order' => "Cannot order by unknown target: {$col}"]);
        }
        return ' ORDER BY ' . $this->schema->quote($col) . (strtolower($dir) === 'desc' ? ' DESC' : ' ASC');
    }

    /**
     * @param list<string> $columns
     */
    private function assertColumn(array $columns, string $column): void
    {
        if ($column === '' || !in_array($column, $columns, true)) {
            throw new ValidationException(['column' => "Unknown column: {$column}"]);
        }
    }

    private function assertAlias(string $alias): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) !== 1) {
            throw new ValidationException(['as' => "Illegal alias: {$alias}"]);
        }
        return $alias;
    }

    private function safeSeparator(string $sep): string
    {
        // No quotes/backslashes possible -> safe to inline as a string literal.
        if (preg_match('/^[\w \-,;:.|]{1,16}$/u', $sep) !== 1) {
            throw new ValidationException(['separator' => 'Separator must be 1-16 simple characters']);
        }
        return $sep;
    }
}
