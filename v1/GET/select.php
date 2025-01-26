<?php
function select()
{
    http_response(200, ["data" => [request_data['parameters']['field'] => sqlSelect(request_data['parameters']['table'], request_data['parameters']['field'], request_data['parameters']['where'])]]);
}
select();
