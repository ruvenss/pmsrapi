<?php
function webhook_update()
{
    $unique_id = request_data['parameters']['unique_id'] ?? '';
    if (empty($unique_id)) {
        http_response(400, ["error" => "Missing unique ID"]);
    }
    $url = request_data['parameters']['url'] ?? '';
    $method = request_data['parameters']['method'] ?? 'POST';
    $authorization = request_data['parameters']['authorization'] ?? '';
    $headers = request_data['parameters']['headers'] ?? ["Content-Type" => "application/json", "Accept" => "application/json", "powered-by" => "NIZU.io MicroService"];
    $events = request_data['parameters']['events'] ?? ["insert", "update", "delete"];
    $user = request_data['parameters']['user'] ?? '';
    $pass  = request_data['parameters']['pass'] ?? '';
    $table = request_data['parameters']['table'] ?? '';
    $data_map = request_data['parameters']['data_map'] ?? [];
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
    $hook_file = 'webhooks/data/' . $method . '/' . $unique_id . '.json';
    if (file_exists($hook_file)) {
        $current_hook = json_decode(file_get_contents($hook_file), true);
        $created_date = $current_hook['created_at'];
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
            'created_at' => $created_date,
            'updated_at' => date('Y-m-d H:i:s'),
            'active' => true,
            'tested' => false,
            'created_by' => ms_name,
            'data_map' => $data_map
        ];
        file_put_contents($hook_file, json_encode($hook, JSON_PRETTY_PRINT));
    } else {
        // If the webhook file does not exist, return an error
        http_response(400, ["error" => "Webhook doesn't exists"]);
    }

    http_response(200, [
        "message" => "Webhook updated successfully",
        "unique_id" => $unique_id,
        "hook_file" => $hook_file,
        "hook" => $hook
    ]);
}
webhook_update();
