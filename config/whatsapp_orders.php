<?php

// API credentials belong on the server. ChatGPT subscriptions do not provide these keys.
$branchIds = array_values(array_unique(array_filter(array_map('trim',
    explode(',', (string) env('WHATSAPP_ORDERS_ALLOWED_BRANCH_IDS', ''))
), static fn ($id) => preg_match('/\A[1-9][0-9]{0,18}\z/', $id) === 1)));

return [
    'enabled' => env('WHATSAPP_ORDERS_ENABLED', false),
    'mode' => env('WHATSAPP_ORDERS_MODE', 'review'),
    'model' => env('WHATSAPP_ORDERS_MODEL'),
    'api_key' => env('WHATSAPP_ORDERS_API_KEY'),
    'automation_actor_id' => env('WHATSAPP_ORDERS_AUTOMATION_ACTOR_ID'),
    'activation_message_id' => env('WHATSAPP_ORDERS_ACTIVATION_MESSAGE_ID'),
    'activation_event_id' => env('WHATSAPP_ORDERS_ACTIVATION_EVENT_ID'),
    'allowed_branch_ids' => $branchIds,
    // The provider endpoint and transport limits are fixed in code.
];
