<?php
include_once 'config.php';
if (file_exists(config_path)) {
    define("ms_secrets", json_decode(file_get_contents(config_path), true));
    define("ms_logserver_token", ms_secrets['logserver_token']);
} else {
    define("ms_secrets", []);
    http_response(500, ["error" => "Configuration file not found"]);
}
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authorization = $_SERVER['HTTP_AUTHORIZATION'];
    $token = explode(" ", $authorization);
    if (count($token) == 2) {
        $token = $token[1];
        if (in_array($token, ms_secrets)) {
            // Authorized
            if ($_SERVER['REQUEST_METHOD'] == "GET") {
                http_response(200, ["message" => "Welcome to " . ms_name . " API", "version" => ms_version]);
            } else {
                http_response(405, ["error" => "Method Not Allowed"]);
            }
        } else {
            http_response(401, ["error" => "Unauthorized"]);
        }
    } else {
        http_response(401, ["error" => "Unauthorized"]);
    }
} else {
    http_response(401, ["error" => "Unauthorized"]);
}
function http_response($http_code = 200, $data = null)
{
    $response = ms_restful_responses;
    $http_headers = ms_http_headers;
    header("HTTP/1.1 $http_code $response[$http_code]");
    foreach ($http_headers as $key => $value) {
        header("$key: $value");
    }
    header("MicroService: " . ms_name);
    header("MicroService-Version: " . ms_version);
    if ($data) {
        echo json_encode($data);
    }
    die();
}
/**
 * Log an event to the log server
 * @param string $changes = {"field":"value","field2":"value2"} or null
 * @param string $action = 'created','updated','deleted','view','bitbucket_notification_received','github_notification_received'/.. etc up to you
 * @param string $log_type = 'task','project','user','team','notification','bitbucket','github','log' etc up to you
 * @param int $created_by User ID
 * @param string $log_type_title = if is not defined the MicroService Name will be used
 * @param int $log_type_id = if is not defined 1 will be used
 * @param string $log_for = if is not defined the MicroService Name will be used (max 30 characters)
 * @param int $log_for_id = if is not defined 0 will be used
 * @param string $log_for2 = if is not defined the MicroService Name will be used
 * @param int $log_for_id2 = if is not defined 0 will be used  (max 30 characters)
 * @param int $deleted = 0 or 1 if the record was deleted
 * @return string
 */
function log_event($changes = null, $action = "updated", $log_type = "task", $created_by = 1, $log_type_title = ms_name, $log_type_id = 1, $log_for = ms_name, $log_for_id = 0, $log_for2 = ms_name, $log_for_id2 = null, $deleted = 0)
{
    $logserver = ms_logserver;
    $token = ms_logserver_token;
    $data = ["microservice" => ms_name, "version" => ms_version, "action" => $action];
    $options = [
        'http' => [
            'header' => "Content-type: application/json\r\n" .
                "Authorization: Bearer $token\r\n",
            'method' => 'POST',
            'content' => json_encode($data)
        ]
    ];
    $context = stream_context_create($options);
    $result = file_get_contents($logserver, false, $context);
    return $result;
}