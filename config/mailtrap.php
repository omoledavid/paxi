<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mailtrap API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Mailtrap email sending service. Supports both
    | sandbox (testing) and production email sending.
    |
    */

    'api_key' => env('MAILTRAP_API_KEY'),

    'inbox_id' => env('MAILTRAP_INBOX_ID'),

    'use_sandbox' => env('MAILTRAP_USE_SANDBOX', true),

    'from' => [
        'address' => env('MAILTRAP_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
        'name' => env('MAILTRAP_FROM_NAME', env('MAIL_FROM_NAME', env('APP_NAME', 'Laravel'))),
    ],
];
