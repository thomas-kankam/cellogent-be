<?php

return [

    'currency' => 'GHS',

    // Where the browser lands after a redirect checkout (Paystack). ?reference=&status= are appended.
    'return_url' => env('PAYMENT_RETURN_URL', rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/').'/payments/complete'),

    // Purchases a client can start per minute (top-ups, plans, checkout combined).
    'checkout_per_minute' => (int) env('PAYMENT_CHECKOUT_PER_MINUTE', 10),

    'wallet_top_up' => [
        'min' => (float) env('WALLET_TOP_UP_MIN', 1),
        'max' => (float) env('WALLET_TOP_UP_MAX', 50000),
    ],

    // A gateway is skipped (fallback used first) after this many connection failures in a row,
    // until the cooldown passes.
    'circuit' => [
        'failures' => 3,
        'cooldown_minutes' => 5,
    ],

    // Seconds between status checks while a payment is pending. After the last one it is marked failed.
    'verify_backoff' => [20, 30, 30, 60, 60, 120, 300, 600, 900, 1800, 3600],

    'gateways' => [
        'kairos' => [
            'name' => 'Kairos Afrika',
            'methods' => ['momo'],
            'environment' => env('KAIROS_PAY_ENV', 'production'),
            'base_urls' => [
                'production' => 'https://api.kairosafrika.cloud/paygw',
                'staging' => 'https://api.staging.kairosafrika.cloud/paygw',
            ],
            'username' => env('KAIROS_PAY_USERNAME'),
            'password' => env('KAIROS_PAY_PASSWORD'),
            'callback_id' => env('KAIROS_PAY_CALLBACK_ID'),
            'payee' => env('KAIROS_PAY_PAYEE', env('APP_NAME', 'Cellogent')),
            'webhook_token' => env('KAIROS_PAY_WEBHOOK_TOKEN'),
            'timeout' => 30,
        ],

        'paystack' => [
            'name' => 'Paystack',
            'methods' => ['card', 'momo'],
            'base_url' => 'https://api.paystack.co',
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'public_key' => env('PAYSTACK_PUBLIC_KEY'),
            'callback_url' => env('PAYSTACK_CALLBACK_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/api/callbacks/paystack'),
            'timeout' => 30,
        ],
    ],
];
