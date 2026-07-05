<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Database;

use mysqli;
use mysqli_sql_exception;
use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Debug\DebugRecorder;
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

    public function __construct(
        private readonly Config $config,
        private readonly ?DebugRecorder $recorder = null,
    ) {
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

    /**
     * Liveness probe for diagnostics (/info, /health). Returns true only if a
     * live connection can run a trivial query. NEVER throws — any failure, or
     * "no database configured", returns false. Not every microservice needs a
     * database, so callers combine this with isConfigured() to distinguish
     * "absent" from "configured but down".
     */
    public function isAlive(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            // SELECT 1 also validates a pooled persistent connection is still
            // alive (mysqli::ping() is deprecated as of PHP 8.4).
            return (int) $this->scalar('SELECT 1') === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function connection(): mysqli
    {
        if ($this->mysqli instanceof mysqli) {
            return $this->mysqli;
        }

        if (!$this->isConfigured()) {
            throw new DatabaseException('No database configured in secret config (db block missing).');
        }

        // Enforce PERSISTENT connections (project convention): mysqli reuses a
        // pooled connection when the host is prefixed with "p:", so we avoid a
        // TCP handshake + auth on every request. mysqli implicitly resets
        // session state (rolls back open transactions, closes handlers, etc.)
        // before handing a pooled connection back, so reuse is safe.
        $host = (string) $this->config->secret('db.host', '127.0.0.1');
        if (!str_starts_with($host, 'p:')) {
            $host = 'p:' . $host;
        }

        try {
            $mysqli = new mysqli(
                $host,
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
        $start = microtime(true);
        try {
            $stmt = $this->connection()->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->get_result();
            /** @var list<array<string, mixed>> $rows */
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $this->recorder?->recordQuery($sql, $params, (microtime(true) - $start) * 1000, count($rows));

            return $rows;
        } catch (mysqli_sql_exception $e) {
            $this->recorder?->recordQuery($sql, $params, (microtime(true) - $start) * 1000, -1);
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
     * Run a write and return BOTH the affected-row count and the insert id.
     * Used by upsert (INSERT … ON DUPLICATE KEY UPDATE), where affected_rows
     * distinguishes insert (1) from update (2) and the id comes back via the
     * LAST_INSERT_ID() trick.
     *
     * @param list<scalar|null> $params
     * @return array{affected: int, insert_id: int}
     */
    public function modify(string $sql, array $params = []): array
    {
        $start = microtime(true);
        try {
            $conn = $this->connection();
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $result = ['affected' => (int) $stmt->affected_rows, 'insert_id' => (int) $conn->insert_id];
            $stmt->close();

            $this->recorder?->recordQuery($sql, $params, (microtime(true) - $start) * 1000, $result['affected']);

            return $result;
        } catch (mysqli_sql_exception $e) {
            $this->recorder?->recordQuery($sql, $params, (microtime(true) - $start) * 1000, -1);
            throw new DatabaseException('Upsert failed', $e);
        }
    }

    /**
     * @param list<scalar|null> $params
     */
    private function run(string $sql, array $params, bool $returnInsertId): int
    {
        $start = microtime(true);
        try {
            $conn = $this->connection();
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $affected = $stmt->affected_rows;
            $value = $returnInsertId ? $conn->insert_id : $affected;
            $stmt->close();

            $this->recorder?->recordQuery($sql, $params, (microtime(true) - $start) * 1000, $affected);

            return (int) $value;
        } catch (mysqli_sql_exception $e) {
            $this->recorder?->recordQuery($sql, $params, (microtime(true) - $start) * 1000, -1);
            throw new DatabaseException('Write failed', $e);
        }
    }

    public function close(): void
    {
        $this->mysqli?->close();
        $this->mysqli = null;
    }
}
