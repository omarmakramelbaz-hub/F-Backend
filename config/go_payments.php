<?php
return [
    'enabled' => env('GO_ORDER_PAYMENTS_ENABLED', true),
    'api_key' => env('PAYMOB_API_KEY'),
    'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
    'is_live' => env('GO_PAYMENTS_LIVE'),
    'methods' => [
        'card' => env('PAYMOB_CARD_INTEGRATION_ID'),
        'mobile_wallet' => env('PAYMOB_MOBILE_WALLET_INTEGRATION_ID'),
    ],
];
