<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Bronze',
                'details' => [
                    ['months' => 1, 'units' => 1300, 'price' => 40],
                    ['months' => 2, 'units' => 2600, 'price' => 80],
                    ['months' => 3, 'units' => 3900, 'price' => 110],
                ],
            ],
            [
                'name' => 'Silver',
                'details' => [
                    ['months' => 1, 'units' => 3000, 'price' => 90],
                    ['months' => 2, 'units' => 6000, 'price' => 170],
                    ['months' => 3, 'units' => 9000, 'price' => 240],
                ],
            ],
            [
                'name' => 'Gold',
                'details' => [
                    ['months' => 1, 'units' => 8000, 'price' => 200],
                    ['months' => 2, 'units' => 16000, 'price' => 380],
                    ['months' => 3, 'units' => 24000, 'price' => 540],
                ],
            ],
            [
                'name' => 'Platinum',
                'details' => [
                    ['months' => 1, 'units' => 20000, 'price' => 400],
                    ['months' => 2, 'units' => 40000, 'price' => 760],
                    ['months' => 3, 'units' => 60000, 'price' => 1080],
                ],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(
                ['name' => $plan['name']],
                [
                    'details' => $plan['details'],
                    'status' => 'active',
                ],
            );
        }
    }
}
