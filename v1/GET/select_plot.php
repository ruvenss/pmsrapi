<?php
/**
 * select_plot.php
 * Endpoint to select a plot from a table
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function select_plot()
{
    $fields = explode(",", request_data['parameters']['fields']);
    $field_x = trim($fields[0]);
    $field_y = trim($fields[1]);
    $plot = sqlSelectPlot(request_data['parameters']['table'], $field_x, $field_y, request_data['parameters']['where'], request_data['parameters']['orderby'], request_data['parameters']['limit'], request_data['parameters']['groupby']);
    http_response(200, ["values" => ["rows" => $plot], "table_last_update" => getTableLastUpdateTime(request_data['parameters']['table'])]);
}
select_plot();