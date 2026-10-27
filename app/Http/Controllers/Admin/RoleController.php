<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignRoleRequest;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Models\Admin;
use App\Models\Role;
use App\Repositories\RoleRepository;
use App\Services\RoleService;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function __construct(
        protected RoleRepository $roles,
        protected RoleService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->paginateQuery($request, Role::query()->with('permissions')->latest('id'));

        return $this->paginatedApiResponse(
            'Roles loaded.',
            ApiReason::SUCCESS,
            $paginator,
            fn (Role $role) => $this->service->present($role),
        );
    }

    public function show(Role $role): JsonResponse
    {
        return $this->apiResponse(false, 'Role loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $this->service->present($role));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        return $this->respondFrom($this->service->create($request->user(), $request->validated()));
    }

    public function update(StoreRoleRequest $request, Role $role): JsonResponse
    {
        return $this->respondFrom($this->service->update($request->user(), $role, $request->validated()));
    }

    public function assign(AssignRoleRequest $request): JsonResponse
    {
        $target = Admin::query()->findOrFail($request->integer('admin_id'));

        return $this->respondFrom($this->service->assignToAdmin(
            $request->user(),
            $target,
            $request->integer('role_id'),
        ));
    }
}
