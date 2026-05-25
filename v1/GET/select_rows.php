<?php

declare(strict_types=1);

/**
 * select_rows.php
 * Endpoint to select multiple rows from a table
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function select_rows(): void
{
    $params = request_data['parameters'] ?? [];

    if (empty($params['table'])) {
        http_response(400, ["error" => "Bad Request: 'table' parameter is required"]);
    }

    $table   = $params['table'];
    $fields  = $params['fields']  ?? "*";
    $where   = $params['where']   ?? "";
    $orderby = $params['orderby'] ?? "";
    $limit   = $params['limit']   ?? "";
    $groupby = $params['groupby'] ?? "";

    $rows = sqlSelectRows($table, $fields, $where, $orderby, $limit, $groupby);

    http_response(200, [
        "values" => ["rows" => $rows],
        "table_last_update" => getTableLastUpdateTime($table),
    ]);
}
select_rows();
