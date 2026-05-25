<?php

declare(strict_types=1);

/**
 * info.php
 * Endpoint to get information about the microservice
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function info(): void
{
    $jsonFilePath = dirname(dirname(__DIR__)) . '/manifest.json';

    if (file_exists($jsonFilePath) && is_readable($jsonFilePath)) {
        $manifest    = json_decode(file_get_contents($jsonFilePath), true);
        $ms_name     = $manifest['product'] ?? "Microservice";
        $ms_version  = $manifest['version'] ?? "unknown";
    } else {
        $ms_name    = "Microservice";
        $ms_version = "unknown";
    }

    $os = 'unknown';
    if (is_readable('/etc/os-release')) {
        $os_release = file_get_contents('/etc/os-release');
        if (preg_match('/^PRETTY_NAME="(.+)"$/m', $os_release, $matches)) {
            $os = $matches[1];
        }
    }

    $rawIps = exec("hostname -I");
    $ips    = array_values(array_filter(explode(" ", $rawIps !== false ? $rawIps : "")));

    http_response(200, [
        "platform"         => $ms_name,
        "platform_version" => $ms_version,
        "program"          => ms_name,
        "version"          => ms_version,
        "status"           => "running",
        "description"      => ms_description,
        "author"           => ms_author,
        "author_email"     => ms_author_email,
        "author_website"   => ms_author_website,
        "license"          => ms_license,
        "documentation"    => ms_documentation,
        "last_updated"     => ms_last_updated,
        "github_repo"      => ms_github_repo,
        "local_time"       => date("Y-m-d H:i:s"),
        "os"               => $os,
        "ips"              => $ips,
        "db"               => isset(ms_secrets['db']),
        "environment"      => ms_environment,
        "logserver"        => ms_logserver,
    ]);
}
info();
