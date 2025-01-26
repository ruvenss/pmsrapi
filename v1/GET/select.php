<?php
/**
 * select.php
 * Endpoint to select a single field from a table
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function select()
{
    http_response(200, ["data" => [request_data['parameters']['field'] => sqlSelect(request_data['parameters']['table'], request_data['parameters']['field'], request_data['parameters']['where'])]]);
}
select();
