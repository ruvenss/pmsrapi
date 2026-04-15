# Yet another PHP Microservice REST API with Webhooks

This is a Microservice intended to separate functions into independent MicroServices in form of REST API and Webhooks, to achive faster deployment via CI/CD and reduce testing.

The premises of the project are: lightweight, fast, parallel execution

## Architecture

Language: PHP 8.3
Database: MariaDB
Backend Framework: None
OS: Ubuntu 24.04

## Environments

Isoleted and Exposed

## Features

- Auto updatable
- Auto install cronjobs
- Allow 3 diffrent type of debug: Development, Testing, Production

## REST API Conventions for CRUD Operations

- GET: Select
- POST: Insert
- PUT: Update
- DELETE: Delete

## REST API Conventions for anything else

- GET: Custom made functions to obtain information
- POST: Upload files, inject large information

