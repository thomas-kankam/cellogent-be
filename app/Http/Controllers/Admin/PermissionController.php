<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->paginateQuery($request, Permission::query()->orderBy('module')->orderBy('name'));

        return $this->paginatedApiResponse(
            'Permissions loaded.',
            ApiReason::SUCCESS,
            $paginator,
            fn (Permission $permission) => [
                'id' => $permission->id,
                'module' => $permission->module,
                'name' => $permission->name,
            ],
        );
    }
}
