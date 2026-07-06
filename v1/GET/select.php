<?php

declare(strict_types=1);

/**
 * select.php
 * Endpoint to select a single field from a table
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function select(): void
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
        "values"            => [$field => sqlSelect($table, $field, $where)],
        "table_last_update" => getTableLastUpdateTime($table),
    ]);
}
select();
