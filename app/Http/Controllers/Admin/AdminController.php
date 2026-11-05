<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignAdminRoleRequest;
use App\Http\Requests\Admin\UpdateAdminRequest;
use App\Http\Requests\Admin\UpdateAdminStatusRequest;
use App\Models\Admin;
use App\Services\AdminService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function __construct(protected AdminService $admins) {}

    public function index(Request $request): JsonResponse
    {
        $query = Admin::query()->with('role.permissions')->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('role_id')) {
            $query->where('role_id', $request->integer('role_id'));
        }
        if ($request->filled('search')) {
            $search = '%'.trim((string) $request->string('search')).'%';
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', $search)
                    ->orWhere('last_name', 'like', $search)
                    ->orWhere('email', 'like', $search)
                    ->orWhere('phone_number', 'like', $search);
            });
        }

        $paginator = $this->paginateQuery($request, $query);

        return $this->paginatedApiResponse(
            'Admins loaded.',
            ApiReason::SUCCESS,
            $paginator,
            fn (Admin $admin) => $this->admins->present($admin),
        );
    }

    public function show(Admin $admin): JsonResponse
    {
        return $this->apiResponse(false, 'Admin loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->admins->present($admin));
    }

    public function update(UpdateAdminRequest $request, Admin $admin): JsonResponse
    {
        return $this->respondFrom($this->admins->update(
            $request->user(),
            $admin,
            $request->safe()->except('profile_image'),
            $request->file('profile_image'),
        ));
    }

    public function updateStatus(UpdateAdminStatusRequest $request, Admin $admin): JsonResponse
    {
        return $this->respondFrom($this->admins->updateStatus(
            $request->user(),
            $admin,
            (string) $request->validated('status'),
        ));
    }

    public function assignRole(AssignAdminRoleRequest $request, Admin $admin): JsonResponse
    {
        return $this->respondFrom($this->admins->assignRole(
            $request->user(),
            $admin,
            $request->integer('role_id'),
        ));
    }

    public function destroy(Request $request, Admin $admin): JsonResponse
    {
        return $this->respondFrom($this->admins->delete($request->user(), $admin));
    }
}
