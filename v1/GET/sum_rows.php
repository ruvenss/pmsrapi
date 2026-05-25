<?php

declare(strict_types=1);

/**
 * sum_rows.php
 * Endpoint to sum the values of a field in a table.
 * The table name, field name, and where clause are passed as parameters.
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function sum_rows(): void
{
    $params = request_data['parameters'] ?? [];

    if (empty($params['table'])) {
        http_response(400, ["error" => "Bad Request: 'table' parameter is required"]);
    }

    if (empty($params['field'])) {
        http_response(400, ["error" => "Bad Request: 'field' parameter is required"]);
    }

    if (empty($params['where'])) {
        http_response(400, ["error" => "Bad Request: 'where' parameter is required"]);
    }

    $table = $params['table'];
    $field = $params['field'];
    $where = $params['where'];

    http_response(200, [
        "values"            => ["total" => sqlSum($table, $field, $where)],
        "table_last_update" => getTableLastUpdateTime($table),
    ]);
}
sum_rows();