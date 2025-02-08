<?php
/**
 * select_row.php
 * Endpoint to select a single row from a table
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function select_row()
{
    $row = sqlSelectRow(request_data['parameters']['table'], request_data['parameters']['fields'], request_data['parameters']['where'], request_data['parameters']['orderby']);
    http_response(200, ["values" => ["row" => $row], "table_last_update" => getTableLastUpdateTime(request_data['parameters']['table'])]);
}
select_row();