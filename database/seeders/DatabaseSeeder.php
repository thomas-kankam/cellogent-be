<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            AdminSeeder::class,
            PlanSeeder::class,
            ProductMarginSeeder::class,
            RewardSeeder::class,
            PassportSeeder::class,
        ]);
    }
}
