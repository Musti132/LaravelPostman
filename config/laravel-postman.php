<?php

return [
    // App Url
    'app_url' => env('APP_URL', 'http://localhost'),

    // Collection Name
    'collection_name' => env('APP_NAME', 'Laravel'),

    // Port can be null
    'port' => 8000,

    // Routes to ignore (Do not add prefix such as api/v1/)
    'ignored_routes' => [
        'example/*',
    ],

    // Path to save the collection
    'path' => base_path(),
];