<?php

// Cascade events
if (file_exists(getcwd() . '/' . request_method . '/my_events.php')) {
    include_once getcwd() . '/' . request_method . '/my_events.php';
}