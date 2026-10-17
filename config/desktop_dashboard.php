<?php
return [
    'enabled' => env('DESKTOP_DASHBOARD_ENABLED', false),
    // Never infer local mode from a browser header or a request parameter.
    'local' => env('DESKTOP_DASHBOARD_LOCAL', false),
    'device_id' => env('DESKTOP_DASHBOARD_DEVICE_ID'),
    'protocol' => 1,
];
