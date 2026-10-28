<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Single JSON envelope for every API endpoint.
 *
 * data is the payload itself (object or list) — no extra wrappers like
 * data.account, data.token, data.items, or data.data.
 *
 * Lists add pagination as a sibling of data:
 * { data: [ ... ], pagination: { current_page, per_page, total, last_page } }
 */
final class ApiResponse
{
    /**
     * @param  array<int|string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function make(
        bool $inError,
        string $message,
        int $statusCode,
        string $reason,
        array $data = [],
        array $meta = [],
    ): JsonResponse {
        $payload = [
            'in_error' => $inError,
            'status_code' => (string) $statusCode,
            'message' => $message,
            'reason' => $reason,
            'data' => $data,
        ];

        if (isset($meta['pagination'])) {
            $payload['pagination'] = $meta['pagination'];
        }

        $payload['point_in_time'] = now()->toDateTimeString();

        return response()->json($payload, $statusCode);
    }

    /**
     * @param  callable(mixed): array<string, mixed>  $transform
     */
    public static function paginated(
        string $message,
        string $reason,
        LengthAwarePaginator $paginator,
        callable $transform,
        int $statusCode = ApiCode::SUCCESS,
    ): JsonResponse {
        return self::make(
            false,
            $message,
            $statusCode,
            $reason,
            collect($paginator->items())->map($transform)->values()->all(),
            [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        );
    }
}
