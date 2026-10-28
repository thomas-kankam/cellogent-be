<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Models\Admin;
use App\Models\Role;
use App\Repositories\RoleRepository;
use App\Support\ApiCode;
use App\Support\ApiReason;

class RoleService
{
    use LogsActivity;

    public function __construct(protected RoleRepository $roles)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Role $role): array
    {
        $role->loadMissing('permissions');

        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'permissions' => $role->permissions->map(fn ($p) => [
                'id' => $p->id,
                'module' => $p->module,
                'name' => $p->name,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function create(Admin $admin, array $input): array
    {
        $role = $this->roles->create([
            'name' => $input['name'],
            'description' => $input['description'] ?? null,
            'created_by' => (string) $admin->id,
        ]);

        if (! empty($input['permission_ids']) && is_array($input['permission_ids'])) {
            $role->permissions()->sync($input['permission_ids']);
        }

        $this->activity()->record('Role created', 'role', 'created', 'admin', $admin, $role, [
            'permission_ids' => $input['permission_ids'] ?? [],
        ]);

        return [
            'in_error' => false,
            'message' => 'Role created.',
            'status_code' => ApiCode::CREATED,
            'reason' => ApiReason::CREATED,
            'data' => $this->present($role),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function update(Admin $admin, Role $role, array $input): array
    {
        $role->update([
            'name' => $input['name'] ?? $role->name,
            'description' => $input['description'] ?? $role->description,
            'updated_by' => (string) $admin->id,
        ]);

        if (array_key_exists('permission_ids', $input) && is_array($input['permission_ids'])) {
            $role->permissions()->sync($input['permission_ids']);
            $this->activity()->record('Role permissions updated', 'permission', 'updated', 'admin', $admin, $role, [
                'permission_ids' => $input['permission_ids'],
            ]);
        }

        $this->activity()->record('Role updated', 'role', 'updated', 'admin', $admin, $role);

        return [
            'in_error' => false,
            'message' => 'Role updated.',
            'status_code' => ApiCode::SUCCESS,
            'reason' => ApiReason::SUCCESS,
            'data' => $this->present($role->fresh('permissions')),
        ];
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function assignToAdmin(Admin $actor, Admin $target, int $roleId): array
    {
        $role = $this->roles->find($roleId);

        if (! $role) {
            return [
                'in_error' => true,
                'message' => 'Role not found.',
                'status_code' => ApiCode::NOT_FOUND,
                'reason' => ApiReason::NOT_FOUND,
                'data' => [],
            ];
        }

        $target->update(['role_id' => $role->id]);

        $this->activity()->record('Role assigned', 'role', 'assigned', 'admin', $actor, $target, [
            'role_id' => $role->id,
        ]);

        return [
            'in_error' => false,
            'message' => 'Role assigned.',
            'status_code' => ApiCode::SUCCESS,
            'reason' => ApiReason::SUCCESS,
            'data' => array_merge($this->present($role), [
                'admin_id' => $target->id,
            ]),
        ];
    }
}
