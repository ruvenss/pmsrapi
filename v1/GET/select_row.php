<?php

declare(strict_types=1);

/**
 * select_row.php
 * Endpoint to select a single row from a table
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function select_row(): void
{
    $params = request_data['parameters'] ?? [];

    if (empty($params['table'])) {
        http_response(400, ["error" => "Bad Request: 'table' parameter is required"]);
    }

    $table   = $params['table'];
    $fields  = $params['fields']  ?? "*";
    $where   = $params['where']   ?? "";
    $orderby = $params['orderby'] ?? "";

    $row = sqlSelectRow($table, $fields, $where, $orderby);

    http_response(200, [
        "values" => ["row" => $row],
        "table_last_update" => getTableLastUpdateTime($table),
    ]);
}
select_row();
