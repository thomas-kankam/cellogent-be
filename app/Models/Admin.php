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
        $this->loadMissing('role.permissions');

        return in_array($name, $this->permissionNames(), true);
    }

    /**
     * @param  list<string>  $names
     */
    public function hasAnyPermission(array $names): bool
    {
        $this->loadMissing('role.permissions');
        $owned = $this->permissionNames();

        foreach ($names as $name) {
            if (in_array($name, $owned, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    public function permissionIds(): array
    {
        $this->loadMissing('role.permissions');

        return $this->role?->permissions->pluck('id')->map(fn ($id) => (int) $id)->values()->all() ?? [];
    }

    /**
     * True when every permission id is one this admin already holds (no privilege escalation).
     *
     * @param  iterable<int|string>  $permissionIds
     */
    public function holdsAllPermissionIds(iterable $permissionIds): bool
    {
        $owned = $this->permissionIds();

        foreach ($permissionIds as $id) {
            if (! in_array((int) $id, $owned, true)) {
                return false;
            }
        }

        return true;
    }

    public function canGrantRole(Role $role): bool
    {
        return $this->holdsAllPermissionIds($role->permissions()->pluck('permissions.id'));
    }

    /**
     * Another admin can only be edited / re-roled / deactivated / deleted by someone at least as powerful.
     */
    public function canManageAdmin(Admin $target): bool
    {
        if ((int) $this->id === (int) $target->id) {
            return false;
        }

        $target->loadMissing('role.permissions');

        return $this->holdsAllPermissionIds($target->permissionIds());
    }
}
