<?php
function select_rows()
{
    $rows = sqlSelectRows(request_data['parameters']['table'], request_data['parameters']['fields'], request_data['parameters']['where'], request_data['parameters']['orderby'], request_data['parameters']['limit']);
    http_response(200, ["data" => ["rows" => $rows]]);
}
select_rows();