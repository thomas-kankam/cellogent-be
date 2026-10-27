<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Role;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * First admin so you can request an OTP without a chicken-and-egg problem.
     * Set SEED_ADMIN_EMAIL in .env.
     */
    public function run(): void
    {
        $role = Role::query()->where('name', 'Super Admin')->first();

        Admin::query()->updateOrCreate(
            ['email' => env('SEED_ADMIN_EMAIL', 'kankamthomas6@gmail.com')],
            [
                'first_name' => 'Super',
                'last_name' => 'Admin',
                'status' => 'active',
                'role_id' => $role?->id,
                'onboarding_type' => 'system',
            ],
        );
    }
}
