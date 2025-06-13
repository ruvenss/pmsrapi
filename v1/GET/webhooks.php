<?php
function webhooks()
{
    $GET_hooks = webhooks_get_hooks("GET");
    $POST_hooks = webhooks_get_hooks("POST");
    $PUT_hooks = webhooks_get_hooks("PUT");
    $DELETE_hooks = webhooks_get_hooks("DELETE");
    http_response(200, [
        "GET_hooks" => $GET_hooks,
        "POST_hooks" => $POST_hooks,
        "PUT_hooks" => $PUT_hooks,
        "DELETE_hooks" => $DELETE_hooks
    ]);
}
webhooks();
