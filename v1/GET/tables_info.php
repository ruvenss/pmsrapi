<?php
/*
 * tables_info.php
 * Get the list of tables in the database
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
function tables_info()
{
    $tables = sqlTables();
    http_response(200, ["values" => ["tables" => $tables]]);
}
tables_info();