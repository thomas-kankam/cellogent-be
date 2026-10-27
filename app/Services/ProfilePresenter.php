<?php

namespace App\Services;

use App\Models\Actor;
use App\Models\Admin;
use App\Models\Client;

/**
 * The account payload returned after login and on GET /profile.
 * Admin includes role + permissions. Client includes wallet + subscription.
 */
class ProfilePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(Actor $actor): array
    {
        if ($actor instanceof Admin) {
            $actor->loadMissing(['role.permissions']);

            return [
                'id' => $actor->id,
                'first_name' => $actor->first_name,
                'last_name' => $actor->last_name,
                'email' => $actor->email,
                'phone_number' => $actor->phone_number,
                'status' => $actor->status,
                'profile_image' => $actor->profileImageUrl(),
                'last_login_at' => optional($actor->last_login_at)->toDateTimeString(),
                'role' => $actor->role ? [
                    'id' => $actor->role->id,
                    'name' => $actor->role->name,
                    'description' => $actor->role->description,
                    'permissions' => $actor->role->permissions->map(fn ($p) => [
                        'id' => $p->id,
                        'module' => $p->module,
                        'name' => $p->name,
                    ])->values()->all(),
                ] : null,
            ];
        }

        /** @var Client $actor */
        $actor->loadMissing(['wallet', 'subscription']);

        return [
            'id' => $actor->id,
            'first_name' => $actor->first_name,
            'last_name' => $actor->last_name,
            'email' => $actor->email,
            'phone_number' => $actor->phone_number,
            'date_of_birth' => optional($actor->date_of_birth)->toDateString(),
            'gender' => $actor->gender,
            'account_type' => $actor->account_type,
            'business_name' => $actor->business_name,
            'region' => $actor->region,
            'city' => $actor->city,
            'address' => $actor->address,
            'status' => $actor->status,
            'is_verified' => (bool) $actor->is_verified,
            'verified_at' => optional($actor->verified_at)->toDateTimeString(),
            'profile_image' => $actor->profileImageUrl(),
            'onboarding_type' => $actor->onboarding_type,
            'last_login_at' => optional($actor->last_login_at)->toDateTimeString(),
            'wallet' => $actor->wallet ? [
                'balance' => $actor->wallet->balance,
                'currency' => $actor->wallet->currency,
                'status' => $actor->wallet->status,
            ] : null,
            'subscription' => $actor->subscription ? [
                'plan_id' => $actor->subscription->plan_id,
                'plan_name' => $actor->subscription->plan_name,
                'status' => $actor->subscription->status,
                'price' => $actor->subscription->price,
                'units' => $actor->subscription->units,
                'type' => $actor->subscription->type,
                'duration_in_months' => $actor->subscription->duration_in_months,
                'starts_at' => optional($actor->subscription->starts_at)->toDateTimeString(),
                'ends_at' => optional($actor->subscription->ends_at)->toDateTimeString(),
            ] : null,
        ];
    }
}
