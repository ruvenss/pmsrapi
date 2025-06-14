<?php
function webhook_add()
{
    $url = request_data['parameters']['url'] ?? '';
    $method = request_data['parameters']['method'] ?? 'POST';
    $authorization = request_data['parameters']['authorization'] ?? '';
    $headers = request_data['parameters']['headers'] ?? ["Content-Type" => "application/json", "Accept" => "application/json", "powered-by" => "NIZU.io MicroService"];
    $events = request_data['parameters']['events'] ?? ["insert", "update", "delete"];
    $user = request_data['parameters']['user'] ?? '';
    $pass  = request_data['parameters']['pass'] ?? '';
    $table = request_data['parameters']['table'] ?? '';
    /* Body type can be 
        json, 
        form-data, 
        x-www-form-urlencoded, 
        raw, graphql, 
        or binary
    */
    $body_type = request_data['parameters']['body_type'] ?? 'json';
    if (empty($url)) {
        http_response(400, ["error" => "Missing URL"]);
    }
    if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'])) {
        http_response(400, ["error" => "Invalid HTTP method"]);
    }
    if (empty($events) || !is_array($events)) {
        http_response(400, ["error" => "Events must be an array"]);
    }
    // Write the webhook to a file
    $unique_id = md5($url . $method . json_encode($headers) . json_encode($events) . $user . $pass . $table . $authorization);
    $hook_file = 'webhooks/data/' . $method . '/' . $unique_id . '.json';
    $hook = [
        'url' => $url,
        'method' => $method,
        'authorization' => $authorization,
        'headers' => $headers,
        'events' => $events,
        'user' => $user,
        'pass' => $pass,
        'table' => $table,
        'body_type' => $body_type,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'active' => true,
        'tested' => false,
        'created_by' => ms_name
    ];
    if (!file_exists('webhooks/data/' . $method)) {
        mkdir('webhooks/data/' . $method, 0777, true);
    }
    file_put_contents($hook_file, json_encode($hook, JSON_PRETTY_PRINT));
    http_response(200, [
        "message" => "Webhook added successfully",
        "unique_id" => $unique_id,
        "hook_file" => $hook_file,
        "hook" => $hook
    ]);
}
webhook_add();
