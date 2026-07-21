<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PalmPay Biller-Reseller API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for PalmPay Biller-Reseller API integration including
    | credentials, base URL, retry settings, and endpoints.
    |
    | Key setup:
    | 1. Run: php artisan palmpay:generate-keys
    | 2. Store the private key in .env as PALMPAY_MERCHANT_PRIVATE_KEY
    | 3. Upload the public key to PalmPay merchant platform
    | 4. Get the PalmPay platform public key and store as PALMPAY_PLATFORM_PUBLIC_KEY
    |
    */

    'base_url' => env('PALMPAY_BASE_URL', 'https://open-gw-prod.palmpay-inc.com/api/v2/bill-payment/'),

    'sandbox_base_url' => env('PALMPAY_SANDBOX_URL', 'https://open-gw-daily.palmpay-inc.com/api/v2/bill-payment/'),

    'app_id' => env('PALMPAY_APP_ID'),

    // Merchant's RSA private key (for signing requests) — generate via: php artisan palmpay:generate-keys
    'merchant_private_key' => env('PALMPAY_MERCHANT_PRIVATE_KEY'),

    // PalmPay platform public key (for verifying callback signatures)
    'platform_public_key' => env('PALMPAY_PLATFORM_PUBLIC_KEY'),

    // Callback URL for payment result notifications
    'notify_url' => env('PALMPAY_NOTIFY_URL'),

    'use_sandbox' => env('PALMPAY_SANDBOX', false),

    'timeout' => env('PALMPAY_TIMEOUT', 60),

    'retry' => [
        'attempts' => env('PALMPAY_RETRY_ATTEMPTS', 3),
        'delay' => env('PALMPAY_RETRY_DELAY', 1000), // milliseconds
    ],

    'cache' => [
        'ttl' => env('PALMPAY_CACHE_TTL', 86400), // 24 hours in seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | API Endpoints (relative to base_url)
    |--------------------------------------------------------------------------
    */
    'endpoints' => [
        'query_biller'           => 'biller/query',
        'query_item'             => 'item/query',
        'create_order'           => 'order/create',
        'query_order'            => 'order/query',
        'query_recharge_account' => 'rechargeaccount/query',
        'virtual_account_create'     => 'virtual/account/label/create',
        'bank_transfer_create_order' => 'payment/merchant/createorder',
        'payout_query_bank_list'     => 'general/merchant/queryBankList',
        'payout_query_bank_account'  => 'payment/merchant/payout/queryBankAccount',
        'payout'                     => 'merchant/payment/payout',
    ],

    /*
    |--------------------------------------------------------------------------
    | Network Mapping
    |--------------------------------------------------------------------------
    |
    | Maps internal network IDs to PalmPay billerId values.
    | These correspond to billerIds returned by the biller/query endpoint.
    |
    */
    'network_map' => [
        '1' => 'MTN',
        '2' => 'GLO',
        '3' => '9MOBILE',
        '4' => 'AIRTEL',
    ],
];
