<?php

/**
 * This file is part of the Micro Service REST API
 * DO NOT ADD YOUR CODE HERE, THIS FILE WILL BE AUTOMATICALLY UPDATED AND YOUR CODE WILL BE REMOVED
 * @author Ruvenss G. Wilches
 * @version 1.0.0
 * @license MIT
 * @package DB PMRSAPI
 * @subject Database Connection, Configuration, and Initialization
 */
if (!defined("ms_secrets")) {
    http_response(500, ["error" => "Internal Server Error"]);
}
if (!defined("dbconn")) {
    if (isset(ms_secrets['db']) && isset(ms_secrets['db']['host'])) {
        $mysqli = new mysqli(ms_secrets['db']['host'], ms_secrets['db']['username'], ms_secrets['db']['password'], ms_secrets['db']['name'], ms_secrets['db']['port']);
        if (!$mysqli) {
            if (ms_secrets['local_log']['level'] == 'errors') {
                sqlLog('dbconn', print_r(ms_secrets['db'], true), 'Error: ' . $mysqli->connect_error);
            }
            http_response(500, ["error" => "Internal Server Error"]);
            exit();
        }
        define("dbconn", $mysqli);
    } else {
        http_response(500, ["error" => "Database configuration not found"]);
        exit();
    }
}
/**
 * Function to insert a record into a table.
 *
 * @param string $table The name of the table.
 * @param array $fields The fields to insert.
 * @param array $values The values to insert.
 * @param string|null $onduplicate Optional ON DUPLICATE KEY UPDATE clause.
 * @return int|null Returns the inserted ID or null on failure.
 */
function sqlInsert($table, $fields = array(), $values = array(), ?string $onduplicate = null): ?int
{
    if (defined("dbconn")) {
        if (strlen($table) > 0 && sizeof($fields) > 0 && sizeof($values) == sizeof($fields)) {
            $querylog = array();
            $sqlquery = "INSERT INTO $table(`" . implode("`,`", $fields) . "`) VALUES(";
            for ($i = 0; $i < sizeof($values); $i++) {
                if (strtoupper($values[$i]) == "NOW()") {
                    $values[$i] = "NOW()";
                } elseif (strtoupper($values[$i]) == "NULL") {
                    $values[$i] = "NULL";
                } else {
                    $values[$i] = '"' . mysqli_real_escape_string(dbconn, $values[$i]) . '"';
                }
            }
            $sqlquery .= implode(",", $values);
            $sqlquery .= ")";
            if ($onduplicate != null) {
                $sqlquery .= " ON DUPLICATE KEY UPDATE " . $onduplicate;
            }
            try {
                mysqli_query(dbconn, $sqlquery);
                if (mysqli_insert_id(dbconn)) {
                    // Log the event
                    if (ms_secrets['local_log']['level'] == 'info' || ms_secrets['local_log']['level'] == 'errors')
                        sqlLog('sqlInsert', $sqlquery, mysqli_insert_id(dbconn));
                    return (mysqli_insert_id(dbconn));
                } else {
                    if (ms_secrets['local_log']['level'] == 'info' || ms_secrets['local_log']['level'] == 'errors')
                        sqlLog('sqlInsert', $sqlquery, 'null');
                    return (null);
                }
            } catch (Exception $e) {
                if (ms_secrets['local_log']['level'] == 'errors') {
                    sqlLog('sqlInsert', $sqlquery, 'Error');
                }
                return (null);
            }
        } else {
            if (ms_secrets['local_log']['level'] == 'errors') {
                sqlLog('sqlInsert', $table . " fields=" . sizeof($fields) . " values=" . sizeof($values), 'Error');
            }
            return (null);
        }
    } else {
        http_response(500, ["error" => "Internal DB Server Error"]);
    }
}
/**
 * Function to delete records from a table.
 *
 * @param string $table The name of the table.
 * @param string $where The WHERE clause for deletion.
 * @return bool Returns true on success, false on failure.
 */
function sqlDelete($table, $where)
{
    if (defined("dbconn")) {
        if (strlen($table) > 0 && strlen($where) > 0) {
            $sqlquery = "DELETE FROM `$table` WHERE ($where)";
            if (dbconn->query($sqlquery) === TRUE) {
                if (ms_secrets['local_log']['level'] == 'info' || ms_secrets['local_log']['level'] == 'errors') {
                    sqlLog('sqlDelete', $sqlquery, dbconn->affected_rows . " rows deleted");
                }
                return true;
            } else {
                if (ms_secrets['local_log']['level'] == 'errors' || ms_secrets['local_log']['level'] == 'errors') {
                    sqlLog('sqlDelete', $sqlquery, "Error");
                }
                return false;
            }
        } else {
            if (ms_secrets['local_log']['level'] == 'errors' || ms_secrets['local_log']['level'] == 'errors') {
                sqlLog('sqlDelete', $table . " where=$where ", 'Error');
            }
        }
    } else {
        http_response(500, ["error" => "Internal Server Error"]);
    }
}
/**
 * Function to update records in a table.
 *
 * @param string $table The name of the table.
 * @param array $fields The fields to update.
 * @param array $values The values to set for the fields.
 * @param string|null $where Optional WHERE clause for the update.
 * @return bool Returns true on success, false on failure.
 */
function sqlUpdate($table, $fields = [], $values = [], $where = null)
{
    if (defined("dbconn")) {
        if (strlen($table) > 0 && sizeof($values) == sizeof($fields)) {
            $sqlquery = "UPDATE `$table` SET ";
            $updateFields = [];
            for ($i = 0; $i < sizeof($values); $i++) {
                if (strtoupper($values[$i]) == "NOW()") {
                    $updateFields[] = "`" . $fields[$i] . "`=NOW()";
                } elseif (strtoupper($values[$i]) == "NULL") {
                    $updateFields[] = "`" . $fields[$i] . "`=NULL";
                } else {
                    $updateFields[] = "`" . $fields[$i] . "`='" . mysqli_real_escape_string(dbconn, $values[$i]) . "'";
                }
            }
            $sqlquery .= implode(",", $updateFields);
            if ($where != null) {
                $sqlquery .= " WHERE (" . $where . ")";
            }
            if (dbconn->query($sqlquery) === TRUE) {
                $affectedRows = dbconn->affected_rows;
                if (ms_secrets['local_log']['level'] == 'info' || ms_secrets['local_log']['level'] == 'errors') {
                    sqlLog('sqlUpdate', $sqlquery, $affectedRows . " rows updated");
                }
                return $affectedRows > 0;
            } else {
                if (ms_secrets['local_log']['level'] == 'errors') {
                    sqlLog('sqlUpdate', $sqlquery, "Error");
                }
                return false;
            }
        } else {
            if (ms_secrets['local_log']['level'] == 'errors') {
                sqlLog('sqlUpdate', $table . " fields=" . sizeof($fields) . " values=" . sizeof($values) . " where=$where ", 'Error');
            }
            return false;
        }
    } else {
        http_response(500, ["error" => "Internal Server Error"]);
    }
}
/**
 * Function to select a single field from a table.
 *
 * @param string $table The name of the table.
 * @param string $field The field to select.
 * @param string $where The WHERE clause for selection.
 * @param string $orderby Optional ORDER BY clause.
 * @param string $limit Optional LIMIT clause.
 * @return string|null Returns the selected field value or null on failure.
 */
function sqlSelect($table, $field, $where, $orderby = "", $limit = "")
{
    if (defined("dbconn")) {
        if (!empty($table) && !empty($field)) {
            $sqlquery = "SELECT `$field` FROM `$table`";
            if (!empty($where)) {
                $sqlquery .= " WHERE ($where)";
            }
            if (!empty($orderby)) {
                $sqlquery .= " ORDER BY `$orderby`";
            }
            if (!empty($limit)) {
                $sqlquery .= " LIMIT $limit";
            }
            $result = dbconn->query($sqlquery);
            if ($result && $row = $result->fetch_assoc()) {
                return mb_convert_encoding($row[$field], 'UTF-8', mb_detect_encoding($row[$field]));
            } else {
                error_log("sqlSelect empty result : $sqlquery", 0);
            }
        }
    } else {
        http_response(500, ["error" => "Internal Server Error"]);
    }
}
/**
 * Function to select a single row from a table.
 *
 * @param string $table The name of the table.
 * @param string $fields The fields to select.
 * @param string $where The WHERE clause for selection.
 * @param string $orderby Optional ORDER BY clause.
 * @return array Returns the selected row as an associative array or an empty array on failure.
 */
function sqlSelectRow($table, $fields, $where, $orderby = "")
{
    if (defined("dbconn")) {
        if (!empty($table) && !empty($fields)) {
            $sqlquery = "SELECT $fields FROM `$table`";
            if (!empty($where)) {
                $sqlquery .= " WHERE ($where)";
            }
            if (!empty($orderby)) {
                $sqlquery .= " ORDER BY `$orderby`";
            }
            $sqlquery .= " LIMIT 1";
            $result = dbconn->query($sqlquery);
            if ($result && $row = $result->fetch_assoc()) {
                return $row;
            }
        }
        return [];
    } else {
        http_response(500, ["error" => "Internal Server Error"]);
    }
}
/**
 * Function to select multiple rows from a table.
 *
 * @param string $table The name of the table.
 * @param string $fields The fields to select.
 * @param string $where The WHERE clause for selection.
 * @param string $orderby Optional ORDER BY clause.
 * @param string $limit Optional LIMIT clause.
 * @return array Returns an array of selected rows as associative arrays or an empty array on failure.
 */
function sqlSelectRows($table, $fields, $where, $orderby = "", $limit = "")
{
    $rows = [];
    if (defined("dbconn")) {
        if (!empty($table) && !empty($fields)) {
            $sqlquery = "SELECT $fields FROM `$table`";
            if (!empty($where)) {
                $sqlquery .= " WHERE ($where)";
            }
            if (!empty($orderby)) {
                $sqlquery .= " ORDER BY $orderby";
            }
            if (!empty($limit)) {
                $sqlquery .= " LIMIT $limit";
            }
            if ($result = dbconn->query($sqlquery)) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
            }
        }
    }
    return $rows;
}
/**
 * Function to select data for plotting.
 *
 * @param string $table The name of the table.
 * @param string $field_x The field for the X-axis.
 * @param string $field_y The field for the Y-axis.
 * @param string $where Optional WHERE clause.
 * @param string $orderby Optional ORDER BY clause.
 * @param string $limit Optional LIMIT clause.
 * @param string $groupby Optional GROUP BY clause.
 * @return array Returns an associative array of X and Y values or null on failure.
 */
function sqlSelectPlot($table, $field_x, $field_y, $where = "", $orderby = "", $limit = "", $groupby = "")
{
    $rows = [];
    if (defined("dbconn")) {
        if (strlen($table) > 0 && strlen($field_x) > 0 && strlen($field_y) > 0) {
            $sqlquery = "SELECT `$field_x`,`$field_y` FROM `$table`";
            if (strlen($where) > 0) {
                $sqlquery .= " WHERE ($where)";
            }
            if (strlen($groupby) > 0) {
                $sqlquery .= " GROUP BY $groupby";
            }
            if (strlen($orderby) > 0) {
                $sqlquery .= " ORDER BY $orderby";
            }
            if (strlen($limit) > 0) {
                $sqlquery .= " LIMIT $limit";
            }
            $result = dbconn->query($sqlquery);
            if (!$result) {
                return (null);
            } else {
                while ($row = $result->fetch_assoc()) {
                    $rows[$row[$field_x]] = $row[$field_y];
                }
            }
        }
    }
    return ($rows);
}
function sqlCount($table, $where)
{
    if (defined("dbconn")) {
        if (strlen($table) > 0 && strlen($where) > 0) {
            $sqlquery = "SELECT count(*) AS TOTAL FROM `$table` WHERE $where";
            $result = dbconn->query($sqlquery);
            if (!$result) {
                return 0;
            }
            while ($row = $result->fetch_assoc()) {
                return $row['TOTAL'];
            }
        } else {
            return 0;
        }
    } else {
        return 0;
    }
}
function sqlSum($table, $field, $where)
{
    if (defined("dbconn")) {
        if (strlen($table) > 0 && strlen($where) > 0) {
            $sqlquery = "SELECT sum($field) AS TOTAL FROM $table WHERE $where";
            $result = dbconn->query($sqlquery);
            if (!$result) {
                return 0;
            }
            while ($row = $result->fetch_assoc()) {
                return $row['TOTAL'];
            }
        } else {
            return 0;
        }
    } else {
        return 0;
    }
}
function sqlTableInformationOf($table)
{
    $fields = [];
    if (defined("dbconn")) {
        $result = dbconn->query("DESC `$table`");
        while ($field = $result->fetch_assoc()) {
            array_push($fields, $field['Field']);
        }
    }
    return $fields;
}
function DuplicateMySQLRecord($table, $id_field, $id)
{
    if (defined("dbconn")) {
        // load the original record into an array
        $result = dbconn->query("SELECT * FROM {$table} WHERE {$id_field}={$id}");
        $original_record = $result->fetch_assoc();
        // insert the new record and get the new auto_increment id
        mysqli_query(dbconn, "INSERT INTO {$table} (`{$id_field}`) VALUES (NULL)");
        $newid = mysqli_insert_id(dbconn);
        // generate the query to update the new record with the previous values
        $query = "UPDATE {$table} SET ";
        foreach ($original_record as $key => $value) {
            if ($key != $id_field) {
                $query .= '`' . $key . '` = "' . str_replace('"', '\"', $value) . '", ';
            }
        }
        $query = substr($query, 0, strlen($query) - 2); # lop off the extra trailing comma
        $query .= " WHERE {$id_field}={$newid}";
        mysqli_query(dbconn, $query);
        // return the new id
        error_log("DuplicateMySQLRecord query:" . $query, 0);
        return $newid;
    } else {
        return 0;
    }
}
function sqlTableExist($table, $dbname)
{
    if (defined("dbconn")) {
        $query = "SELECT COUNT(TABLE_NAME) AS `EXIST` FROM information_schema.TABLES WHERE table_schema = '$dbname' AND TABLE_NAME = '$table';";
        $apidb = dbconn->query($query);
        while (($row = $apidb->fetch_assoc()) !== false) {
            if ($row['EXIST'] == "0" || $row['EXIST'] == "false") {
                return (false);
            } else {
                return (true);
            }
        }
    }
    return (false);
}
function sqlLog($action = 'sqlInsert', $query = null, $logresult = null)
{
    if (isset(ms_secrets['local_log']) && isset(ms_secrets['local_log']['path'])) {
        // Log the event locally
        $logline = "[" . date("Y-m-d H:i:s") . "]\t" . $action . "\t" . $query . "\t" . $logresult . PHP_EOL;
        file_put_contents(ms_secrets['local_log']['path'], $logline, FILE_APPEND);
    }
    return (true);
}
function getPrimaryKey($tableName)
{
    if (defined("dbconn")) {
        $sql = "SHOW KEYS FROM $tableName WHERE Key_name = 'PRIMARY'";
        $result = dbconn->query($sql);
        if ($result) {
            $row = $result->fetch_assoc();
            return $row['Column_name'];
        }
    }
    return null;
}
function sqlTables()
{
    if (defined("dbconn")) {
        $sql = "SELECT TABLE_NAME,UPDATE_TIME FROM information_schema.tables WHERE     TABLE_SCHEMA = '" . ms_secrets['db']['name'] . "';";
        $result = dbconn->query($sql);
        $tables = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $tables[] = ["table" => $row['TABLE_NAME'], "update" => $row['UPDATE_TIME'], "hash" => md5($row['UPDATE_TIME'] . $row['TABLE_NAME'])];
            }
        }
        return $tables;
    }
    return null;
}
/**
 * Get the last update time of a table.
 *
 * @param mysqli $conn       The MySQLi connection object.
 * @param string $dbName     The name of the database.
 * @param string $tableName  The name of the table.
 * @return string            The last update time or an error message.
 */
function getTableLastUpdateTime($tableName)
{
    // Query to fetch the last update time from information_schema.tables
    $sql = "
        SELECT UPDATE_TIME
        FROM information_schema.tables
        WHERE TABLE_SCHEMA = '" . ms_secrets['db']['name'] . "'
        AND TABLE_NAME = '$tableName'
    ";

    // Prepare the SQL statement
    if ($stmt = dbconn->prepare($sql)) {
        // Bind parameters (database name and table name)
        // Execute the query
        $stmt->execute();
        // Get the result
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        // Check if UPDATE_TIME is available
        if ($row && $row['UPDATE_TIME']) {
            return $row['UPDATE_TIME'];
        } else {
            return null;
        }
    } else {
        return null;
    }
}
function sqlCreateDatabase($dbname)
{
    if (defined("dbconn")) {
        $sql = "CREATE DATABASE IF NOT EXISTS $dbname CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
        if (dbconn->query($sql) === TRUE) {
            return true;
        } else {
            return false;
        }
    }
    return false;
}
