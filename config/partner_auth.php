<?php

return [
    // Separate verified TLS SMTP transport, reusing the existing server credentials.
    'mailer' => env('PARTNER_MAIL_MAILER', 'partner_smtp'),
];
