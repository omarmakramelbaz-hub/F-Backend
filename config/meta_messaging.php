<?php

// Server-private configuration; never commit account tokens or alter the Business Agent settings.
$path = (string) env('META_MESSAGING_CONFIG_PATH', storage_path('app/private/meta-messaging.json'));
$saved = [];
if (is_file($path) && !is_link($path) && filesize($path) <= 65536) {
    $data = json_decode((string) file_get_contents($path), true);
    if (is_array($data)) $saved = $data;
}
return [
    'app_secret' => (string) ($saved['app_secret'] ?? ''),
    'verify_token' => (string) ($saved['verify_token'] ?? ''),
    'accounts' => is_array($saved['accounts'] ?? null) ? $saved['accounts'] : [],
    'api_version' => preg_match('/\Av[0-9]{1,2}\.0\z/', $saved['api_version'] ?? '') ? $saved['api_version'] : 'v25.0',
    'orders' => is_array($saved['orders'] ?? null) ? $saved['orders'] : [],
];
