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

        // Always attach every current permission (including newly seeded ones).
        $super->permissions()->sync(Permission::query()->pluck('id'));

        $support = Role::query()->updateOrCreate(
            ['name' => 'Support'],
            ['description' => 'Client support without role, gateway, or admin onboarding access.'],
        );

        $support->permissions()->sync(
            Permission::query()
                ->whereIn('name', [
                    'client.manage',
                    'profile.manage',
                    'activity_log.view',
                    'sender_id.view',
                    'sender_id.approve',
                    'subscription.view',
                    'wallet.view',
                    'payment.view',
                    'order.view',
                    'sms.view',
                    'report.view',
                ])
                ->pluck('id')
        );
    }
}
