<?php
/**
 * This file is used to sum the values of a field in a table.
 * The table name, field name and where clause are passed as parameters.
 * The response is the total sum of the field in the table.
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function sum_rows()
{
    $sum = sqlSum(request_data['parameters']['table'], request_data['parameters']['field'], request_data['parameters']['where']);
    http_response(200, ["data" => ["total" => $sum]]);
}
sum_rows();