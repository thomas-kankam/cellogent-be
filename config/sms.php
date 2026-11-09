<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Aggregator (admin-only details; never returned to clients)
    |--------------------------------------------------------------------------
    */

    'aggregator' => env('SMS_AGGREGATOR', 'kairos'),

    'kairos' => [
        'environment' => env('KAIROS_ENV', 'production'), // production | staging
        'base_urls' => [
            'production' => 'https://api.kairosafrika.com/v1',
            'staging' => 'https://api.staging.kairosafrika.com/v1',
        ],
        'key' => env('KARIOS_API_ACCESS_KEY'),
        'secret' => env('KARIOS_API_ACCESS_SECRET'),
        'version' => env('KAIROS_API_VERSION', '2025-08-01'),
        'timeout' => (int) env('KAIROS_TIMEOUT', 30),
        'connect_timeout' => (int) env('KAIROS_CONNECT_TIMEOUT', 5),
    ],

    // Sender used for platform messages (OTP, alerts) sent by SendSmsJob.
    'system_sender' => env('SMS_SYSTEM_SENDER'),

    /*
    |--------------------------------------------------------------------------
    | Throughput
    |--------------------------------------------------------------------------
    | Messages per second we allow ourselves to push to the aggregator, per lane.
    | Quick and bulk have separate budgets so a large blast never delays OTPs.
    */

    'throttle' => [
        'quick' => (int) env('SMS_TPS_QUICK', 20),
        'bulk' => (int) env('SMS_TPS_BULK', 100),
        'dlr' => (int) env('SMS_TPS_DLR', 20),
    ],

    'quick' => [
        'max_recipients' => (int) env('SMS_QUICK_MAX_RECIPIENTS', 100),
    ],

    'bulk' => [
        'max_groups' => (int) env('SMS_BULK_MAX_GROUPS', 20),
        'request_size' => (int) env('SMS_BULK_REQUEST_SIZE', 100),  // messages per aggregator call
        'job_size' => (int) env('SMS_BULK_JOB_SIZE', 2000),         // recipients per queued batch job
    ],

    /*
    |--------------------------------------------------------------------------
    | Unit billing
    |--------------------------------------------------------------------------
    | Every chars_per_unit characters of the message costs 1 SMS unit per recipient.
    | message_max_length caps the body (1600 ≈ 10 units at the default of 160).
    */

    'chars_per_unit' => (int) env('SMS_CHARS_PER_UNIT', 160),
    'message_max_length' => (int) env('SMS_MESSAGE_MAX_LENGTH', 1600),

    /*
    |--------------------------------------------------------------------------
    | Delivery reports (polled; the aggregator has no callback)
    |--------------------------------------------------------------------------
    */

    'dlr' => [
        'per_minute' => (int) env('SMS_DLR_PER_MINUTE', 1000),
        'job_size' => 100,
        // First poll soon after submit, then back off if still pending.
        'first_check_after' => (int) env('SMS_DLR_FIRST_CHECK_AFTER', 15),
        'backoff' => [15, 30, 60, 120, 300, 900, 1800, 3600],
        // How long sms:sync-delivery holds a row before it can be picked again if the job never finishes.
        'lease_seconds' => (int) env('SMS_DLR_LEASE_SECONDS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Aggregator balance alerts
    |--------------------------------------------------------------------------
    */

    'alerts' => [
        'low_balance_threshold' => (int) env('SMS_LOW_BALANCE_THRESHOLD', 10000),
        'emails' => array_filter(explode(',', (string) env('SMS_ALERT_EMAILS', ''))),
        'cooldown_minutes' => (int) env('SMS_ALERT_COOLDOWN_MINUTES', 360),
    ],

    // Requests per minute per API client on the public SMS API.
    'api_rate_per_minute' => (int) env('SMS_API_RATE_PER_MINUTE', 300),
];
