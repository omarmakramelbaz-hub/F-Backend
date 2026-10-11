<?php
$meta = require __DIR__.'/meta_messaging.php';
$wa = require __DIR__.'/whatsapp_orders.php';
$settings = $meta['orders'];
// Reuse the existing extraction credentials only. Activation, cutoffs and mode remain channel-specific.
return array_merge([
    'enabled' => ($wa['enabled'] ?? false), 'mode' => 'review',
    'model' => ($wa['model'] ?? null), 'api_key' => ($wa['api_key'] ?? null),
    'automation_actor_id' => null, 'allowed_branch_ids' => [],
    'activation_message_id' => null, 'activation_event_id' => null,
], $settings);
