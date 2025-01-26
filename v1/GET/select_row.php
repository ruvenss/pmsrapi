<?php
function select_row()
{
    $row = sqlSelectRow(request_data['parameters']['table'], request_data['parameters']['fields'], request_data['parameters']['where'], request_data['parameters']['orderby']);
    http_response(200, ["data" => ["row" => $row]]);
}
select_row();