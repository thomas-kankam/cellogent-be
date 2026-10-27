<?php

namespace App\Repositories;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

class RoleRepository
{
    public function allWithPermissions(): Collection
    {
        return Role::query()->with('permissions')->latest('id')->get();
    }

    public function find(int $id): ?Role
    {
        return Role::query()->with('permissions')->find($id);
    }

    public function create(array $attributes): Role
    {
        return Role::create($attributes);
    }

    public function allPermissions(): Collection
    {
        return Permission::query()->orderBy('module')->orderBy('name')->get();
    }
}
