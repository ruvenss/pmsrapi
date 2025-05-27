<?php
function downloadfile()
{
    $file_name = request_data['parameters']['file_name'] ?? null;
    $file_path = request_data['parameters']['local_path'] ?? null;

    if ($file_name == null || $file_path == null) {
        http_response(400, ["error" => "Missing parameters, file_name and local_path are required"]);
    }

    $full_path = $file_path . '/' . $file_name;

    if (!file_exists($full_path)) {
        http_response(404, ["error" => "File not found"]);
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($full_path) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($full_path));

    readfile($full_path);
    exit;
}
downloadfile();
