<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $super = Role::query()->updateOrCreate(
            ['name' => 'Super Admin'],
            ['description' => 'Full access. Used to onboard other admins.'],
        );

        $super->permissions()->sync(Permission::query()->pluck('id'));

        $support = Role::query()->updateOrCreate(
            ['name' => 'Support'],
            ['description' => 'Client support without role or admin onboarding.'],
        );

        $support->permissions()->sync(
            Permission::query()
                ->whereIn('name', [
                    'client.manage',
                    'profile.manage',
                    'activity_log.view',
                    'sender_id.approve',
                    'referral.view',
                ])
                ->pluck('id')
        );
    }
}
