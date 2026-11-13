<?php

$dir = dirname(__DIR__, 2).'/resources/api-docs/fixtures';

function ex(string $dir, string $name, array $request, array $response, string $note = 'Documented example — run against live API with real beneficiary/meter/smartcard.'): void
{
    $payload = [
        'http_status' => (int) ($response['status_code'] ?? 201),
        'note' => $note,
        'body' => $response,
        'request' => ['method' => 'POST', 'path' => '/client/utilities/purchase', 'body' => $request],
        'captured_at' => date('c'),
    ];
    file_put_contents("{$dir}/{$name}.example.json", json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "wrote {$name}\n";
}

$ok = fn (string $msg, array $data) => [
    'in_error' => false,
    'status_code' => '201',
    'message' => $msg,
    'reason' => 'created',
    'data' => $data,
];

$pendingPay = fn (float $amount, string $method = 'momo') => [
    'in_error' => false,
    'status_code' => '201',
    'message' => 'Complete the payment on the checkout page.',
    'reason' => 'created',
    'data' => [
        'id' => 99,
        'resource' => 'payment',
        'purpose' => 'utility',
        'method' => $method,
        'amount' => $amount,
        'status' => 'pending',
        'payment_link' => 'https://checkout.paystack.com/example',
        'currency' => 'GHS',
    ],
];

$order = fn (string $service, float $amount, string $beneficiary, ?string $name = null) => [
    'id' => 1,
    'resource' => 'utility_order',
    'reference' => 'UTL-EXAMPLE',
    'batch_id' => null,
    'service' => $service,
    'name' => $name ?? strtoupper($service),
    'beneficiary' => $beneficiary,
    'amount' => $amount,
    'currency' => 'GHS',
    'status' => 'pending',
    'funding_method' => 'wallet',
];

ex($dir, 'purchase_data_wallet', [
    'service' => 'data', 'package_id' => 'yellow_5gb', 'beneficiary' => '0244123456', 'method' => 'wallet',
], $ok('Purchase received and is being processed.', $order('data', 25.5, '233244123456', 'YELLO 5GB')));

ex($dir, 'purchase_data_momo', [
    'service' => 'data', 'package_id' => 'yellow_5gb', 'beneficiary' => '0244123456', 'method' => 'momo', 'phone_number' => '0244123456',
], $pendingPay(25.5));

ex($dir, 'purchase_mashup_wallet', [
    'service' => 'mashup', 'package_id' => 'mashup_1gb+353mins', 'beneficiary' => '0244123456', 'method' => 'wallet',
], $ok('Purchase received and is being processed.', $order('mashup', 12.0, '233244123456', 'Mash Up 1GB+353mins')));

ex($dir, 'purchase_airtime_wallet', [
    'service' => 'airtime', 'network' => 'MTN', 'amount' => 5, 'beneficiary' => '0244123456', 'method' => 'wallet',
], $ok('Purchase completed.', array_merge($order('airtime', 5.0, '233244123456', 'MTN airtime'), ['status' => 'completed'])));

ex($dir, 'purchase_ecg_wallet', [
    'service' => 'ecg', 'amount' => 20, 'beneficiary' => '1234567890', 'customer_phone' => '0244123456', 'account_name' => 'AMA MENSAH', 'method' => 'wallet',
], $ok('Purchase received and is being processed.', $order('ecg', 20.0, '1234567890', 'ECG payment')));

ex($dir, 'purchase_ecg_momo', [
    'service' => 'ecg', 'amount' => 20, 'beneficiary' => '1234567890', 'method' => 'momo',
], $pendingPay(20.0));

ex($dir, 'purchase_tv_dstv_wallet', [
    'service' => 'tv', 'bill_type' => 'DSTV', 'amount' => 50, 'beneficiary' => '7012345678', 'account_name' => 'KOFI OWUSU', 'method' => 'wallet',
], $ok('Purchase received and is being processed.', array_merge($order('tv', 50.0, '7012345678', 'DStv'), ['product_type' => 'tv'])));

ex($dir, 'purchase_tv_gotv_wallet', [
    'service' => 'tv', 'bill_type' => 'GOTV', 'amount' => 30, 'beneficiary' => '7012345678', 'method' => 'wallet',
], $ok('Purchase received and is being processed.', $order('tv', 30.0, '7012345678', 'GOtv')));

ex($dir, 'purchase_tv_startimes_wallet', [
    'service' => 'tv', 'bill_type' => 'STARTIMES', 'amount' => 25, 'beneficiary' => '7012345678', 'method' => 'wallet',
], $ok('Purchase received and is being processed.', $order('tv', 25.0, '7012345678', 'StarTimes')));

ex($dir, 'purchase_tv_dstv_momo', [
    'service' => 'tv', 'bill_type' => 'DSTV', 'amount' => 50, 'beneficiary' => '7012345678', 'method' => 'momo', 'phone_number' => '0244123456',
], $pendingPay(50.0));

ex($dir, 'purchase_results_wallet', [
    'service' => 'results', 'checker_type' => 'WASSCE', 'quantity' => 2, 'beneficiary' => '0244123456', 'method' => 'wallet',
], $ok('Purchase completed.', array_merge($order('results', 39.1, '233244123456', 'WASSCE Results Checker'), ['quantity' => 2, 'status' => 'completed'])));

ex($dir, 'purchase_results_momo', [
    'service' => 'results', 'checker_type' => 'BECE', 'quantity' => 1, 'beneficiary' => '0244123456', 'method' => 'momo',
], $pendingPay(19.55));

ex($dir, 'purchase_group_wallet', [
    'service' => 'data', 'package_id' => 'yellow_5gb', 'contact_group_ids' => [9], 'method' => 'wallet',
], $ok('Purchase received. 12 orders are being processed.', [
    'batch_id' => 'BLK-EXAMPLEGRP',
    'count' => 12,
    'amount' => 306.0,
    'currency' => 'GHS',
    'analytics' => [
        'total' => 12, 'pending' => 12, 'completed' => 0, 'failed' => 0,
        'progress_percent' => 0, 'message' => '0/12 delivered, 0 failed, 12 pending.',
    ],
    'audience' => ['total' => 40, 'verified' => 12, 'not_verified' => 28, 'requires_beneficiary' => true, 'source' => 'contact_groups'],
]));

ex($dir, 'purchase_items_wallet', [
    'method' => 'wallet',
    'items' => [
        ['service' => 'airtime', 'network' => 'MTN', 'amount' => 5, 'beneficiary' => '0244123456'],
        ['service' => 'ecg', 'amount' => 20, 'beneficiary' => '1234567890'],
        ['service' => 'results', 'checker_type' => 'WASSCE', 'quantity' => 1, 'beneficiary' => '0244123456'],
    ],
], $ok('Purchase received. 3 orders are being processed.', [
    'batch_id' => 'BLK-EXAMPLEITM',
    'count' => 3,
    'amount' => 44.55,
    'currency' => 'GHS',
    'analytics' => ['total' => 3, 'pending' => 3, 'completed' => 0, 'failed' => 0, 'progress_percent' => 0, 'message' => '0/3 delivered, 0 failed, 3 pending.'],
]));

ex($dir, 'purchase_items_momo', [
    'method' => 'momo',
    'phone_number' => '0244123456',
    'items' => [
        ['service' => 'airtime', 'network' => 'MTN', 'amount' => 5, 'beneficiary' => '0244123456'],
        ['service' => 'data', 'package_id' => 'at_3gb', 'beneficiary' => '0201234567'],
    ],
], $pendingPay(20.0));

foreach ([
    ['quote_tv_gotv', ['service' => 'tv', 'bill_type' => 'GOTV', 'amount' => 30], 'GOtv', 'gotv'],
    ['quote_tv_startimes', ['service' => 'tv', 'bill_type' => 'STARTIMES', 'amount' => 25], 'StarTimes', 'starttimes'],
] as [$name, $req, $label, $orderType]) {
    $payload = [
        'http_status' => 200,
        'note' => 'Documented example.',
        'body' => [
            'in_error' => false, 'status_code' => '200', 'message' => 'Quote ready.', 'reason' => 'success',
            'data' => [
                'service' => 'tv', 'order_type' => $orderType, 'product_type' => 'tv', 'name' => $label,
                'bill_type' => $req['bill_type'], 'amount' => (float) $req['amount'], 'currency' => 'GHS',
                'face_amount' => (float) $req['amount'],
            ],
        ],
        'request' => ['method' => 'POST', 'path' => '/client/utilities/quote', 'body' => $req],
        'captured_at' => date('c'),
    ];
    file_put_contents("{$dir}/{$name}.example.json", json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "wrote {$name}\n";
}

$momoPath = "{$dir}/purchase_momo_pending.json";
if (is_file($momoPath)) {
    $momo = json_decode((string) file_get_contents($momoPath), true);
    if (isset($momo['request']['body']['momo_network'])) {
        unset($momo['request']['body']['momo_network']);
        file_put_contents($momoPath, json_encode($momo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "cleaned purchase_momo_pending\n";
    }
}
