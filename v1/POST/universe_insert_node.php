<?php

declare(strict_types=1);

/**
 * universe_insert_node.php
 * Endpoint to insert a new node into the universe manifest
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function universe_insert_node(): void
{
    $manifest_path = getcwd() . '/general/manifest.php';
    if (file_exists($manifest_path)) {
        include_once $manifest_path;
    }

    $params = request_data['parameters'] ?? [];

    foreach (['name', 'ip', 'port', 'token'] as $required) {
        if (empty($params[$required])) {
            http_response(400, ["error" => "Bad Request: '{$required}' parameter is required"]);
        }
    }

    $name  = $params['name'];
    $ip    = $params['ip'];
    $port  = $params['port'];
    $token = $params['token'];
    $type  = $params['type'] ?? 'crud';
    $ssl   = filter_var($params['ssl'] ?? false, FILTER_VALIDATE_BOOLEAN);

    foreach (ms_secrets['universe'] ?? [] as $node) {
        if ($node['name'] === $name) {
            http_response(409, ["error" => "Node '{$name}' already exists"]);
        }
        if ($node['ip'] === $ip && (string) $node['port'] === (string) $port) {
            http_response(409, ["error" => "Another node is already using this IP and port"]);
        }
    }

    $new_ms_secrets               = ms_secrets;
    $new_ms_secrets['universe'][] = [
        'name'  => $name,
        'type'  => $type,
        'ip'    => $ip,
        'port'  => $port,
        'token' => $token,
        'ssl'   => $ssl,
    ];

    $tmp_path = tempnam(sys_get_temp_dir(), 'ms_secrets_');
    file_put_contents($tmp_path, json_encode($new_ms_secrets, JSON_PRETTY_PRINT));

    if (copy($tmp_path, config_path)) {
        unlink($tmp_path);
    } else {
        unlink($tmp_path);
        http_response(500, ["error" => "Failed to persist configuration"]);
    }

    http_response(200, [
        "values"   => ["message" => "Node inserted successfully"],
        "manifest" => $new_ms_secrets,
    ]);
}
universe_insert_node();