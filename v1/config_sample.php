<?php
// Define the database connection and private tokens out of your source code
define("config_path", "../../pmsrapi.json");
// Define your Microservice details
define("ms_name", "mms_name");
define("ms_version", "mms_version");
define("ms_description", "mms_description");
define("ms_author", "mms_author");
define("ms_author_email", "mms_author_email");
define("ms_author_website", "mms_author_website");
define("ms_license", "mms_license");
define("ms_documentation", "mms_documentation");
define("ms_last_updated", "mms_last_updated");
define("ms_github_repo", "mms_github_repo");
// Define the responses for the RESTful API, you can add more responses if you need
define("ms_restful_responses", ["200" => "OK", "201" => "Created", "204" => "No Content", "400" => "Bad Request", "401" => "Unauthorized", "403" => "Forbidden", "404" => "Not Found", "405" => "Method Not Allowed", "409" => "Conflict", "410" => "Gone", "500" => "Internal Server Error"]);
define("ms_http_headers", ["Content-Type" => "application/json", "Access-Control-Allow-Origin" => "*", "Access-Control-Allow-Methods" => "GET, POST, PUT, DELETE, OPTIONS", "Access-Control-Allow-Headers" => "Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With"]);
define("ms_logserver", "mms_logserver");
