<?php

// Credentials are reused; activation is a separate, dynamic Owner setting.
$existing = require __DIR__ . '/whatsapp_orders.php';
return [
    'model' => env('META_COMMENT_AI_MODEL', $existing['model'] ?? null),
    'api_key' => env('META_COMMENT_AI_API_KEY', $existing['api_key'] ?? null),
];
