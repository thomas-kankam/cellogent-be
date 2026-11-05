<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate admin routes by role permissions.
 * Usage: middleware('permission:payment.view') or permission:payment.view,payment.manage (any of).
 */
class EnsureAdminPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $admin = $request->user();

        if (! $admin instanceof Admin) {
            return ApiResponse::make(true, 'Unauthenticated.', ApiCode::UNAUTHENTICATED, ApiReason::UNAUTHENTICATED);
        }

        $admin->loadMissing('role.permissions');

        if ($permissions !== [] && ! $admin->hasAnyPermission($permissions)) {
            return ApiResponse::make(
                true,
                'You do not have permission to perform this action.',
                ApiCode::FORBIDDEN,
                ApiReason::FORBIDDEN,
            );
        }

        return $next($request);
    }
}
