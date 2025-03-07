<?php
function webhooks_folders()
{
    if (!file_exists('webhooks')) {
        mkdir('webhooks', 0777, true);
    }
    if (!file_exists('webhooks/data')) {
        mkdir('webhooks/data', 0777, true);
    }
    if (!file_exists('webhooks/logs')) {
        mkdir('webhooks/logs', 0777, true);
    }
    if (!file_exists('webhooks/queue')) {
        mkdir('webhooks/queue', 0777, true);
    }
}
function webhooks_add_hook($url, $method, $header_key, $user, $pass, $events = [])
{
    $unique_id = uniqid();
    $hook_file = 'webhooks/data/' . $unique_id . '.json';
    $hook = [
        'url' => $url,
        'method' => $method,
        'header_key' => $header_key,
        'user' => $user,
        'pass' => $pass,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'active' => false,
        'tested' => false,
        'events' => $events
    ];
    file_put_contents($hook_file, json_encode($hook, JSON_PRETTY_PRINT));
    return $unique_id;
}
function webhooks_edit_hook($unique_id, $url, $method, $header_key, $user, $pass, $events = [])
{
    $hook_file = 'webhooks/data/' . $unique_id . '.json';
    $hook = json_decode(file_get_contents($hook_file), true);
    $hook['url'] = $url;
    $hook['method'] = $method;
    $hook['header_key'] = $header_key;
    $hook['user'] = $user;
    $hook['pass'] = $pass;
    $hook['updated_at'] = date('Y-m-d H:i:s');
    $hook['events'] = $events;
    file_put_contents($hook_file, json_encode($hook, JSON_PRETTY_PRINT));
}
function webhooks_delete_hook($unique_id)
{
    $hook_file = 'webhooks/data/' . $unique_id . '.json';
    unlink($hook_file);
}
function webhooks_get_hooks()
{
    $hooks = [];
    $hook_files = glob('webhooks/data/*.json');
    foreach ($hook_files as $hook_file) {
        $hook = json_decode(file_get_contents($hook_file), true);
        $hook['unique_id'] = basename($hook_file, '.json');
        $hooks[] = $hook;
    }
    return $hooks;
}
function webhooks_get_hook($unique_id)
{
    $hook_file = 'webhooks/data/' . $unique_id . '.json';
    return json_decode(file_get_contents($hook_file), true);
}
