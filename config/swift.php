<?php

return [

    /*
    | SwiftData Ghana Developer API (v2) — https://swiftdatagh.shop/api-docs
    | This is NOT the reseller.swiftdatagh.shop API.
    */
    'base_url' => rtrim((string) env(
        'SWIFT_BASE_URL',
        'https://lsocdjpflecduumopijn.supabase.co/functions/v1/developer-api'
    ), '/'),

    // Beneficiary verify lives next to developer-api on the same functions host.
    'verify_url' => rtrim((string) env(
        'SWIFT_VERIFY_URL',
        'https://lsocdjpflecduumopijn.supabase.co/functions/v1/verify-beneficiary'
    ), '/'),

    'api_key' => env('SWIFT_API_KEY'),

    // HMAC secret: signs our requests and verifies X-Swift-Signature on /api/swift_webhook.
    'secret_key' => env('SWIFT_SECRET_KEY'),

    'timeout' => (int) env('SWIFT_TIMEOUT', 30),

    'connect_timeout' => (int) env('SWIFT_CONNECT_TIMEOUT', 5),

    // Fallback percent markup when no admin product_margins row applies.
    'markup_percent' => (float) env('SWIFT_MARKUP_PERCENT', 0),

    'webhook_path' => 'swift_webhook',

    // Seconds the /plans catalogue is cached.
    'catalog_ttl' => (int) env('SWIFT_CATALOG_TTL', 300),

    /*
    | Client-facing batch size for items[] / beneficiaries CSV (one Swift call per number).
    | Swift has no multi-phone data purchase — each recipient is its own /payment/data call.
    | Default API rate limit is ~30 req/min; we stagger job dispatch (see dispatch_spacing_seconds).
    */
    'bulk_max' => (int) env('SWIFT_BULK_MAX', 50),

    // Max contacts expanded from contact_group_ids in one purchase (queued individually).
    'group_purchase_max' => (int) env('SWIFT_GROUP_PURCHASE_MAX', 2000),

    // Seconds between queued Swift sends in a batch (keeps us under provider rate limits).
    'dispatch_spacing_seconds' => (int) env('SWIFT_DISPATCH_SPACING', 2),

    /*
    | Affordable MTN SME data (catalog network YELLO, sometimes labeled MTN).
    | Mash Up / AT / TELECEL / airtime do NOT need beneficiary checks.
    */
    'beneficiary_required_networks' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SWIFT_BENEFICIARY_NETWORKS', 'YELLO,MTN'))
    ))),

    /*
    | Services we expose. SMS from Swift is intentionally not used (Cellogent has Kairos).
    | AFA registration is also not integrated.
    | `mashup` is a data plan whose network category is "MTN Mash Up".
    */
    'services' => [
        'data' => ['order_type' => 'data_bundle'],
        'mashup' => ['order_type' => 'mashup'],
        'airtime' => ['order_type' => 'airtime'],
        'ecg' => ['order_type' => 'ecg'],
        'tv' => ['order_type' => 'dstv'],
        'results' => ['order_type' => 'results'],
    ],

    // Airtime networks accepted by POST /payment/airtime.
    'airtime_networks' => [
        ['code' => 'MTN', 'name' => 'MTN'],
        ['code' => 'TELECEL', 'name' => 'Telecel'],
        ['code' => 'AT', 'name' => 'AirtelTigo'],
        ['code' => 'GLO', 'name' => 'Glo'],
    ],

    'airtime_limits' => [
        'min' => (float) env('SWIFT_AIRTIME_MIN', 1),
        'max' => (float) env('SWIFT_AIRTIME_MAX', 500),
    ],

    // billType values accepted by /payment/bills/validate and /payment/bills/pay.
    'tv_bill_types' => [
        ['code' => 'DSTV', 'name' => 'DStv', 'order_type' => 'dstv'],
        ['code' => 'GOTV', 'name' => 'GOtv', 'order_type' => 'gotv'],
        ['code' => 'STARTIMES', 'name' => 'StarTimes', 'order_type' => 'starttimes'],
    ],

    'bill_limits' => [
        'min' => (float) env('SWIFT_BILL_MIN', 1),
        'max' => (float) env('SWIFT_BILL_MAX', 5000),
    ],

    // Swift has no price list for results checkers; the per-voucher API cost is configured here.
    'results_checkers' => [
        ['type' => 'WASSCE', 'name' => 'WASSCE Results Checker', 'unit_cost' => (float) env('SWIFT_RESULTS_WASSCE_COST', 17)],
        ['type' => 'BECE', 'name' => 'BECE Results Checker', 'unit_cost' => (float) env('SWIFT_RESULTS_BECE_COST', 17)],
    ],

    'results_max_quantity' => 100,

];
