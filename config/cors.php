<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_merge(
        explode(',', env('CORS_ALLOWED_ORIGINS', 'https://tocco.app,https://*.tocco.app')),
        ['http://localhost', 'http://127.0.0.1', 'capacitor://localhost'],
    ),

    'allowed_origins_patterns' => [
        '#^https?://localhost(:\d+)?$#',
        '#^https?://127\.0\.0\.1(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    // Matchpollen läser ETag (för If-None-Match) och Retry-After (vid 429) från JS.
    'exposed_headers' => ['ETag', 'Retry-After'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
