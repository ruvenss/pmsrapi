<?php

declare(strict_types=1);

/**
 * delete.php
 * Endpoint to delete record(s) in a table
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function delete(): void
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

    if (sqlDelete($table, $where)) {
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
        http_response(400, ["error" => "Delete failed"]);
    }
}
delete();