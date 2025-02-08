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
    http_response(200, ["values" => ["rows" => $rows], "table_last_update" => getTableLastUpdateTime(request_data['parameters']['table'])]);
}
select_rows();