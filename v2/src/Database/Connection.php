<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Database;

use mysqli;
use mysqli_sql_exception;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Exception\DatabaseException;

/**
 * Lazy mysqli connection that speaks ONLY in prepared statements.
 *
 * v2 stays on the mysqli driver v1 used, but every query goes through
 * prepare()+execute() with bound parameters (PHP 8.1+ lets us pass the bind
 * array straight to execute()). This removes v1's string-concatenation SQL
 * injection surface while keeping the same driver and connection config.
 *
 * mysqli is put into exception mode so failures raise mysqli_sql_exception,
 * which we wrap as DatabaseException (client sees a generic 500; details are
 * logged server-side).
 */
final class Connection
{
    private ?mysqli $mysqli = null;

    public function __construct(private readonly Config $config)
    {
        // Turn silent mysqli warnings/false-returns into exceptions.
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    }

    public function databaseName(): string
    {
        return (string) $this->config->secret('db.name', '');
    }

    public function isConfigured(): bool
    {
        return $this->config->hasSecret('db.host');
    }

    private function connection(): mysqli
    {
        if ($this->mysqli instanceof mysqli) {
            return $this->mysqli;
        }

        if (!$this->isConfigured()) {
            throw new DatabaseException('No database configured in secret config (db block missing).');
        }

        try {
            $mysqli = new mysqli(
                (string) $this->config->secret('db.host', '127.0.0.1'),
                (string) $this->config->secret('db.username', ''),
                (string) $this->config->secret('db.password', ''),
                (string) $this->config->secret('db.name', ''),
                (int) $this->config->secret('db.port', 3306),
            );
            $mysqli->set_charset('utf8mb4');
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException('Database connection failed', $e);
        }

        return $this->mysqli = $mysqli;
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->connection()->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->get_result();
            /** @var list<array<string, mixed>> $rows */
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            return $rows;
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException('Query failed', $e);
        }
    }

    /**
     * @param list<scalar|null> $params
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        return $this->select($sql, $params)[0] ?? null;
    }

    /**
     * @param list<scalar|null> $params
     * @return int|string|null the single scalar value of the first column
     */
    public function scalar(string $sql, array $params = []): int|string|null
    {
        $row = $this->selectOne($sql, $params);
        if ($row === null) {
            return null;
        }
        /** @var int|string|null $value */
        $value = array_values($row)[0] ?? null;
        return $value;
    }

    /**
     * Run an INSERT and return the new auto-increment id.
     *
     * @param list<scalar|null> $params
     */
    public function insert(string $sql, array $params = []): int
    {
        return (int) $this->run($sql, $params, returnInsertId: true);
    }

    /**
     * Run an UPDATE/DELETE and return the number of affected rows.
     *
     * @param list<scalar|null> $params
     */
    public function affect(string $sql, array $params = []): int
    {
        return $this->run($sql, $params, returnInsertId: false);
    }

    /**
     * @param list<scalar|null> $params
     */
    private function run(string $sql, array $params, bool $returnInsertId): int
    {
        try {
            $conn = $this->connection();
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $value = $returnInsertId ? $conn->insert_id : $stmt->affected_rows;
            $stmt->close();

            return (int) $value;
        } catch (mysqli_sql_exception $e) {
            throw new DatabaseException('Write failed', $e);
        }
    }

    public function close(): void
    {
        $this->mysqli?->close();
        $this->mysqli = null;
    }
}
