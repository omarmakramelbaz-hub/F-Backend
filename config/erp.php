<?php

return [
    // Enable only after the ERP migration and opening-data review.
    'enabled' => (bool) env('ERP_ENABLED', false),
    'legacy_owner_id' => (int) env('ERP_OWNER_USER_ID', 1),
    // Existing legacy admin accounts that should open the ERP app-orders workspace
    // without gaining account administration or other ERP modules.
    'legacy_order_admin_emails' => array_values(array_filter(array_map(
        fn ($email) => mb_strtolower(trim($email)),
        explode(',', (string) env('ERP_ORDER_ADMIN_EMAILS', 'omarmakramelbazz@gmail.com'))
    ))),
    'timezone' => 'Africa/Cairo',
];
