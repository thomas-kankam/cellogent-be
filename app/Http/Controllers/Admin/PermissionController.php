<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Support\ApiCode;
use App\Support\ApiReason;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    /**
     * Full catalogue grouped by module (for role assignment UIs).
     */
    public function index(): JsonResponse
    {
        $grouped = Permission::groupByModule(
            Permission::query()->orderBy('module')->orderBy('name')->get()
        );

        return $this->apiResponse(false, 'Permissions loaded.', ApiCode::SUCCESS, ApiReason::SUCCESS, $grouped);
    }
}
