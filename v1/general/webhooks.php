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
    error_log("Fetching webhooks for method: $method");
    $directory = 'webhooks/data/' . $method;
    $jsonFiles = [];
    if (file_exists($directory)) {
        $files = scandir($directory);

        foreach ($files as $file) {
            if (is_file($directory . DIRECTORY_SEPARATOR . $file) && str_ends_with($file, '.json')) {
                $unique_id = str_replace(".json", "", $file);
                //$jsonFiles[] = $unique_id;
                $jsonData = webhooks_get_hook($unique_id, $method);
                $hook = [
                    'unique_id' => $unique_id,
                    'url' => $jsonData['url'] ?? '',
                    'created_at' => $jsonData['created_at'] ?? '',
                    'active' => $jsonData['active'] ?? false,

                ];
                $jsonFiles[] = $hook;
            }
        }
    }
    return $jsonFiles;
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
function sendWebhook($hook, $data, $method)
{
    $method = strtoupper($method);
    $unique_id = $hook['unique_id'] ?? '';
    if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'])) {
        throw new Exception("sendWebhook Invalid HTTP method: $method");
    }
    $ch = curl_init();
    // Default options (can be overridden by $config)
    $defaultOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
    ];

    // Set URL
    if (!isset($hook['url'])) {
        throw new InvalidArgumentException("Missing required 'url' parameter in config array.");
    }
    $defaultOptions[CURLOPT_URL] = $hook['url'];

    if ($method === 'POST') {
        $defaultOptions[CURLOPT_POST] = true;
    } elseif (in_array($method, ['PUT', 'DELETE', 'PATCH'])) {
        $defaultOptions[CURLOPT_CUSTOMREQUEST] = $method;
    }

    // Optional headers
    if (isset($hook['headers']) && is_array($hook['headers'])) {
        $defaultOptions[CURLOPT_HTTPHEADER] = $hook['headers'];
    }

    // Optional body/data
    if (isset($hook['data'])) {
        $defaultOptions[CURLOPT_POSTFIELDS] = is_array($hook['data'])
            ? http_build_query($hook['data'])
            : $hook['data'];
    }
    // Merge user-defined curl options (advanced)
    if (isset($hook['curlopts']) && is_array($hook['curlopts'])) {
        $defaultOptions = $hook['curlopts'] + $defaultOptions;
    }
    curl_setopt_array($ch, $defaultOptions);
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'success'  => $error === '',
        'status'   => $status,
        'error'    => $error ?: null,
        'response' => $response,
    ];
    file_put_contents('webhooks/logs/' . $unique_id . '.log', date('Y-m-d H:i:s') . " - " . $method . " - " . $hook['url'] . " - " . json_encode($data) . " - " . json_encode($response) . "\n", FILE_APPEND);
}
