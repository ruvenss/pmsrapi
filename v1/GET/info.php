<?php
/**
 * info.php
 * Endpoint to get information about the microservice
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
info();
function info()
{
    $parentDirectory = dirname(dirname(__DIR__));
    // Path to the JSON file in the parent directory
    $jsonFilePath = $parentDirectory . '/manifest.json';
    if (file_exists($jsonFilePath) && is_readable($jsonFilePath)) {
        $manifest = json_decode(file_get_contents($jsonFilePath), true);
        $ms_name = isset($manifest['product']) ? $manifest['product'] : "Microservice";
        $ms_version = isset($manifest['version']) ? $manifest['version'] : 'unknown';
    } else {
        $ms_name = "Microservice";
        $ms_version = "unknown";
    }
    $local_time = date("Y-m-d H:i:s");
    $os = 'unknown';
    if (is_readable('/etc/os-release')) {
        $os_release = file_get_contents('/etc/os-release');
        if (preg_match('/^PRETTY_NAME="(.+)"$/m', $os_release, $matches)) {
            $os = $matches[1];
        }
    }
    $ips = exec("hostname -I");
    $ips_array = explode(" ", $ips);
    http_response(200, ["platform" => $ms_name, "platform_version" => $ms_version, "program" => ms_name, "version" => ms_version, "status" => "running", "description" => ms_description, "author" => ms_author, "author_email" => ms_author_email, "author_website" => ms_author_website, "license" => ms_license, "documentation" => ms_documentation, "last_updated" => ms_last_updated, "github_repo" => ms_github_repo, "local_time" => $local_time, "os" => $os, "ips" => $ips_array]);
}