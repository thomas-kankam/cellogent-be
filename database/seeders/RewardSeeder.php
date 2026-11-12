<?php

namespace Database\Seeders;

use App\Models\Reward;
use Illuminate\Database\Seeder;

/**
 * Starter rewards catalog for campaigns / surveys.
 * Data packages use Swift package_ids from the live catalogue (YELLO = Affordable MTN SME).
 *
 * Re-run: php artisan db:seed --class=RewardSeeder
 */
class RewardSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // Money (Paystack transfer)
            [
                'name' => 'GHS 5 Cash',
                'type' => Reward::MONEY,
                'size' => null,
                'amount' => 5,
                'network' => null,
                'bundle_id' => null,
                'provider_code' => 'paystack',
                'status' => Reward::INSTOCK,
            ],
            [
                'name' => 'GHS 10 Cash',
                'type' => Reward::MONEY,
                'size' => null,
                'amount' => 10,
                'network' => null,
                'bundle_id' => null,
                'provider_code' => 'paystack',
                'status' => Reward::INSTOCK,
            ],

            // Airtime (no beneficiary check)
            [
                'name' => 'MTN Airtime GHS 5',
                'type' => Reward::AIRTIME,
                'size' => null,
                'amount' => 5,
                'network' => 'MTN',
                'bundle_id' => null,
                'provider_code' => 'MTN',
                'status' => Reward::INSTOCK,
            ],
            [
                'name' => 'Telecel Airtime GHS 5',
                'type' => Reward::AIRTIME,
                'size' => null,
                'amount' => 5,
                'network' => 'TELECEL',
                'bundle_id' => null,
                'provider_code' => 'TELECEL',
                'status' => Reward::INSTOCK,
            ],

            // YELLO / Affordable MTN SME data — requires verified beneficiary
            [
                'name' => 'YELLO 1GB Data',
                'type' => Reward::DATA,
                'size' => '1GB',
                'amount' => 4.2,
                'network' => 'YELLO',
                'bundle_id' => 'yellow_1gb',
                'provider_code' => 'YELLO',
                'status' => Reward::INSTOCK,
                'meta' => ['requires_beneficiary' => true],
            ],
            [
                'name' => 'YELLO 2GB Data',
                'type' => Reward::DATA,
                'size' => '2GB',
                'amount' => 8.4,
                'network' => 'YELLO',
                'bundle_id' => 'yellow_2gb',
                'provider_code' => 'YELLO',
                'status' => Reward::INSTOCK,
                'meta' => ['requires_beneficiary' => true],
            ],
            [
                'name' => 'YELLO 5GB Data',
                'type' => Reward::DATA,
                'size' => '5GB',
                'amount' => 23,
                'network' => 'YELLO',
                'bundle_id' => 'yellow_5gb',
                'provider_code' => 'YELLO',
                'status' => Reward::INSTOCK,
                'meta' => ['requires_beneficiary' => true],
            ],

            // Other networks — no beneficiary check
            [
                'name' => 'Telecel 10GB Data',
                'type' => Reward::DATA,
                'size' => '10GB',
                'amount' => 38,
                'network' => 'TELECEL',
                'bundle_id' => 'telecel_10gb',
                'provider_code' => 'TELECEL',
                'status' => Reward::INSTOCK,
            ],
            [
                'name' => 'AT 3GB Data',
                'type' => Reward::DATA,
                'size' => '3GB',
                'amount' => 12,
                'network' => 'AT',
                'bundle_id' => 'at_3gb',
                'provider_code' => 'AT',
                'status' => Reward::INSTOCK,
            ],

            // Mash Up — no beneficiary check
            [
                'name' => 'MTN Mash Up 1GB + 353 Mins',
                'type' => Reward::DATA,
                'size' => '1GB + 353 Mins',
                'amount' => 24.77,
                'network' => 'MTN Mash Up',
                'bundle_id' => 'mashup_1gb+353mins',
                'provider_code' => 'MTN Mash Up',
                'status' => Reward::INSTOCK,
            ],
        ];

        foreach ($rows as $row) {
            Reward::query()->updateOrCreate(
                [
                    'type' => $row['type'],
                    'name' => $row['name'],
                ],
                [
                    'size' => $row['size'],
                    'amount' => $row['amount'],
                    'network' => $row['network'],
                    'units' => 0,
                    'currency' => 'GHS',
                    'provider_code' => $row['provider_code'],
                    'bundle_id' => $row['bundle_id'],
                    'status' => $row['status'],
                    'meta' => $row['meta'] ?? null,
                ],
            );
        }
    }
}
