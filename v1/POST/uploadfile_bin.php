<?php

declare(strict_types=1);

/**
 * uploadfile_bin.php
 * Endpoint to upload binary files to a local path
 * DO NOT MODIFY THIS FILE.
 * @author Ruvenss G. Wilches <ruvenss@gmail.com>
 */
function uploadfile_bin(): void
{
    $params = request_data['parameters'] ?? [];

    if (empty($params['file_name'])) {
        http_response(400, ["error" => "Bad Request: 'file_name' parameter is required"]);
    }

    if (empty($params['local_path'])) {
        http_response(400, ["error" => "Bad Request: 'local_path' parameter is required"]);
    }

    // Strip directory components to prevent path traversal
    $safe_name  = basename($params['file_name']);
    if ($safe_name === '' || $safe_name === '.') {
        http_response(400, ["error" => "Invalid file name"]);
    }

    $local_path = $params['local_path'];

    $raw_body = file_get_contents('php://input');
    if ($raw_body === false || strlen($raw_body) === 0) {
        http_response(400, ["error" => "Request body is empty or could not be read"]);
    }

    $max_size = 50 * 1024 * 1024; // 50MB
    if (strlen($raw_body) > $max_size) {
        http_response(400, ["error" => "File exceeds maximum allowed size of 50MB"]);
    }

    if (!file_exists($local_path)) {
        if (!mkdir($local_path, 0755, true)) {
            http_response(500, ["error" => "Error creating local directory"]);
        }
    }

    $full_path = rtrim($local_path, '/') . '/' . $safe_name;

    if (file_put_contents($full_path, $raw_body) !== false) {
        http_response(200, [
            "values" => [
                "message"   => "File uploaded successfully",
                "file_name" => $safe_name,
                "size"      => filesize($full_path),
            ],
        ]);
    } else {
        http_response(500, ["error" => "Error writing file to disk"]);
    }
}
uploadfile_bin();
