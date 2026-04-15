<?php
/*
Upload binary files to a local path.
@author Ruvenss G. Wilches <ruvenss@gmail.com>
*/
function uploadfile_bin()
{
    $file_name = request_data['parameters']['file_name'] ?? null;
    $file_path = request_data['parameters']['local_path'] ?? null;

    if ($file_name == null || $file_path == null) {
        http_response(400, ["error" => "Missing parameters, file_name and local_path are required"]);
    }

    $raw_body = file_get_contents('php://input');
    if ($raw_body === false || strlen($raw_body) === 0) {
        http_response(400, ["error" => "Request body is empty or could not be read"]);
    }

    if (!file_exists($file_path)) {
        if (!mkdir($file_path, 0777, true)) {
            http_response(500, ["error" => "Error creating local directory"]);
        }
    }

    $full_path = rtrim($file_path, '/') . '/' . $file_name;

    if (file_put_contents($full_path, $raw_body) !== false) {
        http_response(200, ["values" => ["message" => "File uploaded successfully", "path" => $full_path, "size" => filesize($full_path)]]);
    } else {
        http_response(500, ["error" => "Error writing file to disk"]);
    }
}
uploadfile_bin();
