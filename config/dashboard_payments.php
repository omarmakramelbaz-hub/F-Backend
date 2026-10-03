<?php

return [
    // Read the existing legacy Paymob credentials; this does not enable or
    // change a payment method, a gateway URL, or settlement behavior.
    'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
    'integrations' => [
        'online' => env('PAYMOB_CARD_INTEGRATION_ID'),
        'v_cash' => env('PAYMOB_MOBILE_WALLET_INTEGRATION_ID'),
    ],
];
