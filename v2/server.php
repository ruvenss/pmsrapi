<?php

declare(strict_types=1);

/**
 * Router script for the PHP built-in server during local development.
 *
 *   php -S 0.0.0.0:8000 v2/server.php
 *
 * It serves real static files as-is and forwards everything else to the
 * v2 front controller, so REST paths like /v2/users/42 resolve correctly
 * without Apache/nginx rewrites. In production use the .htaccess / nginx
 * rules documented in v2/README.md instead of this script.
 *
 * DO NOT MODIFY (core).
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$file = __DIR__ . '/..' . $uri;

// Let the built-in server serve existing static assets directly.
if ($uri !== '/' && is_file($file) && !str_ends_with($uri, '.php')) {
    return false;
}

require __DIR__ . '/index.php';
