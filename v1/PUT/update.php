<?php

declare(strict_types=1);

/**
 * update.php
 * Endpoint to update a record in a table
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function update(): void
{
    $params = request_data['parameters'] ?? [];

    if (empty($params['table'])) {
        http_response(400, ["error" => "Bad Request: 'table' parameter is required"]);
    }

    if (empty($params['values']) || !is_array($params['values'])) {
        http_response(400, ["error" => "Bad Request: 'values' must be a non-empty associative array"]);
    }

    $values_map = $params['values'];

    foreach (array_keys($values_map) as $key) {
        if (!is_string($key)) {
            http_response(400, ["error" => "Bad Request: 'values' must be an associative array"]);
        }
    }

    $table  = $params['table'];
    $keys   = array_keys($values_map);
    $values = array_values($values_map);
    $where  = $params['where'] ?? null;

    if (sqlUpdate($table, $keys, $values, $where)) {
        $events_path = getcwd() . '/' . request_method . '/events.php';
        if (file_exists($events_path)) {
            include_once $events_path;
        }

        http_response(200, [
            "values"            => [],
            "table_last_update" => getTableLastUpdateTime($table),
            "affected_rows"     => dbconn->affected_rows,
        ]);
    } else {
        http_response(400, ["error" => "Update failed"]);
    }
}
update();
