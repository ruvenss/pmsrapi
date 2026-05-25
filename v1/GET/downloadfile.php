<?php

declare(strict_types=1);

/**
 * downloadfile.php
 * Endpoint to download a file from the server
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function downloadfile(): void
{
    $file_name  = request_data['parameters']['file_name']  ?? null;
    $local_path = request_data['parameters']['local_path'] ?? null;

    if ($file_name === null || $local_path === null) {
        http_response(400, ["error" => "Missing parameters: 'file_name' and 'local_path' are required"]);
    }

    // Strip directory components from the filename to prevent path traversal
    $safe_name = basename($file_name);
    if ($safe_name === '' || $safe_name === '.') {
        http_response(400, ["error" => "Invalid file name"]);
    }

    $full_path = rtrim($local_path, '/') . '/' . $safe_name;

    // Resolve the real path and confirm the file is within the declared directory
    $resolved = realpath($full_path);
    $base_dir = realpath($local_path);

    if ($resolved === false || $base_dir === false || !str_starts_with($resolved, $base_dir . DIRECTORY_SEPARATOR)) {
        http_response(404, ["error" => "File not found"]);
    }

    if (!is_readable($resolved)) {
        http_response(403, ["error" => "File not readable"]);
    }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($resolved) ?: 'application/octet-stream';

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $safe_name . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($resolved));

    readfile($resolved);
    exit;
}
downloadfile();
