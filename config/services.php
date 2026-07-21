<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gatewayapi' => [
        'token' => env('GATEWAYAPI_TOKEN'),
        'sender' => env('GATEWAYAPI_SENDER', 'Paxi'),
    ],

    'smile_identity' => [
        'partner_id' => env('SMILE_IDENTITY_PARTNER_ID'),
        'api_key' => env('SMILE_IDENTITY_API_KEY'),
        'callback_secret' => env('SMILE_IDENTITY_CALLBACK_SECRET'), // Signature key
        'callback_url' => env('SMILE_IDENTITY_CALLBACK_URL'),
        'env' => env('SMILE_IDENTITY_ENV', 'sandbox'), // sandbox or production
    ],

    'admin_cron_secret' => env('ADMIN_CRON_SECRET'),

    'admin_secret' => env('ADMIN_SECRET'),

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'payment_url' => env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Service Wallet Balance Check Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for checking service provider wallet balances before
    | processing transactions. This prevents debiting users when the
    | provider has insufficient balance to fulfill the order.
    |
    */
    'wallet_check' => [
        // Buffer to add on top of transaction amount (in Naira)
        'buffer_amount' => env('SERVICE_BUFFER_AMOUNT', 100),

        // Buffer as percentage of transaction amount (0-100)
        'buffer_percentage' => env('SERVICE_BUFFER_PERCENTAGE', 5),

        // Which buffer to use: 'amount', 'percentage', or 'both' (uses larger of the two)
        'use_buffer' => env('SERVICE_BUFFER_TYPE', 'amount'),

        // Services to check (must match ServiceWalletChecker method names)
        'enabled_services' => [
            'nellobytes',
            'vtpass',
            'vtuafrica',
            'palmpay',
            'paystack',
            'smileid',
            'gatewayapi',
            'one',
            'two',
            'three',
        ],

        // Service-specific configuration
        'services' => [
            'nellobytes' => [
                'enabled' => true,
                'endpoint' => 'https://www.nellobytesystems.com/APIWalletBalanceV1.asp',
            ],
            'vtpass' => [
                'enabled' => true,
                'endpoint' => 'https://vtpass.com/api/balance',
            ],
            'vtuafrica' => [
                'enabled' => true,
                'endpoint' => 'https://vtuafrica.com.ng/portal/api/balance/',
            ],
            'palmpay' => [
                'enabled' => true,
            ],
            'paystack' => [
                'enabled' => true,
            ],
            'smileid' => [
                'enabled' => true,
                'sandbox_url' => 'https://portal.smileidentity.com/api/v2/partner/wallet_balance',
                'production_url' => 'https://prod.smileidentity.com/api/v2/partner/wallet_balance',
            ],
            'gatewayapi' => [
                'enabled' => true,
                'endpoint' => 'https://gatewayapi.com/rest/me',
            ],
        ],
    ],

];
