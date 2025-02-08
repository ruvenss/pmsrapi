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
    if ($new_id == null) {
        http_response(500, ["error" => "Error inserting data"]);
    }
    define("new_id", $new_id);
    include_once getcwd() . '/' . request_method . '/events.php';
    http_response(200, ["data" => ["new_id" => new_id]]);
}
insert();