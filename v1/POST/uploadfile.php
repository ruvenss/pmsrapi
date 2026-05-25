<?php

declare(strict_types=1);

/**
 * uploadfile.php
 * Endpoint to upload a file to a local path using base64 encoding
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function uploadfile(): void
{
    $params  = request_data['parameters'] ?? [];
    $payload = request_data['payload']    ?? [];

    if (empty($params['file_name'])) {
        http_response(400, ["error" => "Bad Request: 'file_name' parameter is required"]);
    }

    if (empty($params['local_path'])) {
        http_response(400, ["error" => "Bad Request: 'local_path' parameter is required"]);
    }

    if (empty($payload['base64'])) {
        http_response(400, ["error" => "Bad Request: 'base64' payload is required"]);
    }

    // Strip directory components to prevent path traversal
    $safe_name  = basename($params['file_name']);
    if ($safe_name === '' || $safe_name === '.') {
        http_response(400, ["error" => "Invalid file name"]);
    }

    $local_path = $params['local_path'];

    // Strict mode rejects non-base64 characters
    $decoded = base64_decode($payload['base64'], true);
    if ($decoded === false) {
        http_response(400, ["error" => "Invalid base64 string"]);
    }

    $max_size = 50 * 1024 * 1024; // 50MB
    if (strlen($decoded) > $max_size) {
        http_response(400, ["error" => "File exceeds maximum allowed size of 50MB"]);
    }

    if (!file_exists($local_path)) {
        if (!mkdir($local_path, 0755, true)) {
            http_response(500, ["error" => "Error creating local directory"]);
        }
    }

    $full_path = rtrim($local_path, '/') . '/' . $safe_name;

    if (file_put_contents($full_path, $decoded) !== false) {
        $events_path = getcwd() . '/' . request_method . '/events.php';
        if (file_exists($events_path)) {
            include_once $events_path;
        }

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
uploadfile();
