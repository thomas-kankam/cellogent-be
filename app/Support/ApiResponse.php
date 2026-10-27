<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Single JSON envelope for every API endpoint.
 *
 * Shape:
 * {
 *   "in_error": false,
 *   "status_code": "200",
 *   "message": "OTP verified",
 *   "reason": "verified",
 *   "data": { ... },
 *   "point_in_time": "2026-10-26 16:00:00"
 * }
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(
        bool $inError,
        string $message,
        int $statusCode,
        string $reason,
        array $data = [],
    ): JsonResponse {
        return response()->json([
            'in_error' => $inError,
            'status_code' => (string) $statusCode,
            'message' => $message,
            'reason' => $reason,
            'data' => $data,
            'point_in_time' => now()->toDateTimeString(),
        ], $statusCode);
    }

    /**
     * Wrap a Laravel paginator. `$transform` receives one model/row and returns an array.
     *
     * @param  callable(mixed): array<string, mixed>  $transform
     */
    public static function paginated(
        string $message,
        string $reason,
        LengthAwarePaginator $paginator,
        callable $transform,
        int $statusCode = ApiCode::SUCCESS,
    ): JsonResponse {
        return self::make(false, $message, $statusCode, $reason, [
            'items' => collect($paginator->items())->map($transform)->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
