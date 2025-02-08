<?php
function select_plot()
{
    $fields = explode(",", request_data['parameters']['fields']);
    $field_x = trim($fields[0]);
    $field_y = trim($fields[1]);
    $plot = sqlSelectPlot(request_data['parameters']['table'], $field_x, $field_y, request_data['parameters']['where'], request_data['parameters']['orderby'], request_data['parameters']['limit'], request_data['parameters']['groupby']);
    http_response(200, ["data" => ["rows" => $plot]]);
}
select_plot();