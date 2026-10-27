<?php

namespace App\Http\Middleware;

use App\Support\ApiCode;
use App\Support\ApiReason;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ($user->status ?? 'active') !== 'active') {
            return ApiResponse::make(
                true,
                'This account is not active.',
                ApiCode::FORBIDDEN,
                ApiReason::ACCOUNT_INACTIVE,
            );
        }

        return $next($request);
    }
}
