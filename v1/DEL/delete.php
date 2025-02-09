<?php
/**
 * delete.php
 * Endpoint to delete record(s) in a table
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function delete()
{
    if (!isset(request_data['parameters']['table']) || !isset(request_data['parameters']['where'])) {
        http_response(400, ["error" => "Missing table or where"]);
    }
    if (!isset(request_data['parameters']['where'])) {
        http_response(400, ["error" => "Missing where "]);
    }
    if (sqlDelete(request_data['parameters']['table'], request_data['parameters']['where'])) {
        $affectedRows = dbconn->affected_rows;
        $last_update = getTableLastUpdateTime(request_data['parameters']['table']);
        include_once getcwd() . '/' . request_method . '/events.php';
        http_response(200, ["values" => [], "table_last_update" => $last_update, "affected_rows" => $affectedRows]);
    } else {
        http_response(400, ["error" => "Delete failed"]);
    }
}
update();