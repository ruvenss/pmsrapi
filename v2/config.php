<?php

declare(strict_types=1);

/**
 * v2 PUBLIC config.
 *
 * Unlike v1 (which used define() constants), v2 returns a plain array so it
 * can be injected and tested. Secrets NEVER live here — DB credentials, the
 * server token, Redis auth, etc. stay in the JSON pointed to by 'secrets_path'
 * (the SAME file v1 uses, kept outside the web root).
 *
 * This file is committed-shape and safe to keep in git.
 */

return [
    // --- Microservice identity (mirror of v1 config.php) ---
    'ms_name' => 'weather',
    'ms_version' => '2.0.0',
    'ms_description' => 'Weather API',
    'ms_author' => 'John Doe',
    'ms_author_email' => 'joe@example.com',
    'ms_license' => 'MIT',
    'ms_documentation' => 'https://github.com/ruvenss/pmsrapi/wiki',
    'ms_github_repo' => 'https://github.com/ruvenss/pmsrapi/',

    // --- Where the secret config JSON lives (shared with v1) ---
    // Default: parent directory of the project root, named after the service.
    // Absolute paths are recommended in production.
    'secrets_path' => dirname(__DIR__, 2) . '/weather.json',

    // --- CORS / default response headers ---
    'headers' => [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
    ],

    // --- HTTP status reason phrases used by the Response envelope ---
    'reason_phrases' => [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ],
];
