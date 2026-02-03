<?php

return [
    /*
    |--------------------------------------------------------------------------
    | VTU Africa API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for VTU Africa API integration including credentials,
    | base URL, retry settings, and endpoints.
    |
    */

    'base_url' => env('VTU_AFRICA_BASE_URL', 'https://vtuafrica.com.ng/portal/api/'),

    'sandbox_base_url' => env('VTU_AFRICA_SANDBOX_URL', 'https://vtuafrica.com.ng/portal/api-test/'),

    'api_key' => env('VTU_AFRICA_API_KEY'),

    'use_sandbox' => env('VTU_AFRICA_SANDBOX', false),

    'timeout' => env('VTU_AFRICA_TIMEOUT', 60),

    'retry' => [
        'attempts' => env('VTU_AFRICA_RETRY_ATTEMPTS', 3),
        'delay' => env('VTU_AFRICA_RETRY_DELAY', 1000), // milliseconds
    ],

    'cache' => [
        'ttl' => env('VTU_AFRICA_CACHE_TTL', 86400), // 24 hours in seconds
    ],

    'endpoints' => [
        'betting' => [
            'verify' => 'merchant-verify/',
            'fund' => 'betpay',
        ],
        'airtime' => [
            'purchase' => 'airtime/',
        ],
        'data' => [
            'purchase' => 'data/',
        ],
        'cabletv' => [
            'verify' => 'merchant-verify/',
            'purchase' => 'paytv/',
        ],
        'electricity' => [
            'verify' => 'merchant-verify/',
            'purchase' => 'electric/',
        ],
        'exam' => [
            'purchase' => 'exam-pin/'
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Betting Companies
    |--------------------------------------------------------------------------
    |
    | VTU Africa does not provide an endpoint to fetch betting companies.
    | This static list is used instead.
    |
    */
    'betting_companies' => [
        ['code' => 'bet9ja', 'name' => 'Bet9ja'],
        ['code' => 'betking', 'name' => 'BetKing'],
        ['code' => '1xbet', 'name' => '1XBet'],
        ['code' => 'nairabet', 'name' => 'NairaBet'],
        ['code' => 'betbiga', 'name' => 'BetBiga'],
        ['code' => 'merrybet', 'name' => 'MerryBet'],
        ['code' => 'sportybet', 'name' => 'SportyBet'],
        ['code' => 'naijabet', 'name' => 'NaijaBet'],
        ['code' => 'betway', 'name' => 'Betway'],
        ['code' => 'bangbet', 'name' => 'BangBet'],
        ['code' => 'melbet', 'name' => 'MelBet'],
        ['code' => 'livescorebet', 'name' => 'LiveScoreBet'],
        ['code' => 'naira-million', 'name' => 'Naira-Million'],
        ['code' => 'cloudbet', 'name' => 'CloudBet'],
        ['code' => 'paripesa', 'name' => 'Paripesa'],
        ['code' => 'mylottohub', 'name' => 'MylottoHub'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cable TV Plans
    |--------------------------------------------------------------------------
    |
    | VTU Africa cable TV subscription plans with variation codes and prices.
    |
    */
    'cabletv_plans' => [
        'gotv' => [
            ['code' => 'gotv_smallie', 'name' => 'GOtv Smallie', 'price' => 1900],
            ['code' => 'gotv_smallie_3months', 'name' => 'GOtv Smallie 3 Months', 'price' => 5100],
            ['code' => 'gotv_smallie_1year', 'name' => 'GOtv Smallie 1 Year', 'price' => 15000],
            ['code' => 'gotv_jinja', 'name' => 'GOtv Jinja', 'price' => 3900],
            ['code' => 'gotv_jolli', 'name' => 'GOtv Jolli', 'price' => 5800],
            ['code' => 'gotv_max', 'name' => 'GOtv Max', 'price' => 8500],
        ],
        'dstv' => [
            ['code' => 'dstv_padi', 'name' => 'DStv Padi', 'price' => 4400],
            ['code' => 'dstv_yanga', 'name' => 'DStv Yanga', 'price' => 6000],
            ['code' => 'dstv_confam', 'name' => 'DStv Confam', 'price' => 11000],
            ['code' => 'dstv_compact', 'name' => 'DStv Compact', 'price' => 19000],
            ['code' => 'dstv_compact_plus', 'name' => 'DStv Compact Plus', 'price' => 30000],
            ['code' => 'dstv_premium', 'name' => 'DStv Premium', 'price' => 44500],
            ['code' => 'dstv_asia', 'name' => 'DStv Asia', 'price' => 14900],
            ['code' => 'dstv_premium_french', 'name' => 'DStv Premium French', 'price' => 69000],
        ],
        'startimes' => [
            ['code' => 'startimes_nova_daily', 'name' => 'Startimes Nova Daily', 'price' => 0],
            ['code' => 'startimes_nova_weekly', 'name' => 'Startimes Nova Weekly', 'price' => 600],
            ['code' => 'startimes_nova', 'name' => 'Startimes Nova', 'price' => 1900],
            ['code' => 'startimes_basic_daily', 'name' => 'Startimes Basic Daily', 'price' => 0],
            ['code' => 'startimes_basic_weekly', 'name' => 'Startimes Basic Weekly', 'price' => 1250],
            ['code' => 'startimes_basic', 'name' => 'Startimes Basic', 'price' => 3700],
            ['code' => 'startimes_smart_daily', 'name' => 'Startimes Smart Daily', 'price' => 0],
            ['code' => 'startimes_smart_weekly', 'name' => 'Startimes Smart Weekly', 'price' => 1550],
            ['code' => 'startimes_smart', 'name' => 'Startimes Smart', 'price' => 4700],
            ['code' => 'startimes_classic_daily', 'name' => 'Startimes Classic Daily', 'price' => 0],
            ['code' => 'startimes_classic_weekly', 'name' => 'Startimes Classic Weekly', 'price' => 1900],
            ['code' => 'startimes_classic', 'name' => 'Startimes Classic', 'price' => 5500],
            ['code' => 'startimes_super_daily', 'name' => 'Startimes Super Daily', 'price' => 0],
            ['code' => 'startimes_super_weekly', 'name' => 'Startimes Super Weekly', 'price' => 3000],
            ['code' => 'startimes_super', 'name' => 'Startimes Super', 'price' => 9000],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Electricity Providers
    |--------------------------------------------------------------------------
    |
    | VTU Africa supported electricity distribution companies (DISCOs).
    |
    */
    'electricity_providers' => [
        ['code' => 'ikeja-electric', 'name' => 'Ikeja Electric (IE)'],
        ['code' => 'eko-electric', 'name' => 'Eko Electric (EKEDC)'],
        ['code' => 'abuja-electric', 'name' => 'Abuja Electric (AEDC)'],
        ['code' => 'kano-electric', 'name' => 'Kano Electric (KEDCO)'],
        ['code' => 'portharcourt-electric', 'name' => 'Port Harcourt Electric (PHEDC)'],
        ['code' => 'jos-electric', 'name' => 'Jos Electric (JED)'],
        ['code' => 'kaduna-electric', 'name' => 'Kaduna Electric (KEDC)'],
        ['code' => 'enugu-electric', 'name' => 'Enugu Electric (EEDC)'],
        ['code' => 'ibadan-electric', 'name' => 'Ibadan Electric (IBEDC)'],
        ['code' => 'benin-electric', 'name' => 'Benin Electric (BEDC)'],
        ['code' => 'aba-electric', 'name' => 'Aba Electric (ABEDC)'],
        ['code' => 'yola-electric', 'name' => 'Yola Electric (YEDC)'],
    ],
];
