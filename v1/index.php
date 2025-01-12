<?php
include_once 'config.php';
if (file_exists(config_path)) {
    define("ms_secrets", json_decode(file_get_contents(config_path), true));
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
