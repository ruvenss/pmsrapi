<?php
function select_plot()
{
    $field_x = request_data['parameters']['fields'][0];
    $field_y = request_data['parameters']['fields'][1];
    $plot = sqlSelectPlot(request_data['parameters']['table'], $field_x, $field_y, request_data['parameters']['where'], request_data['parameters']['orderby'], request_data['parameters']['limit']);
    http_response(200, ["data" => ["rows" => $plot]]);
}
select_plot();