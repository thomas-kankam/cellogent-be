<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Models\Admin;
use App\Models\Permission;
use App\Models\Role;
use App\Repositories\RoleRepository;
use App\Support\ApiCode;
use App\Support\ApiReason;

class RoleService
{
    use LogsActivity;

    public function __construct(protected RoleRepository $roles) {}

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
            'permissions' => Permission::groupByModule($role->permissions),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function create(Admin $admin, array $input): array
    {
        if (! $admin->holdsAllPermissionIds($input['permission_ids'] ?? [])) {
            return $this->forbidden('You cannot give a role permissions you do not have.');
        }

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
        if ((int) $role->id === (int) $admin->role_id) {
            return $this->forbidden('You cannot edit your own role.');
        }

        if (! $admin->canGrantRole($role)) {
            return $this->forbidden('You cannot edit a role with permissions you do not have.');
        }

        if (! $admin->holdsAllPermissionIds($input['permission_ids'] ?? [])) {
            return $this->forbidden('You cannot give a role permissions you do not have.');
        }

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

        if ((int) $actor->id === (int) $target->id) {
            return $this->forbidden('You cannot change your own role.');
        }

        if (! $actor->canManageAdmin($target) || ! $actor->canGrantRole($role)) {
            return $this->forbidden('You cannot assign a role with permissions you do not have.');
        }

        $previous = $target->role_id;
        $target->update(['role_id' => $role->id]);

        if ((int) $previous !== (int) $role->id) {
            $target->tokens()->get()->each->revoke();
        }

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

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    protected function forbidden(string $message): array
    {
        return [
            'in_error' => true,
            'message' => $message,
            'status_code' => ApiCode::FORBIDDEN,
            'reason' => ApiReason::FORBIDDEN,
            'data' => [],
        ];
    }
}
