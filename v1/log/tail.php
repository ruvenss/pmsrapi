<?php
/*
CLI script to tail a log file in real-time to NIZU DEVOPS Monitoring
@author Ruvenss G. Wilches <ruvenss@gmail.com>
@license MIT
Usage: php tail.php
*/

declare(strict_types=1);
// Only allow execution from CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden: This script can only be run from the console.');
}
define("config_file", getcwd() . "/../config.php");
if (!file_exists(config_file)) {
    echo "Configuration file not found.";
    exit(1);
}
include_once config_file;
if (!defined("config_path") || !file_exists(config_path)) {
    echo "Secret configuration file not found at " . config_path . "\n";
    exit(1);
}
define("config", json_decode(file_get_contents(config_path), true) ?? []);
if (isset(config['nizu']) && isset(config['nizu']['token']) && isset(config['nizu']['api_url'])) {
    define("NIZU_API_URL", config['nizu']['api_url']);
    define("NIZU_TOKEN", config['nizu']['token']);
}

/**
 * Tail a log file and push lines into MySQL using mysqli.
 * Handles log rotation (inode change) and batches inserts.
 */

const LOG_FILE = '/home/errors/dev.nizuadmin.log';
const BATCH_SIZE = 100;
const BATCH_INTERVAL = 1.0; // seconds
const SLEEP_EMPTY = 200000; // microseconds

while (!feof(STDIN)) {
    $line = fgets(STDIN);
    if ($line === false) {
        usleep(100_000);
        continue;
    }
    $line = rtrim($line, "\r\n");
    $stmt->bind_param('s', $line);
    try {
        $stmt->execute();
    } catch (\Throwable $th) {
        continue;
    }
}
function openLogFile(string $path): array
{
    $fp = @fopen($path, 'r');
    if (!$fp) {
        throw new RuntimeException("Cannot open file: {$path}");
    }
    fseek($fp, 0, SEEK_END);
    $stat = fstat($fp);
    $inode = $stat['ino'] ?? null;
    return [$fp, $inode];
}
function nizu_cloud_rest_api(string $method = "GET", string $endpoint = "", array $data = []): array
{
    $url = rtrim(NIZU_API_URL, '/') . '/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . NIZU_TOKEN,
        'Content-Type: application/json'
    ]);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response = curl_exec($ch);
    if ($response === false) {
        throw new RuntimeException('cURL error: ' . curl_error($ch));
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $httpCode, 'body' => json_decode($response, true)];
}
function pushLogsToNizu(array $logs): void
{
    if (empty($logs)) {
        return;
    }
    try {
        $response = nizu_cloud_rest_api('POST', 'logs/ingest', ['logs' => $logs]);
        if ($response['status'] !== 200) {
            error_log("Failed to push logs to NIZU: " . json_encode($response));
        }
    } catch (RuntimeException $e) {
        error_log("Error pushing logs to NIZU: " . $e->getMessage());
    }
}
