<?php

return [
    // Inquiry reads Paymob's authoritative transaction; it never initiates a charge.
    'api_key' => env('PAYMOB_API_KEY'),
    'integrations' => [
        'visa' => env('PAYMOB_CARD_INTEGRATION_ID'),
        'v_cash' => env('PAYMOB_MOBILE_WALLET_INTEGRATION_ID'),
    ],
    'live' => true,
];
