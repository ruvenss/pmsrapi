<?php

declare(strict_types=1);

/**
 * PMSRAPI v2 integration test suite. DEV/TEST ONLY.
 *
 * Runs inside the test container against a live server + real MariaDB + Redis,
 * so it exercises exactly the paths that can't be checked without them:
 * mysqli prepared/persistent CRUD, the Redis query cache + invalidation, rate
 * limiting, the debug capture pipeline (auto-arm on 5xx, query capture,
 * redaction), the Redis-backed hive registry + collision detection, and NDJSON
 * streaming.
 */

require __DIR__ . '/assert.php';

$container = require '/opt/pmsrapi/v2/bootstrap.php';

$cfg = json_decode((string) file_get_contents('/opt/weather.json'), true);
$TOKEN = $cfg['ms_server_token'];
$BASE = 'http://127.0.0.1:8080/v2';

/**
 * @return array{code:int, body:?array, raw:string}
 */
function http(string $method, string $path, ?array $body = null, ?string $token = null, array $extraHeaders = []): array
{
    global $BASE;
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer {$token}";
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    foreach ($extraHeaders as $h) {
        $headers[] = $h;
    }

    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => json_validate($raw) ? json_decode($raw, true) : null, 'raw' => $raw];
}

function stream_lines(string $path, string $token): array
{
    global $BASE;
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = trim((string) curl_exec($ch));
    curl_close($ch);
    return $raw === '' ? [] : explode("\n", $raw);
}

// Clean Redis slate so cache/rate/hive/debug assertions are deterministic.
$redis = new Redis();
$redis->connect($cfg['redis']['host'], (int) $cfg['redis']['port']);
$redis->flushDB();
note('flushed Redis');

// ── Health & info ────────────────────────────────────────────────────────────
section('Health & info (DB + Redis connectivity)');
$r = http('GET', '/health');
eq(200, $r['code'], 'GET /health = 200');
eq('up', $r['body']['data']['database'] ?? null, 'database probe = up');
eq('up', $r['body']['data']['redis'] ?? null, 'redis probe = up');
$r = http('GET', '/info', null, $TOKEN);
ok(($r['body']['data']['database']['connected'] ?? false) === true, 'info: MySQL connected (persistent p: conn)');

// ── CRUD ─────────────────────────────────────────────────────────────────────
section('CRUD over mysqli prepared statements + schema whitelist');
$email = 'dana' . random_int(1000, 999999) . '@example.com';
$r = http('POST', '/clients', ['name' => 'Dana', 'email' => $email, 'status' => 'active'], $TOKEN);
eq(201, $r['code'], 'POST /clients = 201');
$id = $r['body']['data']['id'] ?? 0;
ok($id > 0, "created id = {$id}");
$r = http('GET', "/clients/{$id}", null, $TOKEN);
eq('Dana', $r['body']['data']['name'] ?? null, 'GET /clients/{id} reads it back');
$r = http('GET', '/clients?status=active&per_page=100', null, $TOKEN);
ok(($r['body']['meta']['total'] ?? 0) >= 3, 'list returns pagination meta with rows');
$r = http('PUT', "/clients/{$id}", ['status' => 'inactive'], $TOKEN);
eq(200, $r['code'], 'PUT /clients/{id} = 200');
$r = http('GET', "/clients/{$id}", null, $TOKEN);
eq('inactive', $r['body']['data']['status'] ?? null, 'update persisted');
$r = http('DELETE', "/clients/{$id}", null, $TOKEN);
eq(204, $r['code'], 'DELETE /clients/{id} = 204');
eq(404, http('GET', "/clients/{$id}", null, $TOKEN)['code'], 'deleted row now 404');
eq(404, http('GET', '/clients/999999', null, $TOKEN)['code'], 'unknown id = 404');

// ── Query cache (Redis) ──────────────────────────────────────────────────────
section('Redis query cache + invalidation-on-write');
http('GET', '/clients?per_page=5', null, $TOKEN);
ok(count($redis->keys('*cache:clients:*')) > 0, 'a cache key was written for the read');
http('POST', '/clients', ['name' => 'Eve', 'email' => 'eve' . random_int(1000, 999999) . '@example.com'], $TOKEN);
ok(count($redis->keys('*cachever:clients')) > 0, 'write bumped the table cache version (invalidation)');

// ── Debug dashboard (the whole Redis capture pipeline) ───────────────────────
section('Debug dashboard: auto-arm on 5xx, query capture, redaction');
http('POST', '/_debug/disarm', null, $TOKEN);
http('DELETE', '/_debug/events', null, $TOKEN);
$st = http('GET', '/_debug/status', null, $TOKEN)['body']['data'] ?? [];
ok(($st['enabled'] ?? false) === true, 'debug enabled');
ok(($st['armed'] ?? true) === false, 'starts disarmed');

// Force a genuine 5xx: email is NOT NULL with no default -> INSERT fails.
$r = http('POST', '/clients', ['name' => 'NoEmail'], $TOKEN);
eq(500, $r['code'], 'insert missing required column -> 500 (DatabaseException)');

$st = http('GET', '/_debug/status', null, $TOKEN)['body']['data'] ?? [];
ok(($st['armed'] ?? false) === true, 'capture auto-armed by the 5xx');
ok(($st['events'] ?? 0) > 0, 'events were flushed to the Redis stream');

$events = http('GET', '/_debug/events?since=0&limit=300', null, $TOKEN)['body']['data']['events'] ?? [];
$failedQuery = $has500 = $redacted = false;
foreach ($events as $e) {
    if (($e['type'] ?? '') === 'query' && ($e['data']['rows'] ?? 0) === -1) {
        $failedQuery = true;
    }
    if (($e['type'] ?? '') === 'response' && ($e['data']['status'] ?? 0) === 500) {
        $has500 = true;
    }
    if (($e['type'] ?? '') === 'request') {
        $auth = (string) ($e['data']['headers']['authorization'] ?? '');
        if (str_contains($auth, 'REDACTED')) {
            $redacted = true;
        }
    }
}
ok($failedQuery, 'captured the failed INSERT (rows = -1)');
ok($has500, 'captured the 500 response');
ok($redacted, 'Authorization header was redacted in the capture');

// ── Rate limiting (Redis) — run before hive so its bucket is clean afterwards ─
section('Redis rate limiting');
foreach ($redis->keys('*rl:*') as $k) {
    $redis->del($k);
}
$max = (int) $cfg['rate_limit']['max'];
$got429 = false;
for ($i = 0; $i < $max + 8; $i++) {
    if (http('GET', '/info', null, $TOKEN)['code'] === 429) {
        $got429 = true;
        break;
    }
}
ok($got429, "429 returned after exceeding {$max} req/window");
foreach ($redis->keys('*rl:*') as $k) {
    $redis->del($k);
}

// ── Hive registry (Redis-backed) + collision detection ───────────────────────
section('Hive registry + duplicate-function detection');
$redis->del('ms:test:hive:services');
http('POST', '/hive/register', [
    'service' => 'clients', 'role' => 'worker', 'version' => '1',
    'functions' => ['get_client' => ['method' => 'GET', 'path' => '/clients/{id}']],
], $TOKEN);
http('POST', '/hive/register', [
    'service' => 'billing', 'role' => 'worker', 'version' => '1',
    'functions' => [
        'get_invoice' => ['method' => 'GET', 'path' => '/invoices/{id}'],
        'get_client' => ['method' => 'GET', 'path' => '/dup/{id}'],
    ],
], $TOKEN);
$map = http('GET', '/hive/map', null, $TOKEN)['body']['data'] ?? [];
eq(2, count($map['services'] ?? []), 'two services registered in the hive');
ok(in_array('get_client', array_column($map['collisions'] ?? [], 'function'), true), 'get_client collision detected');
$export = http('GET', '/hive/export/clients', null, $TOKEN)['body']['data'] ?? [];
ok(isset($export['function_map']['get_invoice']), "export gives 'clients' the other service's get_invoice");

// ── Capabilities ─────────────────────────────────────────────────────────────
section('Capabilities manifest');
$caps = http('GET', '/capabilities', null, $TOKEN)['body']['data'] ?? [];
ok(isset($caps['functions']['get_client']), 'GET /capabilities lists owned functions');
eq('hive_mind', $caps['role'] ?? null, 'role reported in manifest');

// ── NDJSON streaming (server + ServiceClient generator) ──────────────────────
section('NDJSON inter-service streaming');
$lines = stream_lines('/stream/_demo?count=5', $TOKEN);
eq(5, count($lines), 'GET /stream/_demo?count=5 -> 5 NDJSON lines');
ok(json_validate($lines[0] ?? ''), 'each line is valid JSON');
$lines = stream_lines('/stream/clients', $TOKEN);
ok(count($lines) >= 3, 'GET /stream/clients streams the seeded rows');

$sc = $container->get(Pmsrapi\V2\Cluster\ServiceClient::class);
$streamed = 0;
foreach ($sc->stream('demo', ['count' => 4]) as $row) {
    $streamed++;
}
eq(4, $streamed, 'ServiceClient::stream() consumed 4 records as a Generator over HTTP');

exit(summary());
