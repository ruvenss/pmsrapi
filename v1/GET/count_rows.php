<?php

declare(strict_types=1);

/**
 * count_rows.php
 * Endpoint to count the number of rows in a table.
 * The table name and where clause are passed as parameters.
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function count_rows(): void
{
    $params = request_data['parameters'] ?? [];

    if (empty($params['table'])) {
        http_response(400, ["error" => "Bad Request: 'table' parameter is required"]);
    }

    if (empty($params['where'])) {
        http_response(400, ["error" => "Bad Request: 'where' parameter is required"]);
    }

    $table = $params['table'];
    $where = $params['where'];

    http_response(200, [
        "values"            => ["total" => sqlCount($table, $where)],
        "table_last_update" => getTableLastUpdateTime($table),
    ]);
}
count_rows();