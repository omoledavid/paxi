<?php

return [
    'base_url' => env('VTPASS_BASE_URL', 'https://sandbox.vtpass.com/api/'),
    'api_key' => env('VTPASS_API_KEY'),
    'secret_key' => env('VTPASS_SECRET_KEY'), // public_key in vtpass context usually just username/password or api key. prompts said username/password/api_key
    'username' => env('VTPASS_USERNAME'),
    'password' => env('VTPASS_PASSWORD'),
    'vtpassSandbox' => env('VTPASS_SANDBOX', false),

    // Some endpoints might specific
    'endpoints' => [
        'pay' => 'pay',
        'query' => 'requery',
        'merchant_verify' => 'merchant-verify',
        'variations' => 'service-variations',
        'services' => 'services',
        'service_categories' => 'service-categories',
    ],

    'timeout' => 60,

    'retry' => [
        'attempts' => 3,
        'delay' => 1000,
    ],
];
