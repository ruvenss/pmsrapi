<?php
/**
 * select.php
 * Endpoint to select a single field from a table
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function select()
{
    $last_update = getTableLastUpdateTime(request_data['parameters']['table']);
    http_response(200, ["values" => [request_data['parameters']['field'] => sqlSelect(request_data['parameters']['table'], request_data['parameters']['field'], request_data['parameters']['where'])], "table_last_update" => $last_update]);
}
select();
