<?php

namespace Database\Seeders;

use App\Models\ProductMargin;
use Illuminate\Database\Seeder;

/**
 * Profit markups for selected product types only.
 * Catalog prices come live from Swift at quote/purchase; this table only stores % / fixed markup.
 *
 * Scoped to: data_bundle, mashup, results, wallet_top_up.
 * Airtime / ECG / TV / sms_units are left without a seeded rule (sell = cost unless you add one later).
 *
 * Re-run: php artisan db:seed --class=ProductMarginSeeder
 */
class ProductMarginSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // All Affordable / AT / Telecel data plans
            ['product_type' => 'data_bundle', 'network' => null, 'package_id' => null, 'markup_type' => ProductMargin::PERCENT, 'markup_value' => 10],
            // YELLO (Affordable MTN SME) override
            ['product_type' => 'data_bundle', 'network' => 'YELLO', 'package_id' => null, 'markup_type' => ProductMargin::PERCENT, 'markup_value' => 12],

            // MTN Mash Up
            ['product_type' => 'mashup', 'network' => null, 'package_id' => null, 'markup_type' => ProductMargin::PERCENT, 'markup_value' => 10],

            // WASSCE / BECE checkers
            ['product_type' => 'results', 'network' => null, 'package_id' => null, 'markup_type' => ProductMargin::PERCENT, 'markup_value' => 15],

            // Wallet top-ups (0% = pass-through; change when you want a fee)
            ['product_type' => 'wallet_top_up', 'network' => null, 'package_id' => null, 'markup_type' => ProductMargin::PERCENT, 'markup_value' => 0],
        ];

        foreach ($rows as $row) {
            ProductMargin::query()->updateOrCreate(
                [
                    'product_type' => $row['product_type'],
                    'network' => $row['network'],
                    'package_id' => $row['package_id'],
                ],
                [
                    'markup_type' => $row['markup_type'],
                    'markup_value' => $row['markup_value'],
                    'active' => true,
                ],
            );
        }
    }
}
