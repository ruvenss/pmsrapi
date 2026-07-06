<?php

declare(strict_types=1);

/**
 * hive-sync.php — DEVELOPMENT tool.
 *
 * Pulls this worker's cluster map from the Hive and BAKES it into the worker's
 * secret config (`function_map` + `universe`), so the worker can then run
 * standalone in production without ever contacting the Hive again.
 *
 *   php v2/hive-sync.php
 *
 * The Hive location is read from the secret config: either a top-level "hive"
 * block { ip, port, token, ssl } or a universe node named "hive".
 *
 * DO NOT MODIFY (core).
 */

use Pmsrapi\V2\Core\Config;
use Pmsrapi\V2\Core\Container;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Container $container */
$container = require __DIR__ . '/bootstrap.php';
$config = $container->get(Config::class);

if ($config->isProduction()) {
    fwrite(STDERR, "⚠ Refusing to sync from the Hive in a 'prod' environment. Run this in development, then deploy the baked config.\n");
    exit(2);
}

// Locate the Hive.
$hive = $config->secret('hive');
if (!is_array($hive)) {
    foreach ((array) $config->secret('universe', []) as $node) {
        if (is_array($node) && ($node['name'] ?? '') === 'hive') {
            $hive = $node;
            break;
        }
    }
}
if (!is_array($hive) || empty($hive['ip']) || empty($hive['port'])) {
    fwrite(STDERR, "No Hive configured. Add a \"hive\": { \"ip\", \"port\", \"token\", \"ssl\" } block, or a universe node named 'hive'.\n");
    exit(1);
}

$service = $config->name();
$scheme = ($hive['ssl'] ?? true) ? 'https' : 'http';
$url = $scheme . '://' . $hive['ip'] . ':' . $hive['port'] . '/v2/hive/export/' . rawurlencode($service);

echo "Syncing '{$service}' from Hive → {$url}\n";

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . (string) ($hive['token'] ?? '')],
]);
$response = curl_exec($ch);
$error = curl_error($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($error !== '' || !is_string($response) || !json_validate($response)) {
    fwrite(STDERR, "Hive request failed: {$error}\n");
    exit(1);
}

$data = json_decode($response, true)['data'] ?? null;
if ($code >= 400 || !is_array($data)) {
    fwrite(STDERR, "Hive returned HTTP {$code}.\n");
    exit(1);
}

// Load, mutate, and atomically rewrite the secret config.
$path = (string) $config->public('secrets_path');
$raw = is_readable($path) ? file_get_contents($path) : false;
$secret = is_string($raw) && json_validate($raw) ? json_decode($raw, true) : null;
if (!is_array($secret)) {
    fwrite(STDERR, "Cannot read secret config at {$path}\n");
    exit(1);
}

$secret['function_map'] = $data['function_map'] ?? [];

// Merge universe nodes by name (incoming wins).
$byName = [];
foreach ((array) ($secret['universe'] ?? []) as $node) {
    if (is_array($node) && isset($node['name'])) {
        $byName[(string) $node['name']] = $node;
    }
}
foreach ((array) ($data['universe'] ?? []) as $node) {
    if (is_array($node) && isset($node['name'])) {
        $byName[(string) $node['name']] = $node;
    }
}
$secret['universe'] = array_values($byName);

$tmp = tempnam(dirname($path), '.hive');
if ($tmp === false || file_put_contents($tmp, json_encode($secret, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
    fwrite(STDERR, "Failed to write temp config.\n");
    exit(1);
}
rename($tmp, $path);

$fnCount = count((array) $secret['function_map']);
echo "✅ Baked {$fnCount} remote function(s) + " . count($secret['universe']) . " node(s) into {$path}\n";

$collisions = $data['collisions'] ?? [];
if (is_array($collisions) && $collisions !== []) {
    $names = implode(', ', array_map(static fn(array $c): string => (string) $c['function'], $collisions));
    fwrite(STDERR, "⚠ Hive reports " . count($collisions) . " function collision(s): {$names}\n");
    fwrite(STDERR, "  Resolve these (a function must be owned by exactly one service) before shipping.\n");
}

echo "Done — this worker can now run standalone.\n";
