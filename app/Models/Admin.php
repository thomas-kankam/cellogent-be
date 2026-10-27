<?php

namespace App\Models;

class Admin extends Actor
{
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'status',
        'role_id',
        'profile_image',
        'last_login_at',
        'onboarding_type',
        'onboarding_by',
    ];

    protected $hidden = [
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
        ];
    }

    public function guardName(): string
    {
        return 'admin';
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function onboardedBy()
    {
        return $this->belongsTo(Admin::class, 'onboarding_by');
    }

    /**
     * Permission names coming from the assigned role (not a direct admin-permission pivot).
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return $this->role?->permissions->pluck('name')->values()->all() ?? [];
    }

    public function hasPermission(string $name): bool
    {
        return in_array($name, $this->permissionNames(), true);
    }
}
