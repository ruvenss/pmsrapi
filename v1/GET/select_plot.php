<?php

declare(strict_types=1);

/**
 * select_plot.php
 * Endpoint to select a plot from a table
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function select_plot(): void
{
    $params = request_data['parameters'] ?? [];

    if (empty($params['table'])) {
        http_response(400, ["error" => "Bad Request: 'table' parameter is required"]);
    }

    if (empty($params['fields'])) {
        http_response(400, ["error" => "Bad Request: 'fields' parameter is required"]);
    }

    $fields = array_map('trim', explode(',', $params['fields']));

    if (count($fields) < 2 || $fields[0] === '' || $fields[1] === '') {
        http_response(400, ["error" => "Bad Request: 'fields' must contain two comma-separated column names"]);
    }

    $table   = $params['table'];
    $field_x = $fields[0];
    $field_y = $fields[1];
    $where   = $params['where']   ?? "";
    $orderby = $params['orderby'] ?? "";
    $limit   = $params['limit']   ?? "";
    $groupby = $params['groupby'] ?? "";

    $plot = sqlSelectPlot($table, $field_x, $field_y, $where, $orderby, $limit, $groupby);

    http_response(200, [
        "values" => ["rows" => $plot],
        "table_last_update" => getTableLastUpdateTime($table),
    ]);
}
select_plot();