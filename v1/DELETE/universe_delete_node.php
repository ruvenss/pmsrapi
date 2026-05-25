<?php

declare(strict_types=1);

/**
 * universe_delete_node.php
 * Endpoint to delete a node from the universe
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function universe_delete_node(): void
{
    $params = request_data['parameters'] ?? [];

    if (empty($params['name'])) {
        http_response(400, ["error" => "Bad Request: 'name' parameter is required"]);
    }

    if (empty(ms_secrets['universe'])) {
        http_response(400, ["error" => "There are no nodes in the universe"]);
    }

    $name = $params['name'];

    foreach (ms_secrets['universe'] as $key => $node) {
        if ($node['name'] !== $name) {
            continue;
        }

        $new_ms_secrets = ms_secrets;
        unset($new_ms_secrets['universe'][$key]);
        $new_ms_secrets['universe'] = array_values($new_ms_secrets['universe']);

        $tmp_path = tempnam(sys_get_temp_dir(), 'ms_secrets_');
        file_put_contents($tmp_path, json_encode($new_ms_secrets, JSON_PRETTY_PRINT));

        if (copy($tmp_path, config_path)) {
            unlink($tmp_path);
        } else {
            unlink($tmp_path);
            http_response(500, ["error" => "Failed to persist configuration"]);
        }

        http_response(200, [
            "values"   => ["message" => "Node deleted successfully"],
            "manifest" => $new_ms_secrets,
        ]);
    }

    http_response(400, ["error" => "Node not found"]);
}
universe_delete_node();
