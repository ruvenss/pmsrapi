<?php
// Define the database connection and private tokens out of your source code
define("config_path", "../config.json");
// Define your Microservice details
define("ms_name", "weather");
define("ms_version", "1.0.0");
define("ms_description", "Weather API");
define("ms_author", "John Doe");
define("ms_author_email", "joe@gmail.com");
define("ms_author_website", "http://www.johndoe.com");
define("ms_license", "MIT");
define("ms_documentation", "http://www.johndoe.com/docs");
define("ms_last_updated", "2025-06-01");
define("ms_github_repo", "https://github.com/ruvenss/pmsrapi/");
// Define the responses for the RESTful API, you can add more responses if you need
define("ms_restful_responses", ["200" => "OK", "201" => "Created", "204" => "No Content", "400" => "Bad Request", "401" => "Unauthorized", "403" => "Forbidden", "404" => "Not Found", "405" => "Method Not Allowed", "409" => "Conflict", "410" => "Gone", "500" => "Internal Server Error"]);
define("ms_http_headers", ["Content-Type" => "application/json", "Access-Control-Allow-Origin" => "*", "Access-Control-Allow-Methods" => "GET, POST, PUT, DELETE, OPTIONS", "Access-Control-Allow-Headers" => "Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With"]);