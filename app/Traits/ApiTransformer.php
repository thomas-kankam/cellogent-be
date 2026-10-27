<?php

namespace App\Traits;

use App\Support\ApiCode;
use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin wrapper so controllers/form-requests can call $this->apiResponse(...).
 * The actual JSON shape lives in App\Support\ApiResponse so it cannot drift.
 */
trait ApiTransformer
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function apiResponse(
        bool $inError,
        string $message,
        int $statusCode,
        string $reason,
        array $data = [],
    ): JsonResponse {
        return ApiResponse::make($inError, $message, $statusCode, $reason, $data);
    }

    /**
     * Read page/per_page from the query string. Caps per_page at 100.
     */
    protected function paginateQuery(Request $request, Builder|Relation $query, int $defaultPerPage = 15): LengthAwarePaginator
    {
        $perPage = max(1, min((int) $request->input('per_page', $defaultPerPage), 100));
        $page = max(1, (int) $request->input('page', 1));

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  callable(mixed): array<string, mixed>  $transform
     */
    protected function paginatedApiResponse(
        string $message,
        string $reason,
        LengthAwarePaginator $paginator,
        callable $transform,
        int $statusCode = ApiCode::SUCCESS,
    ): JsonResponse {
        return ApiResponse::paginated($message, $reason, $paginator, $transform, $statusCode);
    }
}
