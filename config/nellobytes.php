<?php

return [
    /*
    |--------------------------------------------------------------------------
    | NelloBytes API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for NelloBytes API integration including credentials,
    | base URL, retry settings, and cache TTL.
    |
    */

    'base_url' => env('NELLOBYTES_BASE_URL', 'https://www.nellobytesystems.com/'),

    'user_id' => env('NELLOBYTES_USER_ID'),
    'api_key' => env('NELLOBYTES_API_KEY'),

    'webhook_secret' => env('NELLOBYTES_WEBHOOK_SECRET'),

    'retry' => [
        'attempts' => env('NELLOBYTES_RETRY_ATTEMPTS', 3),
        'delay' => env('NELLOBYTES_RETRY_DELAY', 1000), // milliseconds
    ],

    // Optional default callback for EPIN purchases
    'epin_callback_url' => env('NELLOBYTES_EPIN_CALLBACK_URL'),
    // Optional default callback for Smile purchases
    'smile_callback_url' => env('NELLOBYTES_SMILE_CALLBACK_URL'),
    // Default Smile mobile network code
    'smile_default_network' => env('NELLOBYTES_SMILE_DEFAULT_NETWORK', 'smile-direct'),
    // Optional default callback for Betting funding
    'betting_callback_url' => env('NELLOBYTES_BETTING_CALLBACK_URL'),

    'cache' => [
        'ttl' => env('NELLOBYTES_CACHE_TTL', 86400), // 24 hours in seconds
    ],

    'timeout' => env('NELLOBYTES_TIMEOUT', 30), // seconds

    'endpoints' => [
        'betting' => [
            'fund' => 'APIBettingV1.asp',
            'verify' => 'APIVerifyBettingV1.asp',
            'companies' => 'APIBettingCompaniesV2.asp',
        ],
        'epin' => [
            'print' => 'APIEPINV1.asp',
            'discounts' => 'APIEPINDiscountV2.asp',
        ],
        'smile' => [
            'buy' => 'APISmileV1.asp',
            'verify' => 'APIVerifySmileV1.asp',
            'packages' => 'APISmilePackagesV2.asp',
        ],
        'spectranet' => [
            'buy' => 'APISpectranetV1.asp',
            'packages' => 'APISpectranetPackagesV2.asp',
        ],
        'airtime' => [
            'purchase' => 'APIAirtimeV1.asp',
        ],
        'data' => [
            'buy' => 'APIDatabundleV1.asp',
            'verify' => 'APIVerifyDataV1.asp',
            'packages' => 'APIDataPackagesV2.asp',
        ],
        'electricity' => [
            'buy' => 'APIVerifyElectricityV1.asp',
            'verify' => 'APIVerifyElectricityV1.asp',
            'providers' => 'APIElectricityDiscosV2.asp',
        ],
        'cabletv' => [
            'buy' => 'APICableTVV1.asp',
            'plans' => 'APICableTVPackagesV2.asp',
        ],
        'query' => 'APIQueryV1.asp',
        'cancel' => 'APICancelV1.asp',
    ],
];

