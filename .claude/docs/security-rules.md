# Security Rules

## Files creation, files data, and files append

All files created, or appended must be outside this directory, they can go to the parent directory or any other system directory. This will allow to lock this code only for reading, allowing only the CI/CD to rewrite it

## SQL Injection

Standard anti-sql injection should be applied in all functions that have access to the database

## Passwords, Secrets, Tokens, Services, and configurations

All configuration, secrets, tokens, hosts addresses should be in the configuration json file specified in the file /v1/config.php

Allowed functions, and endpoint methods are also declared in the .json configuration file

This is an example of the configuration json file:

```
{
    "db": {
        "host": "localhost",
        "port": 3306,
        "name": "example-db",
        "username": "example-user",
        "password": "example-password"
    },
    "http": {
        "port": 8001,
        "host": "0.0.0.0"
    },
    "allowed_actions": [
        "read",
        "update",
        "create",
        "delete"
    ],
    "allowed_functions": {
        "PUT": [
            "update"
        ],
        "POST": [
            "insert",
            "uploadfile"
        ],
        "GET": [
            "info",
            "select",
            "select_row",
            "select_plot",
            "select_rows",
            "count_rows",
            "sum_rows"
        ],
        "DELETE": [
            "delete"
        ]
    },
    "ms_server_token": "example-token",
    "ms_logserver_url": "ws://my-logserver",
    "env": "dev",
    "local_log": {
        "path": "/var/log/my-microservice.log",
        "level": "error"
    }
}
```

## PHP should never be exposed

No end point should end by .php

