<?php

/**
 * update.php
 * Endpoint to update a record in a table
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function update()
{
    $request_data = $_REQUEST; // Assuming request data is in $_REQUEST
    if (!isset($request_data['parameters']['table']) || !isset($request_data['parameters']['values'])) {
        http_response(400, ["error" => "Missing table or values"]);
        return;
    }
    if (!isset($request_data['parameters']['where'])) {
        http_response(400, ["error" => "Missing where clause"]);
        return;
    }
    if (!is_array($request_data['parameters']['values'])) {
        http_response(400, ["error" => "Values and Columns must be an array"]);
        return;
    }
    if (count($request_data['parameters']['values']) == 0) {
        http_response(400, ["error" => "Values and Columns must not be empty"]);
        return;
    }

    $values_keys = $request_data['parameters']['values'];
    $keys = [];
    $values = [];
    foreach ($values_keys as $key => $value) {
        if (!is_string($key)) {
            http_response(400, ["error" => "Values and Columns must be an associative array"]);
            return;
        }
        $keys[] = $key;
        $values[] = $value;
    }

    if (sqlUpdate($request_data['parameters']['table'], $keys, $values, $request_data['parameters']['where'])) {
        $affectedRows = dbconn->affected_rows;
        $last_update = getTableLastUpdateTime($request_data['parameters']['table']);
        include_once getcwd() . '/' . request_method . '/events.php';
        http_response(200, ["values" => [], "table_last_update" => $last_update, "affected_rows" => $affectedRows]);
    } else {
        http_response(400, ["error" => "Update failed"]);
    }
}
update();
