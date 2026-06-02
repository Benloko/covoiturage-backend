<?php

return [
    'enabled' => filter_var((string) env('FEDAPAY_ENABLED', 'false'), FILTER_VALIDATE_BOOL),

    'public_key' => env('FEDAPAY_PUBLIC_KEY'),
    'secret_key' => env('FEDAPAY_SECRET_KEY'),

    'environment' => env('FEDAPAY_ENV', 'sandbox'),
    'api_base' => env('FEDAPAY_API_BASE'),

    'currency_iso' => env('FEDAPAY_CURRENCY_ISO', 'XOF'),
    'phone_country' => env('FEDAPAY_PHONE_COUNTRY', 'BJ'),

    'callback_url' => env('FEDAPAY_CALLBACK_URL'),

    'webhook_secret' => env('FEDAPAY_WEBHOOK_SECRET'),
    'webhook_tolerance' => (int) env('FEDAPAY_WEBHOOK_TOLERANCE', 300),

    'transaction_modes' => [
        'mtn_momo' => env('FEDAPAY_TRANSACTION_MODE_MTN', 'mtn'),
        'moov_money' => env('FEDAPAY_TRANSACTION_MODE_MOOV', 'moov'),
        'celtiis_cash' => env('FEDAPAY_TRANSACTION_MODE_CELTIIS', 'celtiis'),
    ],

    'payout_modes' => [
        'mtn_momo' => env('FEDAPAY_PAYOUT_MODE_MTN', 'mobile_money'),
        'moov_money' => env('FEDAPAY_PAYOUT_MODE_MOOV', 'mobile_money'),
        'celtiis_cash' => env('FEDAPAY_PAYOUT_MODE_CELTIIS', 'mobile_money'),
    ],
];
