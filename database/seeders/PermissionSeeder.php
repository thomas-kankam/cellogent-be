<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Product modules stay aligned with activity-log modules where practical.
     * Re-run: php artisan db:seed --class=PermissionSeeder && php artisan db:seed --class=RoleSeeder
     */
    public function run(): void
    {
        $permissions = [
            // Account / access
            ['module' => 'onboarding', 'name' => 'onboarding.client'],
            ['module' => 'onboarding', 'name' => 'onboarding.admin'],
            ['module' => 'otp', 'name' => 'otp.manage'],
            ['module' => 'account_status', 'name' => 'account_status.manage'],
            ['module' => 'profile', 'name' => 'profile.manage'],
            ['module' => 'role', 'name' => 'role.manage'],
            ['module' => 'permission', 'name' => 'permission.manage'],
            ['module' => 'admin', 'name' => 'admin.manage'],
            ['module' => 'client', 'name' => 'client.manage'],
            ['module' => 'activity_log', 'name' => 'activity_log.view'],
            ['module' => 'api_key', 'name' => 'api_key.manage'],

            // Sender IDs
            ['module' => 'sender_id', 'name' => 'sender_id.view'],
            ['module' => 'sender_id', 'name' => 'sender_id.approve'],
            ['module' => 'sender_id', 'name' => 'sender_id.blacklist'],

            // Plans & subscriptions
            ['module' => 'plan', 'name' => 'plan.manage'],
            ['module' => 'subscription', 'name' => 'subscription.view'],
            ['module' => 'subscription', 'name' => 'subscription.assign'],

            // Wallet / payments / orders
            ['module' => 'wallet', 'name' => 'wallet.view'],
            ['module' => 'payment', 'name' => 'payment.view'],
            ['module' => 'payment', 'name' => 'payment.manage'],
            ['module' => 'payment_gateway', 'name' => 'payment_gateway.manage'],
            ['module' => 'payment_settings', 'name' => 'payment_settings.manage'],
            ['module' => 'order', 'name' => 'order.view'],

            // Reports & exports
            ['module' => 'report', 'name' => 'report.view'],
            ['module' => 'report', 'name' => 'report.export'],

            // SMS
            ['module' => 'sms', 'name' => 'sms.view'],
            ['module' => 'sms', 'name' => 'sms.send'],
            ['module' => 'sms', 'name' => 'sms.aggregator'],
        ];

        foreach ($permissions as $permission) {
            Permission::query()->updateOrCreate(
                ['name' => $permission['name']],
                ['module' => $permission['module']],
            );
        }
    }
}
