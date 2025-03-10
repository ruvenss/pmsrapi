<?php
/**
 * universe_insert_node.php
 * Endpoint to insert a new node into the universe manifest
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function universe_insert_node()
{
    include_once getcwd() . '/general/manifest.php';
    $new_ms_secrets = ms_secrets;
    if (!isset(request_data['parameters']['name']) || !isset(request_data['parameters']['type']) || !isset(request_data['parameters']['ip']) || !isset(request_data['parameters']['port']) || !isset(request_data['parameters']['token'])) {
        http_response(400, ["error" => "Missing parameters"]);
    }
    if (!isset(ms_secrets['universe'])) {
        $new_ms_secrets['universe'] = [];
    } else {
        // Check if node already exists
        foreach (ms_secrets['universe'] as $world) {
            if ($world['name'] == request_data['parameters']['name']) {
                http_response(400, ["error" => "Node already exists"]);
            }
            if ($world['ip'] == request_data['parameters']['ip'] && $world['port'] == request_data['parameters']['port']) {
                http_response(400, ["error" => "Another node is already using this IP and Port"]);
            }
        }
    }
    $world = [
        'name' => request_data['parameters']['name'],
        'type' => request_data['parameters']['type'] ?? 'crud',
        'ip' => request_data['parameters']['ip'],
        'port' => request_data['parameters']['port'],
        'token' => request_data['parameters']['token'],
        'ssl' => request_data['parameters']['ssl'] ?? false
    ];
    $new_ms_secrets['universe'][] = $world;
    $new_json_ms_secrets = json_encode($new_ms_secrets, JSON_PRETTY_PRINT);
    file_put_contents("/tmp/ms_secrets.json", $new_json_ms_secrets);
    // copy the new manifest to the secrets folder
    copy("/tmp/ms_secrets.json", config_path);
    http_response(200, ["values" => ["message" => "Node inserted successfully"], "manifest" => $new_ms_secrets]);
}
universe_insert_node();