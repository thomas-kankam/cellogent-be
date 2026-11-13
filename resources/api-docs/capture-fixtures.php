<?php

/**
 * Capture real API responses for the docs UI.
 * Usage: php resources/api-docs/capture-fixtures.php
 * Requires: php artisan serve --host=127.0.0.1 --port=8765
 */

$base = getenv('API_DOCS_BASE') ?: 'http://127.0.0.1:8765/api';
$dir = __DIR__.'/fixtures';
@mkdir($dir, 0777, true);

$tokensFile = $dir.'/.tokens';
if (! is_file($tokensFile)) {
    fwrite(STDERR, "Missing {$tokensFile}. Create Passport tokens first.\n");
    exit(1);
}

$tokens = json_decode((string) file_get_contents($tokensFile), true);
$client = $tokens['client'] ?? '';
$admin = $tokens['admin'] ?? '';

function req(string $method, string $url, ?string $token = null, ?array $body = null): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    return [
        'http_status' => $code,
        'error' => $err ?: null,
        'body' => json_decode((string) $raw, true) ?? ['_raw' => $raw],
        'captured_at' => date('c'),
    ];
}

function save(string $dir, string $name, array $payload): void
{
    file_put_contents($dir.'/'.$name.'.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "saved {$name} http={$payload['http_status']}\n";
}

$calls = [
    // Public / light
    ['plans', 'GET', '/client/plans', null, null],
    ['client_wallet', 'GET', '/client/wallet', $client, null],
    ['client_subscription', 'GET', '/client/subscription', $client, null],
    ['client_sms_balance', 'GET', '/client/sms/balance', $client, null],
    ['client_rewards', 'GET', '/client/rewards', $client, null],
    ['client_rewards_catalog', 'GET', '/client/rewards/catalog', $client, null],
    ['client_utilities_catalog', 'GET', '/client/utilities/catalog', $client, null],
    ['client_payments', 'GET', '/client/payments?per_page=3', $client, null],
    ['client_campaigns', 'GET', '/client/campaigns?per_page=3', $client, null],
    ['client_surveys', 'GET', '/client/surveys?per_page=3', $client, null],
    ['client_contact_groups', 'GET', '/client/contact-groups?per_page=5', $client, null],
    ['client_sender_ids', 'GET', '/client/sender-ids?per_page=5', $client, null],

    // Quotes — multiple scenarios
    ['quote_data_single', 'POST', '/client/utilities/quote', $client, [
        'service' => 'data',
        'package_id' => 'yellow_5gb',
    ]],
    ['quote_data_group', 'POST', '/client/utilities/quote', $client, [
        'service' => 'data',
        'package_id' => 'yellow_5gb',
        'contact_group_ids' => [1],
    ]],
    ['quote_telecel_group', 'POST', '/client/utilities/quote', $client, [
        'service' => 'data',
        'package_id' => 'telecel_10gb',
        'contact_group_ids' => [1],
    ]],
    ['client_profile', 'GET', '/client/profile', $client, null],
    ['admin_profile', 'GET', '/admin/profile', $admin, null],
    ['quote_mashup', 'POST', '/client/utilities/quote', $client, [
        'service' => 'mashup',
        'package_id' => 'mashup_1gb+353mins',
    ]],
    ['quote_airtime', 'POST', '/client/utilities/quote', $client, [
        'service' => 'airtime',
        'network' => 'MTN',
        'amount' => 5,
    ]],
    ['quote_ecg', 'POST', '/client/utilities/quote', $client, [
        'service' => 'ecg',
        'amount' => 20,
    ]],
    ['quote_tv', 'POST', '/client/utilities/quote', $client, [
        'service' => 'tv',
        'bill_type' => 'DSTV',
        'amount' => 50,
    ]],
    ['quote_results', 'POST', '/client/utilities/quote', $client, [
        'service' => 'results',
        'checker_type' => 'WASSCE',
        'quantity' => 2,
    ]],
    ['quote_bulk_mixed', 'POST', '/client/utilities/quote', $client, [
        'items' => [
            ['service' => 'data', 'package_id' => 'at_3gb'],
            ['service' => 'airtime', 'network' => 'TELECEL', 'amount' => 5],
        ],
    ]],
    ['quote_airtime_beneficiaries', 'POST', '/client/utilities/quote', $client, [
        'service' => 'airtime',
        'network' => 'MTN',
        'amount' => 5,
        'beneficiaries' => '0244123456,0201234567',
    ]],
    ['quote_mutual_exclusion', 'POST', '/client/utilities/quote', $client, [
        'service' => 'airtime',
        'network' => 'MTN',
        'amount' => 5,
        'beneficiary' => '0244123456',
        'beneficiaries' => ['0244123456'],
    ]],
    ['purchase_missing_beneficiary', 'POST', '/client/utilities/purchase', $client, [
        'service' => 'airtime',
        'network' => 'MTN',
        'amount' => 5,
        'method' => 'wallet',
    ]],
    ['batch_not_found', 'GET', '/client/utilities/batches/BLK-DOESNOTEXIST', $client, null],

    // Admin
    ['admin_plans', 'GET', '/admin/plans?per_page=5', $admin, null],
    ['admin_rewards', 'GET', '/admin/rewards?per_page=10', $admin, null],
    ['admin_margins', 'GET', '/admin/margins', $admin, null],
    ['admin_margins_options', 'GET', '/admin/margins/options', $admin, null],
    ['admin_utilities_catalog', 'GET', '/admin/utilities/catalog', $admin, null],
    ['admin_utilities_balance', 'GET', '/admin/utilities/balance', $admin, null],
    ['admin_payment_gateways', 'GET', '/admin/payment-gateways', $admin, null],
    ['admin_payment_settings', 'GET', '/admin/settings/payments', $admin, null],
    ['admin_roles', 'GET', '/admin/roles?per_page=10', $admin, null],
    ['admin_permissions', 'GET', '/admin/permissions?per_page=50', $admin, null],
    ['admin_clients', 'GET', '/admin/clients?per_page=3', $admin, null],
    ['admin_wallets', 'GET', '/admin/wallets?per_page=3', $admin, null],
];

foreach ($calls as [$name, $method, $path, $token, $body]) {
    save($dir, $name, req($method, $base.$path, $token, $body) + [
        'request' => [
            'method' => $method,
            'path' => $path,
            'body' => $body,
        ],
    ]);
}

echo "done\n";
