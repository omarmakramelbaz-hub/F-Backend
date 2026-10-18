<?php

return [
    'verify_token' => (string) env('WHATSAPP_VERIFY_TOKEN', ''),
    'app_secret' => (string) env('WHATSAPP_APP_SECRET', ''),
    'allowed_account_ids' => array_values(array_filter(array_map(
        'trim', explode(',', (string) env('WHATSAPP_ALLOWED_ACCOUNT_IDS', ''))
    ), static function ($id) {
        return preg_match('/\A[0-9]+\z/', $id) === 1;
    })),
];
