<?php

namespace App\Services;

use App\Concerns\LogsActivity;
use App\Concerns\ReturnsServiceResult;
use App\Jobs\ProcessProfileImageJob;
use App\Models\Admin;
use App\Models\Role;
use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\GhanaPhone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class AdminService
{
    use LogsActivity;
    use ReturnsServiceResult;

    public const STATUSES = ['active', 'inactive'];

    public function __construct(protected ProfilePresenter $profiles) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Admin $admin): array
    {
        return $this->profiles->present($admin->loadMissing('role.permissions'));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function update(Admin $actor, Admin $admin, array $input, ?UploadedFile $image = null): array
    {
        if (isset($input['email'])) {
            $email = strtolower(trim((string) $input['email']));
            $taken = Admin::query()->where('email', $email)->whereKeyNot($admin->id)->exists();

            if ($taken) {
                return $this->result(true, 'This email is already registered.', ApiCode::BAD_REQUEST, ApiReason::EMAIL_TAKEN);
            }

            $input['email'] = $email;
        }

        if (array_key_exists('phone_number', $input)) {
            $input['phone_number'] = GhanaPhone::normalize($input['phone_number']);
        }

        if (isset($input['role_id']) && ! Role::query()->whereKey($input['role_id'])->exists()) {
            return $this->result(true, 'Role not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $before = $admin->only(['first_name', 'last_name', 'email', 'phone_number', 'role_id']);

        $admin->fill(collect($input)->only(['first_name', 'last_name', 'email', 'phone_number', 'role_id'])->all())->save();

        if ($image) {
            $admin->storeProfileImage($image, 'admin');
            ProcessProfileImageJob::dispatch('admin', (int) $admin->id, (string) $admin->profile_image);
        }

        $this->activity()->record('Admin updated', 'admin', 'updated', 'admin', $actor, $admin, [
            'before' => $before,
            'after' => $admin->only(['first_name', 'last_name', 'email', 'phone_number', 'role_id']),
        ]);

        return $this->result(false, 'Admin updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($admin->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function updateStatus(Admin $actor, Admin $admin, string $status): array
    {
        if (! in_array($status, self::STATUSES, true)) {
            return $this->result(true, 'Status must be active or inactive.', ApiCode::VALIDATION, ApiReason::VALIDATION_ERROR, [
                'status' => ['Status must be active or inactive.'],
            ]);
        }

        if ((int) $actor->id === (int) $admin->id) {
            return $this->result(true, 'You cannot change your own account status.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        if ($admin->status === $status) {
            return $this->result(false, 'Admin status unchanged.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($admin));
        }

        $admin->update(['status' => $status]);

        if ($status !== 'active') {
            $this->revokeTokens($admin);
        }

        $this->activity()->record('Admin status updated', 'account_status', 'updated', 'admin', $actor, $admin, [
            'status' => $status,
        ]);

        return $this->result(false, 'Admin status updated.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($admin->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function assignRole(Admin $actor, Admin $admin, int $roleId): array
    {
        $role = Role::query()->find($roleId);

        if (! $role) {
            return $this->result(true, 'Role not found.', ApiCode::NOT_FOUND, ApiReason::NOT_FOUND);
        }

        $previous = $admin->role_id;
        $admin->update(['role_id' => $role->id]);

        $this->activity()->record('Role assigned', 'role', 'assigned', 'admin', $actor, $admin, [
            'role_id' => $role->id,
            'previous_role_id' => $previous,
        ]);

        return $this->result(false, 'Role assigned.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->present($admin->fresh()));
    }

    /**
     * @return array{in_error: bool, message: string, status_code: int, reason: string, data: array<string, mixed>}
     */
    public function delete(Admin $actor, Admin $admin): array
    {
        if ((int) $actor->id === (int) $admin->id) {
            return $this->result(true, 'You cannot delete your own account.', ApiCode::BAD_REQUEST, ApiReason::INVALID);
        }

        DB::transaction(function () use ($admin) {
            $this->revokeTokens($admin);
            $admin->delete();
        });

        $this->activity()->record('Admin deleted', 'admin', 'deleted', 'admin', $actor, null, [
            'admin_id' => $admin->id,
            'email' => $admin->email,
        ]);

        return $this->result(false, 'Admin deleted.', ApiCode::SUCCESS, ApiReason::SUCCESS);
    }

    protected function revokeTokens(Admin $admin): void
    {
        $admin->tokens()->get()->each->revoke();
    }
}
