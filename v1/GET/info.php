<?php
info();
function info()
{
    http_response(200, ["info" => ms_name, "version" => ms_version, "status" => "running", "description" => ms_description, "author" => ms_author, "author_email" => ms_author_email, "author_website" => ms_author_website, "license" => ms_license, "documentation" => ms_documentation, "last_updated" => ms_last_updated, "github_repo" => ms_github_repo]);
}