<?php
/**
 * select_rows.php
 * Endpoint to select multiple rows from a table
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function select_rows()
{
    $rows = sqlSelectRows(request_data['parameters']['table'], request_data['parameters']['fields'], request_data['parameters']['where'], request_data['parameters']['orderby'], request_data['parameters']['limit']);
    http_response(200, ["data" => ["rows" => $rows]]);
}
select_rows();