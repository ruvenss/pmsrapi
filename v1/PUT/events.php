<?php
/**
 * events.php
 * Trigger events after a successful insert or update
 * DO NOT MODIFY THIS FILE.
 * @author ruvenss <ruvenss@gmail.com>
 */
if (file_exists(getcwd() . '/' . request_method . '/my_events.php')) {
    include_once getcwd() . '/' . request_method . '/my_events.php';
}