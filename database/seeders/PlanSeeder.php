<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['name' => 'Bronze', 'details' => ['months' => 1, 'units' => 1300, 'price' => 40]],
            ['name' => 'Silver', 'details' => ['months' => 1, 'units' => 3000, 'price' => 90]],
            ['name' => 'Gold', 'details' => ['months' => 1, 'units' => 8000, 'price' => 200]],
            ['name' => 'Platinum', 'details' => ['months' => 1, 'units' => 20000, 'price' => 400]],
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
