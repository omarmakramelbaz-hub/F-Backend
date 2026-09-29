<?php

return [
    // Opt in explicitly after installing a Brevo API key on the server.
    'delivery' => env('PARTNER_MAIL_DELIVERY', 'smtp'),
    'brevo_api_key' => env('PARTNER_BREVO_API_KEY'),
    // Separate verified TLS SMTP transport, reusing the existing server credentials.
    'mailer' => env('PARTNER_MAIL_MAILER', 'partner_smtp'),
];
