<?php

return [
    // Enable only after the ERP migration and opening-data review.
    'enabled' => (bool) env('ERP_ENABLED', false),
    // Production uses the existing dashboard session/accounts. Enable this only
    // for the isolated ERP development/trial environment.
    'standalone_auth' => (bool) env('ERP_STANDALONE_AUTH', false),
    'legacy_owner_id' => (int) env('ERP_OWNER_USER_ID', 1),
    // Existing legacy admin accounts that should open the ERP app-orders workspace
    // without gaining account administration or other ERP modules.
    'administrative_admin_emails' => array_values(array_filter(array_map(
        fn ($email) => mb_strtolower(trim($email)),
        explode(',', (string) env('ERP_ADMIN_EMAILS', 'omarmakramelbazz@gmail.com'))
    ))),
    // Backward-compatible alias while dashboard links move to the unified account model.
    'legacy_order_admin_emails' => array_values(array_filter(array_map(
        fn ($email) => mb_strtolower(trim($email)),
        explode(',', (string) env('ERP_ADMIN_EMAILS', 'omarmakramelbazz@gmail.com'))
    ))),
    'timezone' => 'Africa/Cairo',
];
