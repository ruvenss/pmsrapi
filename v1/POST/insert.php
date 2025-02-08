<?php
function insert()
{
    if (!isset(request_data['parameters']['table']) || !isset(request_data['parameters']['values'])) {
        http_response(400, ["error" => "Missing parameters"]);
    }
    if (!is_array(request_data['parameters']['values'])) {
        http_response(400, ["error" => "Values and Columns must be an array"]);
    }
    if (count(request_data['parameters']['values']) == 0) {
        http_response(400, ["error" => "Values and Columns must not be empty"]);
    }
    $values_keys = request_data['parameters']['values'];
    foreach ($values_keys as $key => $value) {
        if (!is_string($key)) {
            http_response(400, ["error" => "Values and Columns must be an associative array"]);
        }
        $keys[] = $key;
        $values[] = $value;
    }
    $new_id = sqlInsert(request_data['parameters']['table'], $keys, $values);
    define("new_id", $new_id);
    if ($new_id > 0) {
        $primary_key = getPrimaryKey(request_data['parameters']['table']);
        $new_row = sqlSelectRow(request_data['parameters']['table'], "*", "`$primary_key` = $new_id");
    } else {
        $new_row = null;
    }
    $last_update = getTableLastUpdateTime(request_data['parameters']['table']);
    include_once getcwd() . '/' . request_method . '/events.php';
    http_response(200, ["values" => ["new_id" => new_id], "table_last_update" => $last_update, "new_row" => $new_row]);
}
insert();