<?php
$meta = require __DIR__.'/meta_messaging.php';
$wa = require __DIR__.'/whatsapp_cart.php';
return array_merge($wa, [
    'activated_at' => ($meta['orders']['activated_at'] ?? null),
    'debounce_seconds' => 10,
    'product_mappings' => [], 'product_names' => [],
]);
