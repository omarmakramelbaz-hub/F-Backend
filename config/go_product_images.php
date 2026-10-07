<?php

return [
    // Server-side credentials only. The mobile app never receives either key.
    'enabled' => env('GO_PRODUCT_IMAGES_ENABLED', false),
    'openai_key' => env('GO_PRODUCT_IMAGES_OPENAI_KEY'),
    'model' => env('GO_PRODUCT_IMAGES_MODEL', 'gpt-4.1-mini'),
    'search_key' => env('GO_PRODUCT_IMAGES_BRAVE_KEY'),
    'batch_size' => 3,
    'confidence' => 0.90,
];
