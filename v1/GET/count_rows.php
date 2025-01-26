<?php
/**
 * This file is used to count the number of rows in a table.
 * The table name and where clause are passed as parameters.
 * The response is the total number of rows in the table.
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function count_rows()
{
    $count = sqlCount(request_data['parameters']['table'], request_data['parameters']['where']);
    http_response(200, ["data" => ["total" => $count]]);
}
count_rows();