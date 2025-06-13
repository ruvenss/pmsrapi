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
function after_insert($new_row)
{
    if (defined("new_id")) {
        // Check if there are any events to trigger in this table
        $webhooks = webhooks_get_hooks("POST");
        if (count($webhooks) > 0) {
            $table = request_data['parameters']['table'] ?? '';
            $primary_key = getPrimaryKey($table);
            $new_row = sqlSelectRow($table, "*", "`$primary_key` = " . new_id);
            foreach ($webhooks as $hook) {
                if ($hook['table'] == $table) {
                    // Prepare the data to send
                    $data = [
                        'event' => 'insert',
                        'table' => $table,
                        'new_row' => $new_row,
                        'new_id' => new_id
                    ];
                    // Send the data to the webhook URL
                    sendWebhook($hook, $data, "POST");
                }
            }
        }
    }
}
