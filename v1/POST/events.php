<?php

/**
 * events.php
 * Trigger events after a successful insert or update
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
// Cascade events
if (file_exists(getcwd() . '/' . request_method . '/my_events.php')) {
    include_once getcwd() . '/' . request_method . '/my_events.php';
}
function after_insert()
{
    if (defined("new_id")) {
        // Check if there are any events to trigger in this table

    }
}
