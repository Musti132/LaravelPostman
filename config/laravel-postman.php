<?php

return [

    // Include fields from the form requests in the collection
    'include_fields' => false,

    // Path to the form requests folder
    'form_requests_path' => app_path('Http/Requests'),

    'app_url' => env('APP_URL', 'http://localhost'),

    'app_name' => env('APP_NAME', 'Laravel'),

    'port' => 8000,
];