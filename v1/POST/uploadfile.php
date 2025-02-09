<?php
function uploadfile()
{
    $file_name = request_data['parameters']['file_name'] ?? null;
    $file_base64 = request_data['payload']['base64'] ?? null;
    $file_path = request_data['parameters']['local_path'] ?? null;
    if ($file_name == null || $file_base64 == null || $file_path == null) {
        http_response(400, ["error" => "Missing parameters, file_name, base64 and local_path are required"]);
    }
    $decodedData = base64_decode($file_base64);
    if ($decodedData === false) {
        http_response(400, ["error" => "Invalid base64 string"]);
    }
    if (!file_exists($file_path)) {
        mkdir($file_path, 0777, true);
    }
    if (file_put_contents($file_path . '/' . $file_name, $decodedData)) {
        http_response(200, ["values" => ["message" => "File uploaded successfully"]]);
    } else {
        http_response(500, ["error" => "Error uploading file"]);
    }
}