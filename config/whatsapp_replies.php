<?php

return [
    'enabled' => env('WHATSAPP_REPLIES_ENABLED', false),
    'access_token' => env('WHATSAPP_REPLIES_ACCESS_TOKEN'),
    // Phone, Graph version, endpoint and recording limits are fixed in code.
];
