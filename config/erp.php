<?php

return [
    // Enable only after the ERP migration and opening-data review.
    'enabled' => (bool) env('ERP_ENABLED', false),
    'legacy_owner_id' => (int) env('ERP_OWNER_USER_ID', 1),
    'timezone' => 'Africa/Cairo',
];
