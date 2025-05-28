<?php
function webhooks_folders()
{
    if (!file_exists('webhooks')) {
        mkdir('webhooks', 0777, true);
    }
    if (!file_exists('webhooks/data')) {
        mkdir('webhooks/data', 0777, true);
        mkdir('webhooks/data/GET', 0777, true);
        mkdir('webhooks/data/POST', 0777, true);
        mkdir('webhooks/data/PUT', 0777, true);
        mkdir('webhooks/data/DELETE', 0777, true);
        touch('webhooks/data/GET/index.html');
        touch('webhooks/data/POST/index.html');
        touch('webhooks/data/PUT/index.html');
        touch('webhooks/data/DELETE/index.html');
        touch('webhooks/data/index.html');
    }
    if (!file_exists('webhooks/logs')) {
        mkdir('webhooks/logs', 0777, true);
    }
    if (!file_exists('webhooks/queue')) {
        mkdir('webhooks/queue', 0777, true);
    }
}
function webhooks_add_hook($url, $method, $header_key, $user, $pass, $events = []): string
{
    $unique_id = uniqid();
    $method = strtoupper($method);
    if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'])) {
        throw new Exception("Invalid HTTP method: $method");
    }
    $hook_file = 'webhooks/data/' . $method . '/' . $unique_id . '.json';
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
function webhooks_edit_hook($unique_id, $url, $method, $header_key, $user, $pass, $events = []): bool
{
    $method = strtoupper($method);
    if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'])) {
        throw new Exception("Invalid HTTP method: $method");
    }
    $hook_file = 'webhooks/data/' . $method . '/' . $unique_id . '.json';
    $hook = json_decode(file_get_contents($hook_file), true);
    $hook['url'] = $url;
    $hook['method'] = $method;
    $hook['header_key'] = $header_key;
    $hook['user'] = $user;
    $hook['pass'] = $pass;
    $hook['updated_at'] = date('Y-m-d H:i:s');
    $hook['events'] = $events;
    file_put_contents($hook_file, json_encode($hook, JSON_PRETTY_PRINT));
    return true;
}
function webhooks_delete_hook($unique_id, $method): bool
{
    $method = strtoupper($method);
    if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'])) {
        throw new Exception("Invalid HTTP method: $method");
    }
    $hook_file = 'webhooks/data/' . $method . '/' . $unique_id . '.json';
    $hook_file = 'webhooks/data/' . $unique_id . '.json';
    unlink($hook_file);
    return true;
}
function webhooks_get_hooks($method): array
{
    $hooks = [];
    $method = strtoupper($method);
    if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'])) {
        throw new Exception("Invalid HTTP method: $method");
    }
    $hook_files = glob('webhooks/data/' . $method . '*.json');
    foreach ($hook_files as $hook_file) {
        $hook = json_decode(file_get_contents($hook_file), true);
        $hook['unique_id'] = basename($hook_file, '.json');
        $hooks[] = $hook;
    }
    return $hooks;
}
function webhooks_get_hook($unique_id, $method): array
{
    $method = strtoupper($method);
    $hook_file = 'webhooks/data/' . $method . '/' . $unique_id . '.json';
    if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'])) {
        throw new Exception("Invalid HTTP method: $method");
    }
    if (!file_exists($hook_file)) {
        throw new Exception("Webhook not found: $unique_id");
    }
    return json_decode(file_get_contents($hook_file), true);
}
function sendWebhook($unique_id, $hook, $data, $method)
{
    $method = strtoupper($method);
    if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'])) {
        throw new Exception("sendWebhook Invalid HTTP method: $method");
    }
}
