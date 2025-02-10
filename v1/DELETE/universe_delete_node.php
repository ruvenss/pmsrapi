<?php
/*
 * universe_delete_node.php
 * Endpoint to delete a node in the universe
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function universe_delete_node()
{
    $new_ms_secrets = ms_secrets;
    if (!isset(request_data['parameters']['name'])) {
        http_response(400, ["error" => "Missing node name"]);
    }
    if (!isset(ms_secrets['universe'])) {
        http_response(400, ["error" => "There are no nodes in the universe"]);
    }
    foreach (ms_secrets['universe'] as $world) {
        if ($world['name'] == request_data['parameters']['name']) {
            $key = array_search($world, ms_secrets['universe']);
            unset($new_ms_secrets['universe'][$key]);
            $new_json_ms_secrets = json_encode($new_ms_secrets, JSON_PRETTY_PRINT);
            file_put_contents("/tmp/ms_secrets.json", $new_json_ms_secrets);
            // copy the new manifest to the secrets folder
            if (file_exists("/tmp/ms_secrets.json")) {
                if (copy("/tmp/ms_secrets.json", config_path)) {
                    unlink("/tmp/ms_secrets.json");
                }
            }
            http_response(200, ["values" => ["message" => "Node deleted successfully"], "manifest" => $new_ms_secrets]);
        }
    }
    http_response(400, ["error" => "Node not found"]);
}
universe_delete_node();