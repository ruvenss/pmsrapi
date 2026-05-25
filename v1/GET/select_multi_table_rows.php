<?php

declare(strict_types=1);

/**
 * select_multi_table_rows.php
 * Endpoint to select rows from multiple tables and merge the results
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function select_multi_table_rows(): void
{
    $params = request_data['parameters'] ?? [];

    if (empty($params['tables']) || !is_array($params['tables'])) {
        http_response(400, ["error" => "Bad Request: 'tables' parameter is required and must be an array"]);
    }

    if (empty($params['fields'])) {
        http_response(400, ["error" => "Bad Request: 'fields' parameter is required"]);
    }

    $tables  = $params['tables'];
    $fields  = $params['fields'];
    $where   = $params['where']   ?? "";
    $orderby = $params['orderby'] ?? "";
    $limit   = $params['limit']   ?? "";

    $rows = [];
    foreach ($tables as $table) {
        $rows = array_merge($rows, sqlSelectRows($table, $fields, $where, $orderby, $limit));
    }

    http_response(200, ["values" => ["rows" => $rows]]);
}
select_multi_table_rows();
