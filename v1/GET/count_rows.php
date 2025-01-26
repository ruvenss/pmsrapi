<?php
function count_rows()
{
    $count = sqlCount(request_data['parameters']['table'], request_data['parameters']['where']);
    http_response(200, ["data" => ["total" => $count]]);
}
count_rows();