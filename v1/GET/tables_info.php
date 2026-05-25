<?php

declare(strict_types=1);

/**
 * tables_info.php
 * Endpoint to get the list of tables in the database
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function tables_info(): void
{
    http_response(200, [
        "values" => ["tables" => sqlTables() ?? []],
    ]);
}
tables_info();