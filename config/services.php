<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have a
    | conventional file to locate this information.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'fcm' => [
        'project_id' => 'fasakhaninjatest',
    ],

    'maps' => [
        'tile_url' => env('MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        'browser_key' => env('MAP_BROWSER_KEY', env('MAP_KEY')),
    ],

    'google' => [
        'client_id' => env(
            'GOOGLE_CLIENT_ID',
            '224648167390-efdtr7rjcnept7eiml1d642sdn8n9ki7.apps.googleusercontent.com'
        ),
        'android_client_id' => env(
            'GOOGLE_ANDROID_CLIENT_ID',
            '224648167390-d4fc6jflatn61r66guemktla3itbsqum.apps.googleusercontent.com'
        ),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('APP_URL') . '/api/auth/social/google/callback',
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('APP_URL') . '/api/auth/social/facebook/callback',
    ],

    'meta_conversions' => [
        'enabled' => env('META_CONVERSIONS_ENABLED', false),
        'dataset_id' => env('META_DATASET_ID'),
        'access_token' => env('META_ACCESS_TOKEN'),
        'api_version' => env('META_GRAPH_API_VERSION', 'v24.0'),
        'currency' => env('META_CURRENCY', 'EGP'),
        'app_platform' => env('META_APP_PLATFORM', 'android'),
        'android_package' => env('META_ANDROID_PACKAGE', 'com.smartvision.faskhanista'),
        'ios_bundle_id' => env('META_IOS_BUNDLE_ID', 'com.faskhaninja.clients'),
        'app_version' => env('META_APP_VERSION', ''),
        'app_build' => env('META_APP_BUILD', ''),
        'app_locale' => env('META_APP_LOCALE', 'ar_EG'),
        'app_timezone' => env('META_APP_TIMEZONE', 'Africa/Cairo'),
        'advertiser_tracking_enabled' => env('META_ADVERTISER_TRACKING_ENABLED', false),
        'application_tracking_enabled' => env('META_APPLICATION_TRACKING_ENABLED', false),
        'test_event_code' => env('META_TEST_EVENT_CODE'),
    ],

];
