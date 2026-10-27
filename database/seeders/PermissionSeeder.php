<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Product modules match the activity-log modules so RBAC and logs stay aligned.
     */
    public function run(): void
    {
        $permissions = [
            ['module' => 'onboarding', 'name' => 'onboarding.client'],
            ['module' => 'onboarding', 'name' => 'onboarding.admin'],
            ['module' => 'otp', 'name' => 'otp.manage'],
            ['module' => 'account_status', 'name' => 'account_status.manage'],
            ['module' => 'profile', 'name' => 'profile.manage'],
            ['module' => 'role', 'name' => 'role.manage'],
            ['module' => 'permission', 'name' => 'permission.manage'],
            ['module' => 'sender_id', 'name' => 'sender_id.approve'],
            ['module' => 'api_key', 'name' => 'api_key.manage'],
            ['module' => 'admin', 'name' => 'admin.manage'],
            ['module' => 'client', 'name' => 'client.manage'],
            ['module' => 'activity_log', 'name' => 'activity_log.view'],
        ];

        foreach ($permissions as $permission) {
            Permission::query()->updateOrCreate(
                ['name' => $permission['name']],
                ['module' => $permission['module']],
            );
        }
    }
}
